<?php

namespace App\Services;

use App\Mail\VerifyEmailMail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmailVerificationService
{
    public function __construct(private readonly AimsMailer $mailer) {}

    public function sendVerification(User $user): void
    {
        EmailVerificationToken::where('user_id', $user->id)->delete();

        $plainToken = Str::random(64);
        $code = (string) random_int(100000, 999999);

        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainToken),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addHours((int) config('auth.verification.expire_hours', 24)),
        ]);

        $this->mailer->send(new VerifyEmailMail($user, $plainToken, $code), $user->email);
    }

    public function verify(string $email, ?string $token = null, ?string $code = null): ?User
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->email_verified_at) {
            return null;
        }

        $record = EmailVerificationToken::where('user_id', $user->id)->latest('id')->first();

        if (! $record || $record->isExpired()) {
            return null;
        }

        $tokenValid = $token && hash_equals($record->token, hash('sha256', $token));
        $codeValid = $code && Hash::check($code, $record->code_hash);

        if (! $tokenValid && ! $codeValid) {
            return null;
        }

        $user->email_verified_at = now();
        $user->is_active = true;
        $user->save();

        EmailVerificationToken::where('user_id', $user->id)->delete();

        return $user;
    }

    public function resend(string $email): bool
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->email_verified_at) {
            return false;
        }

        $this->sendVerification($user);

        return true;
    }
}
