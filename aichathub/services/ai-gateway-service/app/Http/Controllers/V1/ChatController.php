<?php

namespace App\Http\Controllers\V1;

use App\Ai\Agents\TextChatAgent;
use App\Ai\Agents\TitleGeneratorAgent;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseWalletReservationJob;
use App\Models\AiModel;
use App\Services\ChatServiceClient;
use App\Services\GeneratedAttachmentTracker;
use App\Services\PendingReservationTracker;
use App\Services\SubscriptionClientService;
use App\Services\WalletClientService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;

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
        $imageAttachments = [];
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

        // Only reached when THIS turn has no new attachment of its own. Extracted text
        // is deliberately never written into persisted message content or `history`
        // (a big document re-sent as normal chat text on every future turn would blow
        // past a model's context budget on its own) — but that meant a document's
        // content was gone from the model's view the instant the turn that uploaded it
        // ended, even though the UI kept showing it attached in that same chat.
        // Confirmed live: a user uploaded a resume, asked for an edit, then a follow-up
        // ("keep the same structure") got "I don't have access to the file" — the model
        // was telling the truth about its own context, the UI just implied otherwise.
        // Re-resolving from chat-service here closes that gap for documents specifically
        // (images excluded — see getSessionAttachments's own comment on why).
        if ($documentContext === '' && $persistToChatService) {
            foreach ($this->chatClient->getSessionAttachments($sessionId) as $a) {
                if (! empty($a['extracted_text'])) {
                    $documentContext .= "\n\n--- Previously attached file: {$a['original_name']} ---\n{$a['extracted_text']}\n--- End of {$a['original_name']} ---\n";
                }
            }
        }

        // Re-derived from the model's real capabilities, never trusted straight from the
        // client — some providers throw (not silently ignore) an unsupported WebSearch
        // tool, and Deep Think is only meaningful for providers TextChatAgent actually
        // knows a real reasoning-request shape for (see its supportsDeepThink()).
        $webSearchEnabled = (bool) ($data['web_search'] ?? false) && (bool) ($model->capabilities['web_search'] ?? false);
        $deepThinkEnabled = (bool) ($data['deep_think'] ?? false)
            && TextChatAgent::supportsDeepThink($model->provider)
            && (bool) ($model->capabilities['reasoning'] ?? false);

        // Automatic, not a mode the user switches into — the tool is simply made
        // available to whatever text model they're already talking to, and the model
        // itself decides whether the user's message actually wants an image (same
        // behavior as ChatGPT/Gemini). Two gates, same "re-derive server-side" rule as
        // web search above: the selected model must itself support function/tool
        // calling (a model with capabilities.function_calling=false has no mechanism
        // to invoke this at all — DeepSeek's chat models are the current example), and
        // the user's actual subscription must include gemini-2.5-flash-image,
        // independent of which model is selected for the surrounding conversation.
        // (Was dall-e-3, then gpt-image-2 — see GenerateImageTool's own comment for
        // why it moved twice: first dall-e-3's retirement, then a deliberate switch
        // to Gemini's Nano Banana for cost and identity-preserving edit quality.)
        $imageGenEnabled = (bool) ($model->capabilities['function_calling'] ?? false)
            && ($this->subscriptionClient->canAccess($userId, 'gemini-2.5-flash-image')['allowed'] ?? false);

        // Same double-duty pattern as the image gate above: 'document-pdf' both gates the
        // whole "generate a file" bundle (xlsx/pptx/pdf offered together, not as three
        // separate toggles) and prices PDF generation specifically. The spreadsheet and
        // presentation tools each still look up their own pricing row before running —
        // pricing for those two can be seeded on the admin side independently, so this
        // one flag doesn't require all three rates to exist at once to start rolling out.
        $documentGenEnabled = (bool) ($model->capabilities['function_calling'] ?? false)
            && ($this->subscriptionClient->canAccess($userId, 'document-pdf')['allowed'] ?? false);

        $agent = new TextChatAgent(
            userId:              $userId,
            sessionId:           $sessionId,
            history:             $data['history'] ?? [],
            webSearchEnabled:    $webSearchEnabled,
            deepThinkEnabled:    $deepThinkEnabled,
            imageGenEnabled:     $imageGenEnabled,
            documentGenEnabled:  $documentGenEnabled,
            imageAttachments:    $imageAttachments,
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

                    // Populated by GenerateImageTool::handle() if the model called it
                    // during this turn — the tool itself has no way to reach this
                    // persistence step directly (it's invoked deep inside laravel/ai's own
                    // tool-calling loop), so it leaves the attachment id here instead. Same
                    // linkage mechanism a user's own upload already uses (attachment_ids ->
                    // appendMessage() -> file_attachments.message_id), so MessageBubble's
                    // existing rendering shows it with no frontend changes.
                    $generatedAttachmentIds = app(GeneratedAttachmentTracker::class)->all();

                    $this->chatClient->appendMessage($sessionId, $userId, 'assistant', $response->text ?? '', [
                        'model_id'          => $model->id,
                        'prompt_tokens'     => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'cost'              => $this->calculateCost($model, $promptTokens, $completionTokens),
                        'is_streaming'      => true,
                    ], $generatedAttachmentIds);
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

        // Same reasoning as /chat/stream's identical block — see its own comment. A
        // compare turn with no new attachment can still be a follow-up on a document
        // uploaded earlier in this same session ("keep the same structure" applies to
        // compare mode too, not just single-chat).
        if ($documentContext === '' && $sessionId !== null) {
            foreach ($this->chatClient->getSessionAttachments($sessionId) as $a) {
                if (! empty($a['extracted_text'])) {
                    $documentContext .= "\n\n--- Previously attached file: {$a['original_name']} ---\n{$a['extracted_text']}\n--- End of {$a['original_name']} ---\n";
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

        // Fan-out — genuinely concurrent now (see config/octane.php's swoole.options:
        // enable_coroutine + hook_flags), not a sequential foreach that made every
        // model wait for the previous one to fully finish (confirmed live: 77 seconds
        // for a 3-model compare — roughly the SUM of each model's response time, not
        // the time of the slowest one).
        //
        // Each model runs in its own Swoole coroutine and pushes its SSE events onto a
        // shared Channel instead of echoing directly — confirmed via direct research of
        // this Octane/Swoole version that a child coroutine can't safely echo into the
        // same HTTP response another coroutine owns (Swoole scopes output buffering per
        // coroutine), so this closure's own coroutine (the one Octane actually attached
        // to the response) is the ONLY one that ever echoes/flushes, draining whichever
        // model's event is ready next. A separate "closer" coroutine watches a WaitGroup
        // and closes the channel once every model coroutine has finished, which is what
        // ends the main read loop below.
        //
        // Also confirmed safe to keep CostTrackingMiddleware/UsageLoggingMiddleware's
        // direct DB calls unchanged inside each coroutine: this stack has no coroutine-
        // aware PDO driver, so those calls still run to completion synchronously inside
        // whichever coroutine makes them (serialized in practice, not corrupted) —
        // they just don't yield mid-query, which is fine given how small they are next
        // to the multi-second provider HTTP calls that are the actual point here.
        return response()->stream(function () use (
            $data, $userId, $models, $imageAttachments, $promptMessage,
            $sessionId, $persistToChatService, $compareGroupId, $history
        ) {
            $channel   = new Channel(64);
            $waitGroup = new WaitGroup();

            foreach ($data['model_ids'] as $modelId) {
                $model = $models->get($modelId);
                if (! $model) {
                    $channel->push(['model' => $modelId, 'error' => 'Unknown model']);
                    continue;
                }

                // Documents ride along regardless (plain text works with any model);
                // images only go to models that actually support vision — the other
                // columns get a clear per-model error instead of silently ignoring
                // the attachment (confirmed live: every model previously just said
                // "I don't see an image" because nothing was ever sent at all).
                if ($imageAttachments && ! ($model->capabilities['vision'] ?? false)) {
                    $channel->push(['model' => $modelId, 'error' => "{$model->name} doesn't support image input."]);
                    continue;
                }

                $waitGroup->add();
                Coroutine::create(function () use (
                    $channel, $waitGroup, $modelId, $model, $imageAttachments, $promptMessage,
                    $userId, $sessionId, $persistToChatService, $compareGroupId, $history
                ) {
                    try {
                        $images = array_map(
                            fn (array $a) => Image::fromBase64($a['base64'], $a['mime_type']),
                            $imageAttachments
                        );

                        // Real session_id reused across every model in the fan-out (not
                        // a fresh uuid per model) — CostTrackingMiddleware/
                        // UsageLoggingMiddleware only use it for logging context, and
                        // it's what lets prior turns from this same conversation feed
                        // in as history.
                        $agent = new TextChatAgent(userId: $userId, sessionId: $sessionId ?? (string) \Str::uuid(), history: $history);
                        $response = $agent->stream($promptMessage, $images, provider: $model->provider, model: $model->model_id);

                        // Fires once the underlying stream fully resolves — same
                        // AgentResponse ->then() mechanism the single-model /chat/stream
                        // path uses to get real token counts after the fact, not an
                        // estimate.
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
                            $channel->push(['model' => $modelId, 'chunk' => $event->delta]);
                        }

                        $channel->push([
                            'model'             => $modelId,
                            'done'              => true,
                            'cost'              => $cost,
                            'prompt_tokens'     => $promptTokens,
                            'completion_tokens' => $completionTokens,
                        ]);
                    } catch (\Exception $e) {
                        // Previously silent server-side — the client got this exact
                        // message as an SSE error event, but nothing was ever written to
                        // the log, so a real provider failure (e.g. a Gemini 503 "model
                        // overloaded") reported live was impossible to confirm after the
                        // fact. Same reasoning as the wallet-reservation/wallet-create
                        // \Throwable catches elsewhere — a failure a user can see
                        // deserves a trail.
                        Log::error('Compare turn failed for one model', [
                            'model'   => $modelId,
                            'error'   => $e->getMessage(),
                            'session' => $sessionId,
                            'user'    => $userId,
                        ]);

                        // Confirmed live: a model that throws here (e.g. a provider 503)
                        // never reaches the $response->then() callback above, which is
                        // the ONLY place a compare-turn message gets persisted — so this
                        // model's card was visible only transiently and vanished the
                        // instant the page refetched persisted messages. Persisting an
                        // error-flagged row here keeps it around exactly like a real
                        // answer would be, just empty with the reason recorded — the
                        // frontend renders metadata.error the same way it already
                        // renders a live-streaming error (see CompareCard's `error`
                        // prop / collapseCompareGroups(), which also knows to never
                        // treat an errored message as a valid "chosen" candidate).
                        $friendlyError = $this->friendlyProviderError($model, $e);

                        if ($persistToChatService) {
                            // A single space, not '' — chat-service validates content as
                            // required|string, and Laravel's required rule rejects an
                            // empty string outright. The frontend never actually renders
                            // this text; it checks metadata.error first.
                            $this->chatClient->appendMessage($sessionId, $userId, 'assistant', ' ', [
                                'model_id' => $model->id,
                                'metadata' => ['compare_group_id' => $compareGroupId, 'error' => $friendlyError],
                            ]);
                        }

                        $channel->push(['model' => $modelId, 'error' => $friendlyError]);
                    } finally {
                        $waitGroup->done();
                    }
                });
            }

            // Runs in its own coroutine so it doesn't block the main read loop below
            // from draining events as they arrive in the meantime — just waits for
            // every model coroutine's done() and then closes the channel, which is
            // what ends that loop.
            Coroutine::create(function () use ($waitGroup, $channel) {
                $waitGroup->wait();
                $channel->close();
            });

            // The only coroutine that ever echoes/flushes — pops whichever model's
            // event is ready next, so a fast model's tokens interleave with a slow
            // model's instead of queuing up behind it. pop() blocks cooperatively
            // (yields to the model coroutines) until something arrives or the channel
            // closes; false unambiguously means "closed and drained" since every
            // pushed value here is always an array, never a bare false.
            while (true) {
                $event = $channel->pop();
                if ($event === false) {
                    break;
                }
                echo "data: " . json_encode($event) . "\n\n";
                flush();
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

    /**
     * Turns a raw provider exception into the "{Model} failed to generate a response
     * because/due to ..." wording the compare UI shows per failed card. Needed here
     * (unlike stream()'s single-model path) because compare()'s per-model coroutine
     * catches its own exception and never reaches bootstrap/app.php's global
     * exception renderers — without this, the client got $e->getMessage() verbatim,
     * which for a provider SDK exception is often a raw, technical string never meant
     * for an end user (confirmed live: reported as illegible/unhelpful error text).
     */
    private function friendlyProviderError(AiModel $model, \Throwable $e): string
    {
        if ($e instanceof RateLimitedException) {
            return "{$model->name} failed to generate a response due to a provider rate-limit error.";
        }
        if ($e instanceof InsufficientCreditsException) {
            return "{$model->name} failed to generate a response because the provider account is unavailable.";
        }
        if ($e instanceof ProviderOverloadedException) {
            return "{$model->name} failed to generate a response because the provider is currently overloaded.";
        }
        if (str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout')) {
            return "{$model->name} could not generate a response because the provider request timed out.";
        }
        if ($e instanceof RequestException) {
            return $e->response->status() === 401
                ? "{$model->name} failed to generate a response because it isn't configured correctly (invalid provider API key)."
                : "{$model->name} failed to generate a response because the provider request failed.";
        }
        if ($e instanceof AiException) {
            return "{$model->name} failed to generate a response because the provider is temporarily unavailable.";
        }

        return "{$model->name} failed to generate a response due to an unexpected error.";
    }
}
