<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /** GET /projects */
    public function index(Request $request): JsonResponse
    {
        $projects = Project::where('user_id', $this->authUserId($request))
            ->withCount('sessions')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['projects' => $projects]);
    }

    /** POST /projects */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
        ]);

        $project = Project::create([
            'user_id' => $this->authUserId($request),
            'name'    => $data['name'],
            'color'   => $data['color'] ?? null,
        ]);

        return response()->json(['project' => $project], 201);
    }

    /** PATCH /projects/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $project = $this->findOwnedProject($request, $id);
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $data = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'color' => 'sometimes|nullable|string|max:20',
        ]);

        $project->update($data);

        return response()->json(['project' => $project]);
    }

    /**
     * DELETE /projects/{id}
     *
     * `mode` decides what happens to the project's chats — never silently destroyed.
     * Default `orphan` keeps every chat, just detached from the project; explicit
     * `delete_sessions` opt-in soft-deletes them the same way SessionController::destroy()
     * already does. The frontend surfaces these as two distinct, clearly-labeled actions
     * rather than one ambiguous "Delete" button.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $project = $this->findOwnedProject($request, $id);
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['orphan', 'delete_sessions'])],
        ]);
        $mode = $data['mode'] ?? 'orphan';

        DB::transaction(function () use ($project, $mode) {
            if ($mode === 'delete_sessions') {
                ChatSession::where('project_id', $project->id)->delete();
            } else {
                ChatSession::where('project_id', $project->id)->update(['project_id' => null]);
            }
            $project->delete();
        });

        return response()->json([
            'message' => $mode === 'delete_sessions'
                ? 'Project and its chats deleted.'
                : 'Project deleted. Its chats were kept and moved out of the project.',
        ]);
    }

    private function findOwnedProject(Request $request, string $id): ?Project
    {
        return Project::where('id', $id)->where('user_id', $this->authUserId($request))->first();
    }
}
