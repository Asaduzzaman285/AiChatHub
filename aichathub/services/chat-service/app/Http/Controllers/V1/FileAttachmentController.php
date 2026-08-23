<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\FileAttachment;
use App\Services\ClamAvScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileAttachmentController extends Controller
{
    public function __construct(private ClamAvScanner $scanner)
    {
    }

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    // Documents get their text extracted server-side (see ChatInternalController::
    // resolveAttachments) and injected into the prompt as context — works with any
    // model, not just vision-capable ones, since it travels as plain text.
    private const DOCUMENT_MIMES = [
        'application/pdf',
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    ];

    private const ALLOWED_MIMES = [...self::IMAGE_MIMES, ...self::DOCUMENT_MIMES];
    private const MAX_SIZE_KB = 10240; // 10MB

    private const RECENT_LIMIT = 15;

    /** GET /upload/recent — lets the chat composer offer "recently uploaded" as a
     *  quick-attach option instead of forcing a fresh pick from disk every time. */
    public function recent(Request $request): JsonResponse
    {
        $attachments = FileAttachment::where('user_id', $this->authUserId($request))
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIMIT)
            // storage_disk/storage_path must be selected even though they're not in the
            // response shape — FileAttachment::storageUrl() computes storage_url from
            // them on read, so omitting them here would produce a broken signed URL.
            ->get(['id', 'session_id', 'original_name', 'mime_type', 'file_size', 'storage_url', 'storage_disk', 'storage_path', 'created_at']);

        return response()->json(['attachments' => $attachments]);
    }

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
                'message' => 'Unsupported file type. Images (JPEG, PNG, WebP, GIF) and documents (PDF, DOCX, TXT, MD, CSV, JSON) are supported.',
                'error'   => 'unsupported_file_type',
            ], 422);
        }

        $bytes = file_get_contents($file->getRealPath());

        // Scanned synchronously (not queued) — files are capped at 10MB and typically
        // used in the same chat turn they're uploaded in, so a queued async scan would
        // let an infected file be read/previewed before the result was known. clamd is
        // a warm daemon (virus DB stays resident), so this stays fast. Fails closed: a
        // scanner error rejects the upload rather than silently skipping the check.
        $scanResult = $this->scanner->scan($bytes);
        if ($scanResult !== 'clean') {
            return response()->json([
                'message' => $scanResult === 'infected'
                    ? 'This file was flagged by malware scanning and cannot be uploaded.'
                    : 'File scanning is temporarily unavailable. Please try again shortly.',
                'error' => $scanResult === 'infected' ? 'malware_detected' : 'scan_unavailable',
            ], 422);
        }

        $userId = $this->authUserId($request);
        $storedName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = "attachments/{$userId}/{$storedName}";

        // No ACL argument — objects are stored privately. FileAttachment::storageUrl()
        // computes a fresh short-lived signed URL on every read instead; AI providers
        // never use this URL at all (see ChatInternalController::resolveAttachments()).
        Storage::disk('s3')->put($path, $bytes);

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
            'virus_scan_status' => 'clean',
            'virus_scan_at'     => now(),
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
