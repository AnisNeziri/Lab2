<?php

namespace App\Mail;

use App\Mail\Concerns\AimsBrandedEnvelope;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use AimsBrandedEnvelope, Queueable, SerializesModels;

    public string $resetUrl;

    public int $expiresMinutes;

    public function __construct(
        public User $user,
        public string $token,
    ) {
        $this->resetUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
            .'/reset-password?email='.urlencode($this->user->email).'&token='.$this->token;
        $this->expiresMinutes = (int) config('auth.passwords.users.expire', 60);
    }

    public function envelope(): Envelope
    {
        return $this->aimsEnvelope('Reset your AIMS password');
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.reset-password',
            text: 'mail.reset-password-text',
        );
    }
}
