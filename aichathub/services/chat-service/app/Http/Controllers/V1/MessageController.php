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
            ->with('attachments')
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
            'role'     => 'required|in:user,assistant,system',
            'content'  => 'required|string',
            // Used by the "compact conversation" feature to tag a carried-over summary
            // ({type: 'compaction_summary'}) so the frontend can render it distinctly —
            // stored as role 'assistant' (not 'system') so it flows unchanged through the
            // existing user/assistant history-building logic on future turns.
            'metadata' => 'nullable|array',
        ]);

        $message = ChatMessage::create([
            'session_id' => $session->id,
            'user_id'    => $this->authUserId($request),
            'role'       => $data['role'],
            'content'    => $data['content'],
            'metadata'   => $data['metadata'] ?? null,
        ]);

        $session->increment('message_count');
        $session->touch();

        return response()->json(['message_record' => $message], 201);
    }

    /**
     * PATCH /sessions/{sessionId}/messages/{messageId}/choose
     * "Choose the best" on a compare-turn card — marks exactly one message within its
     * compare_group_id group as the one that counts as real conversation history going
     * forward (frontend's collapseCompareGroups() in lib/tokenEstimate.ts is what
     * actually acts on this when building context for future turns; this endpoint just
     * persists which one). Re-pickable at any time — flips is_chosen within the group,
     * never rewrites messages already sent using an earlier choice.
     */
    public function choose(Request $request, string $sessionId, string $messageId): JsonResponse
    {
        $session = ChatSession::where('id', $sessionId)->where('user_id', $this->authUserId($request))->first();
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $message = ChatMessage::where('id', $messageId)->where('session_id', $sessionId)->first();
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $groupId = $message->metadata['compare_group_id'] ?? null;
        if (! $groupId) {
            return response()->json(['message' => 'This message is not part of a comparison.'], 422);
        }

        $siblings = ChatMessage::where('session_id', $sessionId)
            ->whereJsonContains('metadata->compare_group_id', $groupId)
            ->get();

        foreach ($siblings as $sibling) {
            $meta = $sibling->metadata ?? [];
            $meta['is_chosen'] = $sibling->id === $message->id;
            $sibling->update(['metadata' => $meta]);
        }

        return response()->json(['message' => 'Choice recorded.']);
    }

    /**
     * DELETE /sessions/{sessionId}/messages/{messageId}
     * Backs "edit a previously-sent prompt" on the frontend — editing changes what the
     * user asked, so everything sent *after* the original wording (its old reply, and
     * anything since) is stale and gets removed along with the message itself. The
     * frontend then sends the edited text through the normal streaming flow exactly
     * like a fresh message, generating a new reply against the rewritten prompt, rather
     * than this endpoint trying to rewrite content and regenerate in place. Only ever
     * called on a role:'user' message; there's no "edit an assistant reply" feature.
     */
    public function destroy(Request $request, string $sessionId, string $messageId): JsonResponse
    {
        $session = ChatSession::where('id', $sessionId)->where('user_id', $this->authUserId($request))->first();
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $message = ChatMessage::where('id', $messageId)->where('session_id', $sessionId)->first();
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }
        if ($message->role !== 'user') {
            return response()->json(['message' => 'Only your own messages can be edited.'], 422);
        }

        $deletedCount = ChatMessage::where('session_id', $sessionId)
            ->where('created_at', '>=', $message->created_at)
            ->where('id', '!=', $message->id)
            ->delete();

        $message->delete();
        $session->decrement('message_count', $deletedCount + 1);
        $session->touch();

        return response()->json(['message' => 'Message and its continuation removed.']);
    }
}
