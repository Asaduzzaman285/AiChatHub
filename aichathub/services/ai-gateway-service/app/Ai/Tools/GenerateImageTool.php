<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ChargesForGeneratedFile;
use App\Services\GeneratedAttachmentTracker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;
use Symfony\Component\Process\Process;

/**
 * Custom function-calling tool, not a provider-native one like WebSearch —
 * TextChatAgent::tools() hands this to the model alongside the normal conversation,
 * and the model itself decides when to call it based on the user's own words (e.g.
 * "generate an image of a cat"). This is the same mechanism ChatGPT/Gemini use for
 * automatic image-generation intent — no separate model switch, no keyword matching
 * on our side; the model's own language understanding is the detector.
 */
class GenerateImageTool implements Tool
{
    use ChargesForGeneratedFile;

    // Switched from OpenAI's gpt-image-2 to Gemini's gemini-2.5-flash-image
    // ("Nano Banana") — a deliberate choice, not a fallback: it's both cheaper
    // (~$0.039/image vs ~$0.06) and specifically well-suited to identity-preserving
    // photo edits (keeping a real person's actual face/features intact while
    // changing background/attire/style), which is exactly the use case a real user
    // test on gpt-image-2 struggled with.
    private const PRICING_MODEL_ID = 'gemini-2.5-flash-image';

    // Calls Gemini's REST API directly via a `curl` subprocess rather than
    // laravel/ai's own Ai::imageProvider('gemini')->image(...). Confirmed live,
    // methodically: the exact same request body, sent as a raw `curl` process from
    // inside this container, reaches Google cleanly every time (a real 400 for a
    // deliberately-invalid test image, never a transport error) — but laravel/ai's
    // internal Guzzle client failed with "411 Length Required" the moment an
    // attachment made the request body large. Same root cause family as
    // ChatServiceClient::createAttachmentFromBytes()'s earlier "unable to rewind"
    // bug: this service runs under Octane/Swoole with SWOOLE_HOOK_ALL, and its
    // coroutine hooking of PHP's own socket/cURL functions keeps surfacing as
    // different-looking failures for anything but small request bodies. Unlike
    // that earlier fix, laravel/ai's Gemini gateway is vendor code — patching it
    // isn't durable across a composer update — so this tool talks to Gemini's API
    // directly instead of going through the package's image-generation path at all.
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';

    /**
     * @param  array<int, array{base64: string, mime_type: string}>  $imageAttachments
     *         Images attached to the CURRENT message only (not persisted across
     *         turns — see ChatController::stream()'s own comment on why document
     *         context is re-resolved from the session but image context isn't: an
     *         attached photo is what makes this an *edit* rather than a fresh
     *         generation, and re-sending image bytes on every future turn is a much
     *         bigger cost than the text case document persistence already solves).
     */
    public function __construct(
        private string $userId,
        private GeneratedAttachmentTracker $tracker,
        private array $imageAttachments = [],
    ) {}

    public function description(): Stringable|string
    {
        return 'Generates a new image from a text description, OR edits/transforms an image the '
            .'user has attached to this message (e.g. turning a casual photo into a professional '
            .'portrait, changing the background, restyling it). Call this whenever the user asks to '
            .'create, draw, generate, make, edit, transform, or modify an image — do not attempt to '
            .'describe the result in words instead. If the user attached an image and is asking for '
            .'a change to it, that attached image is automatically used as the basis for the edit.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('A detailed description of the image to generate, or of the edit to apply to the attached image.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $prompt = (string) $request['prompt'];

        return $this->chargeAndStore(
            userId: $this->userId,
            pricingModelId: self::PRICING_MODEL_ID,
            filename: Str::random(20).'.png',
            mimeType: 'image/png',
            generate: fn () => $this->callGemini($prompt),
            tracker: $this->tracker,
            unavailableMessage: 'Image generation is temporarily unavailable. Please try again later.',
            generationFailedMessage: 'Image generation failed. Please try rephrasing your request.',
            successMessage: 'Image generated successfully and shown to the user.',
        );
    }

    private function callGemini(string $prompt): string
    {
        $parts = [['text' => $prompt]];
        foreach ($this->imageAttachments as $img) {
            $parts[] = ['inlineData' => ['mimeType' => $img['mime_type'], 'data' => $img['base64']]];
        }

        // imageSize 2K / aspectRatio 1:1 — the same pinned "medium"/"1:1" pairing
        // used before the direct-API switch (GeminiProvider::defaultImageOptions()
        // maps them identically); still pinned explicitly rather than left to a
        // default, since flat_per_image billing needs one predictable cost and
        // Gemini's real per-image cost varies by resolution tier.
        $body = json_encode([
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig' => ['imageSize' => '2K', 'aspectRatio' => '1:1'],
            ],
        ]);

        $tmpBodyPath = tempnam(sys_get_temp_dir(), 'gembody_');
        file_put_contents($tmpBodyPath, $body);

        try {
            $apiKey = (string) config('ai.providers.gemini.key');
            $url = sprintf(self::GEMINI_ENDPOINT, self::PRICING_MODEL_ID, $apiKey);

            $process = new Process([
                'curl', '-sS', '--max-time', '90',
                '-H', 'Content-Type: application/json',
                '--data-binary', "@{$tmpBodyPath}",
                $url,
            ]);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('curl failed: '.$process->getErrorOutput());
            }

            $data = json_decode($process->getOutput(), true);

            if (isset($data['error'])) {
                throw new RuntimeException('Gemini API error: '.($data['error']['message'] ?? json_encode($data['error'])));
            }

            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['inlineData']['data'])) {
                    return base64_decode($part['inlineData']['data']);
                }
            }

            throw new RuntimeException('Gemini response had no image data: '.substr((string) $process->getOutput(), 0, 500));
        } finally {
            @unlink($tmpBodyPath);
        }
    }
}
