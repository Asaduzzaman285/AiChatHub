<?php

namespace App\Listeners;

use App\Mail\WelcomeMail;
use App\Models\Notification;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail
{
    public function handle(object $event): void
    {
        $payload = $event->payload;

        // Idempotency — don't send twice for same user.registered event
        $key = "welcome:{$payload['user_id']}";
        if (Notification::where('idempotency_key', $key)->exists()) {
            return;
        }

        try {
            Mail::to($payload['email'])->send(new WelcomeMail($payload['name']));

            Notification::create([
                'user_id'         => $payload['user_id'],
                'type'            => 'welcome',
                'channel'         => 'email',
                'subject'         => 'Welcome to AI ChatHub',
                'content'         => "Welcome {$payload['name']}!",
                'status'          => 'sent',
                'sent_at'         => now(),
                'idempotency_key' => $key,
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id'         => $payload['user_id'],
                'type'            => 'welcome',
                'channel'         => 'email',
                'subject'         => 'Welcome to AI ChatHub',
                'content'         => "Welcome {$payload['name']}!",
                'status'          => 'failed',
                'error_message'   => $e->getMessage(),
                'idempotency_key' => $key,
            ]);
        }
    }
}
