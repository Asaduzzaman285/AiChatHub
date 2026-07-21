<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\FileAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'model_id'            => 'nullable|uuid',
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
                'model_id'            => $data['model_id'] ?? null,
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

            // Session's model_id tracks "most recently used model" now that a
            // conversation can span several — the sidebar/header shows this.
            if (! empty($data['model_id']) && $data['model_id'] !== $session->model_id) {
                $session->update(['model_id' => $data['model_id']]);
            }

            return $message;
        });

        return response()->json(['message_record' => $message], 201);
    }

    /**
     * POST /internal/attachments/resolve
     * Called by ai-gateway-service to turn attachment_ids from a /chat/stream request
     * into actual image bytes it can hand to the AI provider (Image::fromBase64()).
     * Returns base64, not a URL — MinIO isn't reachable from a real provider's servers
     * in local dev (no public tunnel), so the image has to travel inside the request
     * body instead of being fetched by the provider.
     */
    public function resolveAttachments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|max:4',
            'ids.*' => 'uuid',
        ]);

        $attachments = FileAttachment::whereIn('id', $data['ids'])->get();

        $resolved = $attachments->map(fn (FileAttachment $a) => [
            'id'            => $a->id,
            'base64'        => base64_encode(Storage::disk($a->storage_disk)->get($a->storage_path)),
            'mime_type'     => $a->mime_type,
            'original_name' => $a->original_name,
        ]);

        return response()->json(['attachments' => $resolved]);
    }
}
