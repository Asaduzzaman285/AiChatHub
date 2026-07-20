<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LowBalanceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public float $balance,
        public bool $critical,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->critical ? 'Your wallet balance is critically low' : 'Your wallet balance is running low');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.low-balance', with: [
            'name'     => $this->name,
            'balance'  => number_format($this->balance, 2),
            'critical' => $this->critical,
        ]);
    }
}
