<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\FileAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

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
            // compare_group_id ties several assistant replies (one per model, from a
            // single /chat/compare turn) back together so the frontend can render
            // them as one side-by-side group instead of a run of separate replies.
            'metadata'            => 'nullable|array',
            // Which attachments (already uploaded via POST /upload, resolved into the
            // prompt by ai-gateway-service) belong to THIS message — file_attachments.
            // message_id existed as a column already but nothing ever wrote it, so an
            // uploaded image reached the model fine but never showed up again in the
            // message history afterward. Only ever sent for the user's own turn.
            'attachment_ids'      => 'nullable|array',
            'attachment_ids.*'    => 'uuid',
        ]);

        $session = ChatSession::find($sessionId);
        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $promptTokens     = $data['prompt_tokens'] ?? 0;
        $completionTokens = $data['completion_tokens'] ?? 0;
        $cost             = $data['cost'] ?? 0;

        $isFirstAssistantReply = false;

        $message = DB::transaction(function () use ($session, $data, $promptTokens, $completionTokens, $cost, &$isFirstAssistantReply) {
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
                'metadata'            => $data['metadata'] ?? null,
            ]);

            if (! empty($data['attachment_ids'])) {
                FileAttachment::whereIn('id', $data['attachment_ids'])
                    ->where('user_id', $data['user_id'])
                    ->update(['message_id' => $message->id]);
            }

            $session->increment('message_count');
            $session->increment('total_tokens', $promptTokens + $completionTokens);
            $session->increment('total_cost', $cost);

            // Session's model_id tracks "most recently used model" now that a
            // conversation can span several — the sidebar/header shows this.
            if (! empty($data['model_id']) && $data['model_id'] !== $session->model_id) {
                $session->update(['model_id' => $data['model_id']]);
            }

            // Auto-title trigger: the first assistant reply to the CURRENT user turn,
            // while the session is still sitting on the default title. Scoped to "since
            // the last user message" (not "ever," as this used to be) so a session whose
            // very first title-generation call failed — a transient network/ai-gateway
            // hiccup, logged as a warning below and previously left permanently untitled
            // with no retry — gets another attempt on its next reply instead of being
            // stuck forever. Still only fires once per turn: a 4-way /chat/compare turn
            // appends several assistant messages back-to-back for the same prompt, and
            // this only counts messages created at/after the latest user message, so the
            // rest of that same batch still see a sibling reply and skip it.
            if ($data['role'] === 'assistant' && $session->title === 'New Chat') {
                $lastUserMessageAt = ChatMessage::where('session_id', $session->id)
                    ->where('role', 'user')
                    ->orderByDesc('created_at')
                    ->value('created_at');

                $isFirstAssistantReply = $lastUserMessageAt && ! ChatMessage::where('session_id', $session->id)
                    ->where('role', 'assistant')
                    ->where('id', '!=', $message->id)
                    ->where('created_at', '>=', $lastUserMessageAt)
                    ->exists();
            }

            return $message;
        });

        if ($isFirstAssistantReply) {
            $this->generateSessionTitle($session, $data['content']);
        }

        return response()->json(['message_record' => $message], 201);
    }

    // Runs synchronously (not queued — chat-service has no dedicated queue worker, see
    // the other services' *-queue-worker containers for that pattern) — safe to do
    // inline because by the time this fires, the assistant's reply has already fully
    // streamed to the user; nothing here is on the critical path they're waiting on.
    private function generateSessionTitle(ChatSession $session, string $assistantReply): void
    {
        try {
            $firstUserMessage = ChatMessage::where('session_id', $session->id)
                ->where('role', 'user')
                ->orderBy('created_at')
                ->value('content');

            if (! $firstUserMessage) {
                return;
            }

            $response = Http::timeout(10)
                ->withHeaders(['X-Internal-Service-Key' => config('services.internal_key')])
                ->post(rtrim(config('services.ai_gateway_url'), '/').'/api/internal/generate-title', [
                    'message' => $firstUserMessage,
                    'reply'   => $assistantReply,
                ]);

            $title = $response->successful() ? trim((string) $response->json('title')) : null;

            if ($title) {
                $session->update(['title' => mb_substr($title, 0, 255)]);
            }
        } catch (\Throwable $e) {
            // Cosmetic feature — a failed title call should never break message
            // persistence, which has already succeeded by the time this runs.
            Log::warning('Auto-title generation failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);
        }
    }

    // Cap how much extracted document text can enter a single prompt — a huge PDF
    // would otherwise blow past a model's context window on its own.
    private const MAX_EXTRACTED_CHARS = 50000;

    private const TEXT_MIMES = ['text/plain', 'text/markdown', 'text/csv', 'application/json'];
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /**
     * POST /internal/attachments/resolve
     * Called by ai-gateway-service to turn attachment_ids from a /chat/stream request
     * into content it can hand to the AI provider. Images resolve to base64
     * (Image::fromBase64()) — MinIO isn't reachable from a real provider's servers in
     * local dev (no public tunnel), so the bytes have to travel inside the request body
     * instead of being fetched by the provider. Documents resolve to plain extracted
     * text instead, since that works with any model regardless of vision support.
     */
    public function resolveAttachments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|max:4',
            'ids.*' => 'uuid',
        ]);

        $attachments = FileAttachment::whereIn('id', $data['ids'])->get();

        $resolved = $attachments->map(function (FileAttachment $a) {
            $bytes = Storage::disk($a->storage_disk)->get($a->storage_path);

            $row = [
                'id'            => $a->id,
                'mime_type'     => $a->mime_type,
                'original_name' => $a->original_name,
            ];

            if (str_starts_with($a->mime_type, 'image/')) {
                $row['base64'] = base64_encode($bytes);
            } else {
                $row['extracted_text'] = $this->extractText($bytes, $a->mime_type, $a->original_name);
            }

            return $row;
        });

        return response()->json(['attachments' => $resolved]);
    }

    /**
     * GET /internal/sessions/{sessionId}/attachments
     * Lets ai-gateway-service re-inject a previously-uploaded document's extracted
     * text on a LATER turn that has no new attachment of its own. Without this, a
     * follow-up like "keep the same structure" had genuinely nothing to refer to —
     * extracted text is deliberately never written into persisted message content
     * or `history` (see resolveAttachments's own comment), so once the turn that
     * uploaded a file was over, its content was gone from every future turn even
     * though the UI still showed it attached in that chat. Confirmed live: exactly
     * this — a user uploaded a resume, asked for an edit, then a follow-up got
     * "I don't have access to the file."
     * Images excluded on purpose — re-sending base64 on every future turn is a
     * much bigger cost/latency problem than plain extracted text and isn't what
     * was reported; scoped to the actual complaint (documents), not vision.
     * Capped at the 4 most recent, same ceiling attachment_ids itself already has,
     * so a long session with many uploads doesn't grow this without bound.
     */
    public function sessionAttachments(Request $request, string $sessionId): JsonResponse
    {
        $attachments = FileAttachment::where('session_id', $sessionId)
            ->where('mime_type', 'not like', 'image/%')
            ->orderBy('created_at', 'desc')
            ->limit(4)
            ->get();

        $resolved = $attachments->map(function (FileAttachment $a) {
            $bytes = Storage::disk($a->storage_disk)->get($a->storage_path);

            return [
                'id'             => $a->id,
                'original_name'  => $a->original_name,
                'extracted_text' => $this->extractText($bytes, $a->mime_type, $a->original_name),
            ];
        });

        return response()->json(['attachments' => $resolved]);
    }

    /**
     * POST /internal/attachments/create-from-bytes
     * Server-to-server twin of FileAttachmentController::upload() — same store-to-R2 +
     * FileAttachment::create() logic, but for bytes a caller already holds in memory
     * (an AI-generated image/file) rather than a browser's multipart upload. Used by
     * ai-gateway-service's image/document generation tools so a generated result can
     * be linked to a message via the exact same attachment mechanism a real upload
     * already uses — see FileAttachmentController::upload() for the pattern this
     * mirrors, and appendMessage()'s attachment_ids handling for the message_id link.
     *
     * Real multipart file, not base64-in-JSON — see ChatServiceClient::
     * createAttachmentFromBytes()'s own comment for why that broke on real image
     * payloads (a curl rewind failure on retry, confirmed live).
     */
    public function createAttachmentFromBytes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'   => 'required|uuid',
            'mime_type' => 'required|string|max:100',
            'file'      => 'required|file',
        ]);

        $file = $request->file('file');
        $bytes = $file->get();
        $originalFilename = $file->getClientOriginalName();

        $userId = $data['user_id'];
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'bin';
        $storedName = \Illuminate\Support\Str::uuid().'.'.$extension;
        $path = "attachments/{$userId}/{$storedName}";

        Storage::disk('s3')->put($path, $bytes);

        // Generated content is trusted (it never passed through a user's own upload
        // form) — virus_scan_status is set straight to 'clean' rather than run through
        // ClamAvScanner, same reasoning FileAttachmentController's own CLAMAV_ENABLED
        // toggle uses for "this content didn't come from an untrusted upload."
        $attachment = FileAttachment::create([
            'user_id'           => $userId,
            'session_id'        => null,
            'file_name'         => $storedName,
            'original_name'     => $originalFilename,
            'file_size'         => strlen($bytes),
            'mime_type'         => $data['mime_type'],
            'storage_disk'      => 's3',
            'storage_path'      => $path,
            'storage_url'       => Storage::disk('s3')->url($path),
            'virus_scan_status' => 'clean',
            'virus_scan_at'     => now(),
        ]);

        return response()->json(['attachment' => $attachment], 201);
    }

    private function extractText(string $bytes, string $mimeType, string $originalName): string
    {
        try {
            $text = match (true) {
                $mimeType === 'application/pdf' => (new PdfParser())->parseContent($bytes)->getText(),
                $mimeType === self::DOCX_MIME    => $this->extractDocxText($bytes),
                $mimeType === self::XLSX_MIME    => $this->extractXlsxText($bytes),
                $mimeType === self::PPTX_MIME    => $this->extractPptxText($bytes),
                in_array($mimeType, self::TEXT_MIMES, true) => $bytes,
                default => '',
            };
        } catch (\Throwable $e) {
            Log::error('Document text extraction failed', ['file' => $originalName, 'mime' => $mimeType, 'error' => $e->getMessage()]);
            return '';
        }

        return mb_substr(trim($text), 0, self::MAX_EXTRACTED_CHARS);
    }

    private function extractDocxText(string $bytes): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'docx_');
        file_put_contents($tmpPath, $bytes);

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                return '';
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                return '';
            }

            // Paragraph/tab boundaries carry structure — turn them into whitespace
            // before stripping tags so words don't run together.
            $xml = preg_replace('/<\/w:p>/', "\n", $xml);
            $xml = preg_replace('/<w:tab\/>/', "\t", $xml);

            return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1);
        } finally {
            @unlink($tmpPath);
        }
    }

    // Same phpoffice/phpspreadsheet library ai-gateway-service uses to *generate*
    // .xlsx files (GenerateSpreadsheetTool) — reused here for the opposite direction,
    // reading one a user uploaded. toArray() gives cell values in row/column order,
    // tab-separated per row so the model still sees real table structure rather than
    // cells run together.
    private function extractXlsxText(string $bytes): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');
        file_put_contents($tmpPath, $bytes);

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
            $lines = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $lines[] = "--- Sheet: {$sheet->getTitle()} ---";
                foreach ($sheet->toArray(null, true, true, false) as $row) {
                    $lines[] = implode("\t", array_map(fn ($cell) => (string) ($cell ?? ''), $row));
                }
            }

            return implode("\n", $lines);
        } finally {
            @unlink($tmpPath);
        }
    }

    // Same phpoffice/phppresentation library used to generate .pptx files — reused
    // here to read one back. Only RichText shapes carry extractable text (images/
    // charts on a slide are skipped, same limitation any text-only extraction has).
    private function extractPptxText(string $bytes): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'pptx_');
        file_put_contents($tmpPath, $bytes);

        try {
            $presentation = \PhpOffice\PhpPresentation\IOFactory::load($tmpPath);
            $lines = [];

            foreach ($presentation->getAllSlides() as $index => $slide) {
                $lines[] = '--- Slide '.($index + 1).' ---';
                foreach ($slide->getShapeCollection() as $shape) {
                    if (! $shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                        continue;
                    }
                    foreach ($shape->getParagraphs() as $paragraph) {
                        $text = '';
                        foreach ($paragraph->getRichTextElements() as $element) {
                            $text .= $element->getText();
                        }
                        if (trim($text) !== '') {
                            $lines[] = $text;
                        }
                    }
                }
            }

            return implode("\n", $lines);
        } finally {
            @unlink($tmpPath);
        }
    }
}
