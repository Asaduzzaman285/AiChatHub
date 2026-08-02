<?php

namespace App\Http\Controllers\V1;

use App\Ai\Agents\TextChatAgent;
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

        // 2b. Resolve any attached images to base64 — not a URL, since MinIO isn't
        // reachable from a real provider's servers in local dev (see ChatServiceClient).
        $images = [];
        if (! empty($data['attachment_ids'])) {
            if (! ($model->capabilities['vision'] ?? false)) {
                return response()->json(['error' => "{$model->name} doesn't support image input. Pick a vision-capable model."], 422);
            }

            $attachments = $this->chatClient->resolveAttachments($data['attachment_ids']);
            if (count($attachments) !== count($data['attachment_ids'])) {
                return response()->json(['error' => 'One or more attachments could not be found.'], 422);
            }

            $images = array_map(
                fn (array $a) => Image::fromBase64($a['base64'], $a['mime_type']),
                $attachments
            );
        }

        $agent = new TextChatAgent(
            userId:    $userId,
            sessionId: $sessionId,
            history:   $data['history'] ?? [],
        );

        if ($persistToChatService) {
            $this->chatClient->appendMessage($sessionId, $userId, 'user', $data['message'], ['model_id' => $model->id]);
        }

        try {
            // 3. Stream response — CostTrackingMiddleware fires reserve before, deduct after
            $response = $agent->stream($data['message'], $images, provider: $model->provider, model: $model->model_id);

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
            'message'   => 'required|string|max:10000',
            'model_ids' => 'required|array|min:2|max:4',
            'model_ids.*' => 'required|string',
        ]);

        $userId = $this->authUserId($request);

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

        // Fan-out — return streaming results per model
        // Each agent handles its own reserve/deduct independently
        return response()->stream(function () use ($data, $userId, $models) {
            foreach ($data['model_ids'] as $modelId) {
                $model = $models->get($modelId);
                if (! $model) {
                    echo "data: " . json_encode(['model' => $modelId, 'error' => 'Unknown model']) . "\n\n";
                    flush();
                    continue;
                }

                $agent = new TextChatAgent(userId: $userId, sessionId: \Str::uuid()->toString());
                try {
                    foreach ($agent->stream($data['message'], provider: $model->provider, model: $model->model_id) as $event) {
                        if (! $event instanceof TextDelta) {
                            continue;
                        }
                        echo "data: " . json_encode(['model' => $modelId, 'chunk' => $event->delta]) . "\n\n";
                        flush();
                    }
                } catch (\Exception $e) {
                    echo "data: " . json_encode(['model' => $modelId, 'error' => $e->getMessage()]) . "\n\n";
                    flush();
                }
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
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
