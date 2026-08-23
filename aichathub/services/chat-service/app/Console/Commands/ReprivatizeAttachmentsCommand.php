<?php

namespace App\Console\Commands;

use App\Models\FileAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off cleanup for files uploaded before the private-by-default fix: attempts to
 * flip each existing object to private via Flysystem's setVisibility(). Cloudflare R2
 * doesn't honor per-object ACLs the way AWS S3 does — public access is actually
 * controlled at the bucket level (the storage.alveta.ai custom domain / r2.dev
 * routing) — so this may be a no-op there. Safe to run either way: it reports what
 * actually happened instead of assuming success, and the real fix for R2 specifically
 * is disabling that bucket-level public routing in the Cloudflare dashboard.
 */
class ReprivatizeAttachmentsCommand extends Command
{
    protected $signature   = 'chat:reprivatize-attachments';
    protected $description = 'Attempt to flip existing file_attachments objects to private storage visibility';

    public function handle(): void
    {
        $attachments = FileAttachment::all(['id', 'storage_disk', 'storage_path']);

        if ($attachments->isEmpty()) {
            $this->info('No attachments found.');
            return;
        }

        $ok = 0;
        $failed = 0;

        foreach ($attachments as $attachment) {
            try {
                Storage::disk($attachment->storage_disk)->setVisibility($attachment->storage_path, 'private');
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Failed for {$attachment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$attachments->count()} attachment(s): {$ok} succeeded, {$failed} failed.");
        $this->info('If this ran without errors but files are still publicly reachable, the exposure is at the bucket/custom-domain level, not per-object ACL — disable public access on the R2 bucket in the Cloudflare dashboard.');
    }
}
