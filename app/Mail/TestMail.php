<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "The email settings work": sent from Settings → Email.
 */
class TestMail extends Mailable
{
    public function __construct(public readonly User $sentBy) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Test email from :app', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test');
    }
}
