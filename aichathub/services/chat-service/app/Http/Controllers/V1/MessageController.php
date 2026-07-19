<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /** GET /sessions/{sessionId}/messages */
    public function index(Request $request, string $sessionId): JsonResponse
    {
        $session = ChatSession::where('id', $sessionId)->where('user_id', $this->authUserId($request))->first();
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $messages = ChatMessage::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * POST /sessions/{sessionId}/messages
     * Direct client-authored message persistence (e.g. a user note). The
     * normal chat turn — user message + assistant reply — is persisted by
     * ai-gateway-service via the internal endpoint after a completed
     * /chat/stream call, not through this route.
     */
    public function store(Request $request, string $sessionId): JsonResponse
    {
        $session = ChatSession::where('id', $sessionId)->where('user_id', $this->authUserId($request))->first();
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $data = $request->validate([
            'role'    => 'required|in:user,assistant,system',
            'content' => 'required|string',
        ]);

        $message = ChatMessage::create([
            'session_id' => $session->id,
            'user_id'    => $this->authUserId($request),
            'role'       => $data['role'],
            'content'    => $data['content'],
        ]);

        $session->increment('message_count');
        $session->touch();

        return response()->json(['message_record' => $message], 201);
    }
}
