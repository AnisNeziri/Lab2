<?php

namespace App\Services;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function __construct(private readonly AimsMailer $mailer) {}

    public function sendResetLink(string $email): bool
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return true;
        }

        $plainToken = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => hash('sha256', $plainToken),
                'created_at' => now(),
            ]
        );

        $this->mailer->send(new ResetPasswordMail($user, $plainToken), $user->email);

        return true;
    }

    public function reset(string $email, string $token, string $password): ?User
    {
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record || ! hash_equals($record->token, hash('sha256', $token))) {
            return null;
        }

        $expiresMinutes = (int) config('auth.passwords.users.expire', 60);
        if (now()->diffInMinutes($record->created_at) > $expiresMinutes) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return null;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            return null;
        }

        $user->password = $password;
        $user->must_change_password = false;
        $user->temporary_password_consumed = false;
        $user->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return $user;
    }
}
