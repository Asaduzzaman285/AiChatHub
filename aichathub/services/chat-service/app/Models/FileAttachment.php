<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class FileAttachment extends Model
{
    use HasUuids;

    protected $table = 'file_attachments';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'session_id', 'message_id',
        'file_name', 'original_name', 'file_size', 'mime_type',
        'storage_disk', 'storage_path', 'storage_url',
        'virus_scan_status', 'virus_scan_at', 'expires_at',
    ];

    // Internal storage location — never part of the public response shape. Selected
    // in queries (e.g. FileAttachmentController::recent()) only because storageUrl()
    // needs them to compute a signed link, not because clients should see them.
    protected $hidden = ['storage_disk', 'storage_path'];

    protected function casts(): array
    {
        return [
            'file_size'    => 'integer',
            'expires_at'   => 'datetime',
            'virus_scan_at'=> 'datetime',
        ];
    }

    /**
     * Objects are stored privately (see FileAttachmentController::upload()) — every
     * read computes a fresh, short-lived signed URL against the S3-compatible API
     * instead of trusting the permanent link persisted in the legacy `storage_url`
     * column at upload time. AI providers never use this at all (resolveAttachments()
     * fetches bytes server-side); this is solely for browser-side previews.
     */
    protected function storageUrl(): Attribute
    {
        return Attribute::get(
            fn () => Storage::disk($this->storage_disk)->temporaryUrl($this->storage_path, now()->addMinutes(15))
        );
    }
}
