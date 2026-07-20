<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\FileAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileAttachmentController extends Controller
{
    // Phase 1 scope: images only — this is what actually flows into a vision-capable
    // model via Image::fromUrl() in ai-gateway-service. Documents/PDFs aren't wired
    // into the AI call path yet, so accepting them here would just be inert storage.
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_SIZE_KB = 10240; // 10MB

    /** POST /upload */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'       => 'required|file|max:'.self::MAX_SIZE_KB,
            'session_id' => 'nullable|uuid',
        ]);

        $file = $data['file'];
        if (! in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            return response()->json([
                'message' => 'Only image uploads are supported right now (JPEG, PNG, WebP, GIF).',
                'error'   => 'unsupported_file_type',
            ], 422);
        }

        $userId = $this->authUserId($request);
        $storedName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = "attachments/{$userId}/{$storedName}";

        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), 'public');

        $attachment = FileAttachment::create([
            'user_id'           => $userId,
            'session_id'        => $data['session_id'] ?? null,
            'file_name'         => $storedName,
            'original_name'     => $file->getClientOriginalName(),
            'file_size'         => $file->getSize(),
            'mime_type'         => $file->getMimeType(),
            'storage_disk'      => 's3',
            'storage_path'      => $path,
            'storage_url'       => Storage::disk('s3')->url($path),
            // No virus scanner wired up in Phase 1 — 'pending' (the schema default)
            // would imply a scan is queued when none is; be honest about the gap.
            'virus_scan_status' => 'not_scanned',
        ]);

        return response()->json(['attachment' => $attachment], 201);
    }

    /** DELETE /upload/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $attachment = FileAttachment::where('id', $id)->where('user_id', $this->authUserId($request))->first();
        if (! $attachment) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        Storage::disk('s3')->delete($attachment->storage_path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted.']);
    }
}
