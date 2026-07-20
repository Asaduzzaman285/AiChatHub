<?php

namespace App\Services;

use App\Mail\LowBalanceMail;
use App\Mail\ReceiptMail;
use App\Mail\RenewalFailedMail;
use App\Mail\WelcomeMail;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * @return bool true if sent (or already sent — idempotent), false on failure
     */
    public function send(string $type, string $userId, string $email, array $data, ?string $idempotencyKey = null): bool
    {
        if ($idempotencyKey && Notification::where('idempotency_key', $idempotencyKey)->exists()) {
            return true;
        }

        [$mailable, $subject, $content] = match ($type) {
            'welcome'        => [new WelcomeMail($data['name']), 'Welcome to AI ChatHub', "Welcome {$data['name']}!"],
            'receipt'        => [
                new ReceiptMail($data['name'], (float) $data['amount'], $data['currency'] ?? 'USD', $data['description']),
                'Your AI ChatHub receipt',
                "Receipt: {$data['description']} — {$data['amount']} {$data['currency']}",
            ],
            'low_balance'    => [
                new LowBalanceMail($data['name'], (float) $data['balance'], (bool) ($data['critical'] ?? false)),
                'Wallet balance alert',
                "Balance alert: {$data['balance']}",
            ],
            'renewal_failed' => [
                new RenewalFailedMail($data['name'], $data['package_name']),
                'Subscription renewal failed',
                "Renewal failed for {$data['package_name']}",
            ],
            default => [null, null, null],
        };

        if (! $mailable) {
            Log::error('NotificationService::send — unknown type', ['type' => $type]);
            return false;
        }

        try {
            Mail::to($email)->send($mailable);

            Notification::create([
                'user_id'         => $userId,
                'type'            => $type,
                'channel'         => 'email',
                'subject'         => $subject,
                'content'         => $content,
                'metadata'        => $data,
                'status'          => 'sent',
                'sent_at'         => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            return true;
        } catch (\Exception $e) {
            Notification::create([
                'user_id'         => $userId,
                'type'            => $type,
                'channel'         => 'email',
                'subject'         => $subject,
                'content'         => $content,
                'metadata'        => $data,
                'status'          => 'failed',
                'failed_at'       => now(),
                'error_message'   => $e->getMessage(),
                'idempotency_key' => $idempotencyKey,
            ]);

            Log::error('NotificationService::send failed', ['type' => $type, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
