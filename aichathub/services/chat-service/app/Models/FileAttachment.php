<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FileAttachment extends Model
{
    use HasUuids;

    protected $table = 'file_attachments';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'session_id', 'message_id',
        'file_name', 'original_name', 'file_size', 'mime_type',
        'storage_disk', 'storage_path', 'storage_url',
        'virus_scan_status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size'    => 'integer',
            'expires_at'   => 'datetime',
            'virus_scan_at'=> 'datetime',
        ];
    }
}
