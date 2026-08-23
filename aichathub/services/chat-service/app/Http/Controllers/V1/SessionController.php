<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    /** GET /sessions — optional ?project_id= filters to chats inside one project. */
    public function index(Request $request): JsonResponse
    {
        $query = ChatSession::where('user_id', $this->authUserId($request));

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->query('project_id'));
        }

        $sessions = $query->orderByDesc('updated_at')->paginate(20);

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
        // is_private/private_duration_minutes are ONLY ever accepted here — this is the one
        // and only place a chat's privacy status can be set. update() below structurally
        // forbids touching them afterward; there is no code path to convert an open chat to
        // private or vice versa.
        $data = $request->validate([
            'model_id'                 => 'required|uuid',
            'title'                    => 'nullable|string|max:255',
            'project_id'               => 'nullable|uuid',
            'is_private'               => 'sometimes|boolean',
            // Presets: 1h / 3h / 6h / 24h — the disappearing-chat timer duration.
            'private_duration_minutes' => ['required_if:is_private,true', Rule::in([60, 180, 360, 1440])],
        ]);

        if (! empty($data['project_id']) && ! $this->userOwnsProject($request, $data['project_id'])) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $isPrivate = $data['is_private'] ?? false;

        $session = ChatSession::create([
            'user_id'    => $this->authUserId($request),
            'model_id'   => $data['model_id'],
            'project_id' => $data['project_id'] ?? null,
            'title'      => $data['title'] ?? 'New Chat',
            'is_private' => $isPrivate,
            'expires_at' => $isPrivate ? now()->addMinutes($data['private_duration_minutes']) : null,
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

        // is_private/expires_at are structurally immutable after creation — 'prohibited' fails
        // validation (422) if the field is present at all, regardless of value. This is a real
        // enforcement, not just an omission a future change could accidentally reintroduce.
        // project_id is NOT locked the same way — moving a chat between projects (or out of one
        // entirely, via null) is a normal, expected action, unlike privacy.
        $data = $request->validate([
            'title'                    => 'sometimes|string|max:255',
            'status'                   => ['sometimes', Rule::in(['active', 'archived'])],
            'project_id'               => 'sometimes|nullable|uuid',
            'is_private'               => 'prohibited',
            'expires_at'               => 'prohibited',
            'private_duration_minutes' => 'prohibited',
        ]);

        if (array_key_exists('project_id', $data) && $data['project_id'] !== null
            && ! $this->userOwnsProject($request, $data['project_id'])) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

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

    // Stops a session being attached to a project that isn't the caller's own.
    private function userOwnsProject(Request $request, string $projectId): bool
    {
        return Project::where('id', $projectId)->where('user_id', $this->authUserId($request))->exists();
    }
}
