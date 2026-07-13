<?php

namespace App\Http\Controllers\V1;

use App\Ai\Agents\TextChatAgent;
use App\Http\Controllers\Controller;
use App\Services\SubscriptionClientService;
use App\Services\WalletClientService;
use Illuminate\Http\Request;
use Laravel\Ai\Enums\Lab;

class ChatController extends Controller
{
    public function __construct(
        private SubscriptionClientService $subscriptionClient,
        private WalletClientService $walletClient
    ) {}

    /**
     * POST /api/v1/chat/stream
     * Streams AI response back to client using SSE.
     */
    public function stream(Request $request)
    {
        $data = $request->validate([
            'message'    => 'required|string|max:10000',
            'model_id'   => 'required|string',
            'session_id' => 'nullable|uuid',
            'history'    => 'nullable|array',
            'history.*.role'    => 'required_with:history|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
        ]);

        $userId    = $request->user()->id;
        $modelId   = $data['model_id'];
        $sessionId = $data['session_id'] ?? \Str::uuid()->toString();

        // 1. Verify model access via Subscription Service
        $access = $this->subscriptionClient->canAccess($userId, $modelId);
        if (! $access['allowed']) {
            return response()->json([
                'error'  => 'Model not available in your subscription.',
                'reason' => $access['reason'],
            ], 403);
        }

        // 2. Map model_id to provider — CostTrackingMiddleware handles reserve/deduct
        $agent = new TextChatAgent(
            userId:    $userId,
            sessionId: $sessionId,
            history:   $data['history'] ?? [],
        );

        try {
            // 3. Stream response — CostTrackingMiddleware fires reserve before, deduct after
            return $agent->stream($data['message'])
                ->usingVercelDataProtocol(); // Frontend uses Vercel AI SDK
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 402) {
                return response()->json(['error' => 'Insufficient wallet balance. Please top up.'], 402);
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

        $userId = $request->user()->id;

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

        // Fan-out — return streaming results per model
        // Each agent handles its own reserve/deduct independently
        return response()->stream(function () use ($data, $userId) {
            foreach ($data['model_ids'] as $modelId) {
                $agent = new TextChatAgent(userId: $userId, sessionId: \Str::uuid()->toString());
                try {
                    foreach ($agent->stream($data['message']) as $event) {
                        echo "data: " . json_encode(['model' => $modelId, 'chunk' => (string) $event]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                } catch (\Exception $e) {
                    echo "data: " . json_encode(['model' => $modelId, 'error' => $e->getMessage()]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }
}
