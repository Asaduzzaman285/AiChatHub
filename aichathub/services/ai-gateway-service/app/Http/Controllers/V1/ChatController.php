<?php

namespace App\Http\Controllers\V1;

use App\Ai\Agents\TextChatAgent;
use App\Ai\Agents\TitleGeneratorAgent;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseWalletReservationJob;
use App\Models\AiModel;
use App\Services\ChatServiceClient;
use App\Services\PendingReservationTracker;
use App\Services\SubscriptionClientService;
use App\Services\WalletClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

class ChatController extends Controller
{
    public function __construct(
        private SubscriptionClientService $subscriptionClient,
        private WalletClientService $walletClient,
        private ChatServiceClient $chatClient
    ) {}

    /**
     * POST /api/v1/chat/stream
     * Streams AI response back to client using SSE.
     */
    public function stream(Request $request)
    {
        $data = $request->validate([
            'message'         => 'required|string|max:10000',
            'model_id'        => 'required|string',
            'session_id'      => 'nullable|uuid',
            'history'         => 'nullable|array',
            'history.*.role'    => 'required_with:history|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
            'attachment_ids'   => 'nullable|array|max:4',
            'attachment_ids.*' => 'uuid',
            'web_search'       => 'nullable|boolean',
            'deep_think'       => 'nullable|boolean',
        ]);

        $userId    = $this->authUserId($request);
        $modelId   = $data['model_id'];
        // Only persist to chat-service when the caller passed a real session
        // (created via POST /chat/sessions first) — a made-up uuid here would
        // reference a chat_sessions row that doesn't exist.
        $persistToChatService = isset($data['session_id']);
        $sessionId = $data['session_id'] ?? \Str::uuid()->toString();

        // 1. Verify model access via Subscription Service
        $access = $this->subscriptionClient->canAccess($userId, $modelId);
        if (! $access['allowed']) {
            return response()->json([
                'error'  => 'Model not available in your subscription.',
                'reason' => $access['reason'],
            ], 403);
        }

        // 2. Resolve model_id (e.g. "gemini-2.5-flash") to its provider — the
        // request only names the model, laravel/ai needs both.
        $model = AiModel::where('model_id', $modelId)->where('is_active', true)->where('type', 'text')->first();
        if (! $model) {
            return response()->json(['error' => 'Unknown or unsupported model.'], 422);
        }

        // 2b. Resolve any attachments. Images become base64 for vision input — not a
        // URL, since MinIO isn't reachable from a real provider's servers in local dev
        // (see ChatServiceClient). Documents resolve to extracted plain text instead,
        // which works with any model regardless of vision support.
        $images = [];
        $documentContext = '';
        if (! empty($data['attachment_ids'])) {
            $attachments = $this->chatClient->resolveAttachments($data['attachment_ids']);
            if (count($attachments) !== count($data['attachment_ids'])) {
                return response()->json(['error' => 'One or more attachments could not be found.'], 422);
            }

            $imageAttachments = array_filter($attachments, fn (array $a) => str_starts_with($a['mime_type'], 'image/'));
            if ($imageAttachments && ! ($model->capabilities['vision'] ?? false)) {
                return response()->json(['error' => "{$model->name} doesn't support image input. Pick a vision-capable model."], 422);
            }

            $images = array_map(
                fn (array $a) => Image::fromBase64($a['base64'], $a['mime_type']),
                $imageAttachments
            );

            foreach ($attachments as $a) {
                if (! empty($a['extracted_text'])) {
                    $documentContext .= "\n\n--- Attached file: {$a['original_name']} ---\n{$a['extracted_text']}\n--- End of {$a['original_name']} ---\n";
                }
            }
        }

        // Re-derived from the model's real capabilities, never trusted straight from the
        // client — some providers throw (not silently ignore) an unsupported WebSearch
        // tool, and the Deep Think provider-option is only verified safe for Anthropic.
        // See TextChatAgent's own constructor comment / this session's plan file.
        $webSearchEnabled = (bool) ($data['web_search'] ?? false) && (bool) ($model->capabilities['web_search'] ?? false);
        $deepThinkEnabled = (bool) ($data['deep_think'] ?? false)
            && $model->provider === 'anthropic'
            && (bool) ($model->capabilities['reasoning'] ?? false);

        $agent = new TextChatAgent(
            userId:            $userId,
            sessionId:         $sessionId,
            history:           $data['history'] ?? [],
            webSearchEnabled:  $webSearchEnabled,
            deepThinkEnabled:  $deepThinkEnabled,
        );

        if ($persistToChatService) {
            $this->chatClient->appendMessage($sessionId, $userId, 'user', $data['message'], ['model_id' => $model->id], $data['attachment_ids'] ?? []);
        }

        // Document text rides along with this one request only — the persisted message
        // above and the `history` array on future turns stay just the user's own words,
        // so a big attachment doesn't get re-sent on every subsequent turn.
        $promptMessage = $documentContext === ''
            ? $data['message']
            : "{$documentContext}\nUser's message:\n{$data['message']}";

        try {
            // 3. Stream response — CostTrackingMiddleware fires reserve before, deduct after
            $response = $agent->stream($promptMessage, $images, provider: $model->provider, model: $model->model_id);

            if ($persistToChatService) {
                $response->then(function (AgentResponse $response) use ($sessionId, $userId, $model) {
                    $promptTokens     = $response->usage?->promptTokens ?? 0;
                    $completionTokens = $response->usage?->completionTokens ?? 0;

                    $this->chatClient->appendMessage($sessionId, $userId, 'assistant', $response->text ?? '', [
                        'model_id'          => $model->id,
                        'prompt_tokens'     => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'cost'              => $this->calculateCost($model, $promptTokens, $completionTokens),
                        'is_streaming'      => true,
                    ]);
                });
            }

            // Frontend uses Vercel AI SDK — but don't just return
            // $response->usingVercelDataProtocol() directly. laravel/ai builds that
            // response via response()->stream() with a bare `yield`-based closure that
            // has no declared return type. Laravel's ResponseFactory::stream() only
            // wraps generator closures in an echo+flush loop when NOT running under
            // Octane; under Octane it hands the raw closure straight to
            // StreamedResponse::setCallback(). Symfony's StreamedResponse::sendContent()
            // just invokes that callback — for a generator function, invoking it merely
            // returns a Generator object without executing its body, since nothing
            // iterates the return value. Octane's Swoole client only captures output via
            // a genuine ob_start() handler wrapped around sendContent() (see
            // vendor/laravel/octane SwooleClient::sendResponseContent()) — it never
            // iterates a raw Generator return value either. Net effect: zero bytes sent,
            // confirmed live (200 OK, Content-Length: 0, no error) once this service
            // moved to Octane. Re-wrapping it here in a closure with a real echo+flush
            // loop fixes it under both runtimes without touching vendor code: this
            // closure itself contains no `yield`, so Laravel's generator-detection
            // doesn't touch it, and Octane's ob_start() capture sees real echoed bytes.
            $vercelResponse = $response->usingVercelDataProtocol()->toResponse($request);
            $streamCallback = $vercelResponse->getCallback();

            return response()->stream(function () use ($streamCallback) {
                try {
                    foreach ($streamCallback() as $chunk) {
                        echo $chunk;
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                    }
                } catch (\Throwable $e) {
                    // A provider call failing (bad key, rate limit, outage) here happens
                    // deep inside this stream loop, past the point where the controller's
                    // own try/catch below can see it — same problem the old
                    // register_shutdown_function()/terminating() safety net was built for.
                    // Confirmed live under Octane: terminating() does NOT reliably fire for
                    // an exception thrown at this exact point either — the reservation was
                    // left stuck in reserved_balance with no release job dispatched. This
                    // catch, right at the only point actually guaranteed to run, is what's
                    // reliable — a lifecycle hook downstream of this isn't.
                    Log::error('AI provider stream failed mid-response', ['error' => $e->getMessage()]);

                    $pending = app(PendingReservationTracker::class)->pending();
                    if ($pending) {
                        ReleaseWalletReservationJob::dispatch($pending['user_id'], $pending['amount']);
                    }

                    echo 'data: '.json_encode([
                        'type'      => 'error',
                        'errorText' => 'The AI provider request failed. Please try again.',
                    ])."\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            }, $vercelResponse->getStatusCode(), [
                'Cache-Control'                  => 'no-cache, no-transform',
                'Content-Type'                   => 'text/event-stream',
                'x-vercel-ai-ui-message-stream'  => 'v1',
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 402) {
                return response()->json(['error' => 'Insufficient wallet balance. Please top up.'], 402);
            }
            if ($e->getCode() === 503) {
                return response()->json(['error' => $e->getMessage()], 503);
            }
            // Refund is handled inside CostTrackingMiddleware on exception
            return response()->json(['error' => 'AI request failed. Please try again.'], 503);
        }
    }

    /**
     * POST /api/v1/chat/compare
     * Fan-out to multiple models simultaneously (Standard/Pro).
     */
    public function compare(Request $request)
    {
        $data = $request->validate([
            'message'            => 'required|string|max:10000',
            'model_ids'          => 'required|array|min:2|max:4',
            'model_ids.*'        => 'required|string',
            'attachment_ids'     => 'nullable|array|max:4',
            'attachment_ids.*'   => 'uuid',
            'session_id'         => 'nullable|uuid',
            'history'            => 'nullable|array',
            'history.*.role'     => 'required_with:history|in:user,assistant',
            'history.*.content'  => 'required_with:history|string',
        ]);

        $userId    = $this->authUserId($request);
        $sessionId = $data['session_id'] ?? null;

        // Verify access for all models
        foreach ($data['model_ids'] as $modelId) {
            $access = $this->subscriptionClient->canAccess($userId, $modelId);
            if (! $access['allowed']) {
                return response()->json([
                    'error'    => "Model {$modelId} not available in your subscription.",
                    'model_id' => $modelId,
                ], 403);
            }
        }

        $models = AiModel::whereIn('model_id', $data['model_ids'])->where('is_active', true)->get()->keyBy('model_id');

        // Resolved once up front (not per-model) — same image/document set goes to
        // every model in the fan-out, so there's no reason to re-fetch it 2-4 times.
        // Mirrors /chat/stream's own resolution logic (see its own comments for why
        // images go as base64 and documents as extracted text).
        $imageAttachments = [];
        $documentContext = '';
        if (! empty($data['attachment_ids'])) {
            $attachments = $this->chatClient->resolveAttachments($data['attachment_ids']);
            if (count($attachments) !== count($data['attachment_ids'])) {
                return response()->json(['error' => 'One or more attachments could not be found.'], 422);
            }

            $imageAttachments = array_filter($attachments, fn (array $a) => str_starts_with($a['mime_type'], 'image/'));

            foreach ($attachments as $a) {
                if (! empty($a['extracted_text'])) {
                    $documentContext .= "\n\n--- Attached file: {$a['original_name']} ---\n{$a['extracted_text']}\n--- End of {$a['original_name']} ---\n";
                }
            }
        }

        $promptMessage = $documentContext === ''
            ? $data['message']
            : "{$documentContext}\nUser's message:\n{$data['message']}";

        // Only persist when the caller passed a real session (created via
        // POST /chat/sessions first) — same reasoning as /chat/stream. One user
        // message covers the whole turn; each model's reply is tagged with the same
        // compare_group_id so the frontend can render them back together as one group
        // instead of a run of separate assistant replies.
        $persistToChatService = $sessionId !== null;
        $compareGroupId       = (string) \Str::uuid();
        $history               = $data['history'] ?? [];

        if ($persistToChatService) {
            $this->chatClient->appendMessage($sessionId, $userId, 'user', $data['message'], [], $data['attachment_ids'] ?? []);
        }

        // Fan-out — return streaming results per model
        // Each agent handles its own reserve/deduct independently
        return response()->stream(function () use (
            $data, $userId, $models, $imageAttachments, $promptMessage,
            $sessionId, $persistToChatService, $compareGroupId, $history
        ) {
            foreach ($data['model_ids'] as $modelId) {
                $model = $models->get($modelId);
                if (! $model) {
                    echo "data: " . json_encode(['model' => $modelId, 'error' => 'Unknown model']) . "\n\n";
                    flush();
                    continue;
                }

                // Documents ride along regardless (plain text works with any model);
                // images only go to models that actually support vision — the other
                // columns get a clear per-model error instead of silently ignoring
                // the attachment (confirmed live: every model previously just said
                // "I don't see an image" because nothing was ever sent at all).
                if ($imageAttachments && ! ($model->capabilities['vision'] ?? false)) {
                    echo "data: " . json_encode(['model' => $modelId, 'error' => "{$model->name} doesn't support image input."]) . "\n\n";
                    flush();
                    continue;
                }

                $images = array_map(
                    fn (array $a) => Image::fromBase64($a['base64'], $a['mime_type']),
                    $imageAttachments
                );

                // Real session_id reused across every model in the fan-out (not a fresh
                // uuid per model like before) — CostTrackingMiddleware/UsageLoggingMiddleware
                // only use it for logging context, so sharing it here is safe, and it's what
                // lets prior turns from this same conversation feed in as history below.
                $agent = new TextChatAgent(userId: $userId, sessionId: $sessionId ?? (string) \Str::uuid(), history: $history);
                try {
                    $response = $agent->stream($promptMessage, $images, provider: $model->provider, model: $model->model_id);

                    // Fires once the underlying stream fully resolves — same AgentResponse
                    // ->then() mechanism the single-model /chat/stream path uses to get
                    // real token counts after the fact, not an estimate.
                    $cost             = null;
                    $promptTokens     = null;
                    $completionTokens = null;
                    $response->then(function (AgentResponse $r) use (&$cost, &$promptTokens, &$completionTokens, $model, $sessionId, $userId, $persistToChatService, $compareGroupId) {
                        $promptTokens     = $r->usage?->promptTokens ?? 0;
                        $completionTokens = $r->usage?->completionTokens ?? 0;
                        $cost             = $this->calculateCost($model, $promptTokens, $completionTokens);

                        if ($persistToChatService) {
                            $this->chatClient->appendMessage($sessionId, $userId, 'assistant', $r->text ?? '', [
                                'model_id'          => $model->id,
                                'prompt_tokens'     => $promptTokens,
                                'completion_tokens' => $completionTokens,
                                'cost'              => $cost,
                                'is_streaming'      => true,
                                'metadata'          => ['compare_group_id' => $compareGroupId],
                            ]);
                        }
                    });

                    foreach ($response as $event) {
                        if (! $event instanceof TextDelta) {
                            continue;
                        }
                        echo "data: " . json_encode(['model' => $modelId, 'chunk' => $event->delta]) . "\n\n";
                        flush();
                    }

                    echo "data: " . json_encode([
                        'model'             => $modelId,
                        'done'              => true,
                        'cost'              => $cost,
                        'prompt_tokens'     => $promptTokens,
                        'completion_tokens' => $completionTokens,
                    ]) . "\n\n";
                    flush();
                } catch (\Exception $e) {
                    echo "data: " . json_encode(['model' => $modelId, 'error' => $e->getMessage()]) . "\n\n";
                    flush();
                }
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }

    /**
     * POST /api/v1/chat/compact
     * Manual "compact conversation" — summarizes the full history the client sends
     * (deliberately unbounded, unlike /chat/stream's history param, since the whole
     * point is compressing everything so far) into a short brief the user can download
     * or carry into a new session. Stateless like /chat/compare's unsaved mode — the
     * frontend already holds the full message list, so there's no need for a new
     * chat-service internal endpoint just to re-fetch what the caller already has.
     * Goes through the same wallet/subscription accounting as any other model call —
     * summarizing real conversation content is a real request, not a freebie like
     * generateTitle() (which uses a fixed cheap model with no wallet involvement).
     */
    public function compact(Request $request)
    {
        $data = $request->validate([
            'model_id'          => 'required|string',
            'history'           => 'required|array|min:1',
            'history.*.role'    => 'required|in:user,assistant',
            'history.*.content' => 'required|string',
        ]);

        $userId = $this->authUserId($request);

        $access = $this->subscriptionClient->canAccess($userId, $data['model_id']);
        if (! $access['allowed']) {
            return response()->json([
                'error'  => 'Model not available in your subscription.',
                'reason' => $access['reason'],
            ], 403);
        }

        $model = AiModel::where('model_id', $data['model_id'])->where('is_active', true)->where('type', 'text')->first();
        if (! $model) {
            return response()->json(['error' => 'Unknown or unsupported model.'], 422);
        }

        $agent = new TextChatAgent(
            userId: $userId,
            sessionId: (string) \Str::uuid(),
            history: $data['history'],
            systemPrompt: 'You are summarizing a conversation so the user can continue it in a new chat. '
                .'Preserve the key facts, decisions, and any context genuinely needed to continue naturally — '
                .'skip pleasantries and meta-commentary about the summarization itself. Write it as a clear, '
                .'well-organized brief (short paragraphs or bullet points), not a transcript.',
        );

        try {
            $response = $agent->prompt('Summarize the conversation above for continuation in a new chat.', provider: $model->provider, model: $model->model_id);
            $summary  = trim((string) ($response->text ?? ''));

            if ($summary === '') {
                return response()->json(['error' => 'Could not generate a summary. Please try again.'], 503);
            }

            return response()->json(['summary' => $summary]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 402) {
                return response()->json(['error' => 'Insufficient wallet balance. Please top up.'], 402);
            }
            return response()->json(['error' => 'Compaction failed. Please try again.'], 503);
        } catch (\Throwable $e) {
            Log::error('Chat compaction failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Compaction failed. Please try again.'], 503);
        }
    }

    /**
     * POST /api/internal/generate-title
     * Called by chat-service once a session's first assistant reply lands, to
     * replace the "New Chat" default with something real. No wallet involvement —
     * see TitleGeneratorAgent's own docblock for why.
     */
    public function generateTitle(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:10000',
            'reply'   => 'nullable|string|max:20000',
        ]);

        $prompt = "First user message:\n{$data['message']}\n\n"
            . (! empty($data['reply']) ? "Assistant's reply:\n" . mb_substr($data['reply'], 0, 2000) . "\n\n" : '')
            . 'Generate the title now.';

        try {
            // Fixed to a known-cheap, known-working model rather than the session's own
            // model — titling a Claude Opus 5 conversation doesn't need Claude Opus 5.
            $response = (new TitleGeneratorAgent())->prompt($prompt, provider: 'deepseek', model: 'deepseek-v4-flash');
            $title    = trim((string) ($response->text ?? ''), " \t\n\r\0\x0B\"'.");

            return response()->json(['title' => $title !== '' ? $title : null]);
        } catch (\Throwable $e) {
            Log::warning('Title generation failed', ['error' => $e->getMessage()]);

            return response()->json(['title' => null]);
        }
    }

    /** Mirrors CostTrackingMiddleware's rate lookup — used to record cost on the persisted message. */
    private function calculateCost(AiModel $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = $model->activePricing();
        if (! $pricing || $pricing->pricing_type !== 'token_based') {
            return 0.0;
        }

        return ($promptTokens / 1_000_000 * (float) $pricing->input_rate_per_million)
             + ($completionTokens / 1_000_000 * (float) $pricing->output_rate_per_million);
    }
}
