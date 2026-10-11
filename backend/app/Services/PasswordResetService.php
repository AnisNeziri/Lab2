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
        return DB::transaction(function () use ($email, $token, $password) {
        $record = DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();

        if (! $record || ! hash_equals($record->token, hash('sha256', $token))) {
            return null;
        }

        $expiresMinutes = (int) config('auth.passwords.users.expire', 60);
        $created = \Carbon\Carbon::parse($record->created_at);
        if ($created->lt(now()->subMinutes($expiresMinutes)) || $created->gt(now()->addSeconds(30))) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return null;
        }

        $user = User::where('email', $email)->lockForUpdate()->first();

        if (! $user) {
            return null;
        }

        $user->password = $password;
        $user->must_change_password = false;
        $user->temporary_password_consumed = false;
        $user->api_token = null;
        $user->remember_token = null;
        $user->token_version = (int) $user->token_version + 1;
        $user->save();
        app(JwtService::class)->revokeUserTokens($user);

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return $user;
        });
    }
}
