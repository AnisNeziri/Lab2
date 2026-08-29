<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AimsMailer
{
    public function send(Mailable $mailable, string $recipient): void
    {
        try {
            Mail::mailer(config('mail.default'))->to($recipient)->send($mailable);
        } catch (TransportExceptionInterface $exception) {
            Log::error('AIMS mail transport failed', [
                'recipient' => $recipient,
                'mailable' => $mailable::class,
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(
                'AIMS could not send the email. For local development, start Mailpit (SMTP on port 1025) or check MAIL_HOST, MAIL_PORT, MAIL_USERNAME, and MAIL_PASSWORD.',
                previous: $exception
            );
        } catch (\Throwable $exception) {
            Log::error('AIMS mail failed', [
                'recipient' => $recipient,
                'mailable' => $mailable::class,
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException('AIMS could not send the email.', previous: $exception);
        }
    }
}
