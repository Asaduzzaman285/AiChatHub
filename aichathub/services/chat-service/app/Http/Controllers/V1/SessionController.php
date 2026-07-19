<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    /** GET /sessions */
    public function index(Request $request): JsonResponse
    {
        $sessions = ChatSession::where('user_id', $this->authUserId($request))
            ->orderByDesc('updated_at')
            ->paginate(20);

        return response()->json([
            'sessions' => $sessions->items(),
            'meta'     => [
                'current_page' => $sessions->currentPage(),
                'last_page'    => $sessions->lastPage(),
                'total'        => $sessions->total(),
            ],
        ]);
    }

    /** POST /sessions */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_id' => 'required|uuid',
            'title'    => 'nullable|string|max:255',
        ]);

        $session = ChatSession::create([
            'user_id'  => $this->authUserId($request),
            'model_id' => $data['model_id'],
            'title'    => $data['title'] ?? 'New Chat',
        ]);

        return response()->json(['session' => $session], 201);
    }

    /** GET /sessions/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwnedSession($request, $id);
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['session' => $session]);
    }

    /** PATCH /sessions/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwnedSession($request, $id);
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $data = $request->validate([
            'title'  => 'sometimes|string|max:255',
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ]);

        $session->update($data);

        return response()->json(['session' => $session]);
    }

    /** DELETE /sessions/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwnedSession($request, $id);
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $session->delete();

        return response()->json(['message' => 'Session deleted.']);
    }

    /** GET /sessions/{id}/export — not implemented in Phase 1 */
    public function export(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Not implemented.'], 501);
    }

    private function findOwnedSession(Request $request, string $id): ?ChatSession
    {
        return ChatSession::where('id', $id)->where('user_id', $this->authUserId($request))->first();
    }
}
