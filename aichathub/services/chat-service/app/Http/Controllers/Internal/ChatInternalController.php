<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatInternalController extends Controller
{
    /**
     * POST /internal/sessions/{sessionId}/messages
     * Called by ai-gateway-service after a /chat/stream call completes, once
     * for the user's message and once for the assistant's reply.
     */
    public function appendMessage(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate([
            'user_id'             => 'required|uuid',
            'role'                => 'required|in:user,assistant,system',
            'content'             => 'required|string',
            'prompt_tokens'       => 'nullable|integer|min:0',
            'completion_tokens'   => 'nullable|integer|min:0',
            'cost'                => 'nullable|numeric|min:0',
            'is_streaming'        => 'nullable|boolean',
            'provider_message_id' => 'nullable|string',
        ]);

        $session = ChatSession::find($sessionId);
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $promptTokens     = $data['prompt_tokens'] ?? 0;
        $completionTokens = $data['completion_tokens'] ?? 0;
        $cost             = $data['cost'] ?? 0;

        $message = DB::transaction(function () use ($session, $data, $promptTokens, $completionTokens, $cost) {
            $message = ChatMessage::create([
                'session_id'          => $session->id,
                'user_id'             => $data['user_id'],
                'role'                => $data['role'],
                'content'             => $data['content'],
                'prompt_tokens'       => $promptTokens,
                'completion_tokens'   => $completionTokens,
                'total_tokens'        => $promptTokens + $completionTokens,
                'cost'                => $cost,
                'is_streaming'        => $data['is_streaming'] ?? false,
                'provider_message_id' => $data['provider_message_id'] ?? null,
            ]);

            $session->increment('message_count');
            $session->increment('total_tokens', $promptTokens + $completionTokens);
            $session->increment('total_cost', $cost);

            return $message;
        });

        return response()->json(['message_record' => $message], 201);
    }
}
