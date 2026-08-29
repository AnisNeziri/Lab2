<?php

namespace App\Mail;

use App\Mail\Concerns\AimsBrandedEnvelope;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use AimsBrandedEnvelope, Queueable, SerializesModels;

    public string $verifyUrl;

    public int $expiresHours;

    public function __construct(
        public User $user,
        public string $token,
        public string $code,
    ) {
        $this->verifyUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
            .'/verify-email?email='.urlencode($this->user->email).'&token='.$this->token;
        $this->expiresHours = (int) config('auth.verification.expire_hours', 24);
    }

    public function envelope(): Envelope
    {
        return $this->aimsEnvelope('Verify your AIMS account');
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.verify-email',
            text: 'mail.verify-email-text',
        );
    }
}
