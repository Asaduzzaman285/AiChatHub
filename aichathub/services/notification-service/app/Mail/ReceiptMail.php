<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public float $amount,
        public string $currency,
        public string $description,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your AI ChatHub receipt');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.receipt', with: [
            'name'        => $this->name,
            'amount'      => number_format($this->amount, 2),
            'currency'    => $this->currency,
            'description' => $this->description,
        ]);
    }
}
