<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatAdminController extends Controller
{
    /**
     * GET /admin/users/{userId}/chat-sessions — read-only. Gated to
     * chat_logs.view, deliberately absent from every role's default
     * permission template (see auth-service's config/admin_roles.php) —
     * must be explicitly granted per-admin, matching Customer Support's
     * "where permission is granted" chat-history access in the spec.
     */
    public function index(Request $request, string $userId): JsonResponse
    {
        $perPage  = min((int) $request->query('per_page', 20), 100);
        $sessions = ChatSession::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'chat_sessions' => $sessions->items(),
            'meta'          => [
                'current_page' => $sessions->currentPage(),
                'last_page'    => $sessions->lastPage(),
                'total'        => $sessions->total(),
            ],
        ]);
    }

    /** GET /admin/chat-sessions/{sessionId}/messages — read-only, same chat_logs.view gate. */
    public function messages(Request $request, string $sessionId): JsonResponse
    {
        $session = ChatSession::find($sessionId);
        if (! $session) {
            return response()->json(['message' => 'Session not found.', 'error' => 'not_found'], 404);
        }

        $perPage  = min((int) $request->query('per_page', 50), 200);
        $messages = $session->messages()->orderBy('created_at')->paginate($perPage);

        return response()->json([
            'messages' => $messages->items(),
            'meta'     => [
                'current_page' => $messages->currentPage(),
                'last_page'    => $messages->lastPage(),
                'total'        => $messages->total(),
            ],
        ]);
    }
}
