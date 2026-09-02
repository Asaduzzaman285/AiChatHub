<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\CostTrackingMiddleware;
use App\Ai\Middleware\UsageLoggingMiddleware;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

// No #[Temperature(...)] here on purpose — confirmed live that Anthropic's newest
// Claude 5-generation models (claude-sonnet-5, likely claude-opus-5 too) reject the
// request outright with 400 "`temperature` is deprecated for this model" the instant
// this attribute is present, regardless of value. Every provider we use is fine with
// temperature simply being omitted (falls back to that provider's own default), so
// dropping it here is the safe fix for all 8 providers, not a per-model workaround.
#[MaxTokens(4096)]
class TextChatAgent implements Agent, Conversational, HasMiddleware, HasTools, HasProviderOptions
{
    use Promptable;

    public function __construct(
        private string $userId,
        private string $sessionId,
        private array  $history = [],
        private ?string $systemPrompt = null,
        // Both default false and both re-derived server-side by the caller from the
        // model's real capabilities before construction — see ChatController::stream().
        // Never trust these as passed straight from client input; some providers throw
        // (not silently ignore) an unsupported tool, and the Deep Think provider-option
        // is only a verified-safe payload for Anthropic.
        private bool $webSearchEnabled = false,
        private bool $deepThinkEnabled = false,
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt ?? 'You are a helpful, accurate, and concise AI assistant.';
    }

    public function messages(): iterable
    {
        return array_map(
            fn ($msg) => new Message($msg['role'], $msg['content']),
            $this->history
        );
    }

    public function middleware(): array
    {
        return [
            new CostTrackingMiddleware($this->userId),
            new UsageLoggingMiddleware($this->userId, $this->sessionId),
        ];
    }

    public function tools(): iterable
    {
        return $this->webSearchEnabled ? [new WebSearch()] : [];
    }

    // laravel/ai v0.10.2 has no first-class "reasoning" contract (no SupportsReasoning
    // interface, no Reasoning attribute) — confirmed by reading every provider's
    // BuildsTextRequests trait: providerOptions() is merged verbatim, untranslated,
    // into the outgoing request body (or generationConfig for Gemini). So unlike
    // WebSearch (a real package-level tool), "Deep Think" here means WE know each of
    // these providers' own real API parameter for extended reasoning and pass it
    // through this generic escape hatch ourselves — the package doesn't validate any
    // of these shapes for us. Anthropic's `thinking` key, OpenAI's `reasoning.effort`
    // (Responses API), Gemini's `thinkingConfig.thinkingBudget`, xAI's
    // `reasoning_effort`, and OpenRouter's unified `reasoning.effort` are each that
    // provider's own documented parameter, not a laravel/ai feature.
    private const DEEP_THINK_PROVIDERS = [
        Lab::Anthropic->value,
        Lab::OpenAI->value,
        Lab::Gemini->value,
        Lab::xAI->value,
        Lab::OpenRouter->value,
    ];

    // Shared with ChatController::stream()'s server-side re-derivation, so the two
    // places that decide "can this provider actually do something with Deep Think"
    // can't drift apart — a model flagged capabilities.reasoning=true for a provider
    // not in this list (DeepSeek's reasoner is model-baked with no request-side
    // toggle; Moonshot/Kimi has no first-class laravel/ai driver at all) still
    // shouldn't get the toggle honored.
    public static function supportsDeepThink(string $provider): bool
    {
        return in_array($provider, self::DEEP_THINK_PROVIDERS, true);
    }

    public function providerOptions(Lab|string $provider): array
    {
        $lab = $provider instanceof Lab ? $provider->value : $provider;

        if (! $this->deepThinkEnabled || ! self::supportsDeepThink($lab)) {
            return [];
        }

        // budget_tokens/thinkingBudget are reasonable starting values, not
        // independently tuned yet; 'high' mirrors that same "err generous" intent for
        // the providers that use an effort enum instead of a token budget.
        return match ($lab) {
            Lab::Anthropic->value  => ['thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]],
            Lab::OpenAI->value     => ['reasoning' => ['effort' => 'high']],
            Lab::Gemini->value     => ['thinkingConfig' => ['thinkingBudget' => 10000]],
            Lab::xAI->value        => ['reasoning_effort' => 'high'],
            Lab::OpenRouter->value => ['reasoning' => ['effort' => 'high']],
            default => [],
        };
    }
}
