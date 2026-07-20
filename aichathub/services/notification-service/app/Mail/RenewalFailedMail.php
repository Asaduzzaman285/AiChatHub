<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RenewalFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $packageName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'We couldn\'t renew your AI ChatHub subscription');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.renewal-failed', with: [
            'name'        => $this->name,
            'packageName' => $this->packageName,
        ]);
    }
}
