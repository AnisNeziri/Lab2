<?php

namespace App\Console\Commands;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\AimsMailer;
use Illuminate\Console\Command;

class TestAimsMailCommand extends Command
{
    protected $signature = 'aims:test-mail {email : Recipient email address}';

    protected $description = 'Send a test AIMS verification email to verify SMTP configuration';

    public function handle(AimsMailer $mailer): int
    {
        $email = $this->argument('email');

        $user = new User([
            'name' => 'AIMS Test User',
            'email' => $email,
        ]);

        try {
            $mailer->send(new VerifyEmailMail($user, 'test-token', '123456'), $email);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("AIMS test email sent to {$email} using ".config('mail.default').' / '.config('mail.from.address'));

        return self::SUCCESS;
    }
}
