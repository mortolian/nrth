<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InstanceSmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('[:app] SMTP test', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.instance-smtp-test-text',
        );
    }
}
