<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;

class JwtService
{
    public function issueTokens(User $user): array
    {
        return [
            'access_token' => $this->createAccessToken($user),
            'refresh_token' => $this->createRefreshToken($user),
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.access_ttl', 900),
        ];
    }

    public function createAccessToken(User $user): string
    {
        $now = time();
        $ttl = (int) config('jwt.access_ttl', 900);

        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $this->encode([
            'iss' => config('app.url'),
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + $ttl,
            'role' => $user->role,
            'company_id' => $user->company_id,
        ]);

        $signature = $this->sign("{$header}.{$payload}");

        return "{$header}.{$payload}.{$signature}";
    }

    public function createRefreshToken(User $user): string
    {
        $plain = Str::random(80);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addSeconds((int) config('jwt.refresh_ttl', 2_592_000)),
        ]);

        return $plain;
    }

    public function validateAccessToken(string $token): ?object
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        if (! hash_equals($this->sign("{$header}.{$payload}"), $signature)) {
            return null;
        }

        $data = json_decode($this->decode($payload));

        if (! $data || ($data->exp ?? 0) < time()) {
            return null;
        }

        return $data;
    }

    public function refresh(string $refreshToken): ?array
    {
        $record = RefreshToken::where('token_hash', hash('sha256', $refreshToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            return null;
        }

        $user = $record->user;

        if (! $user || $user->is_active === false) {
            return null;
        }

        $record->update(['revoked_at' => now()]);

        return $this->issueTokens($user);
    }

    public function revokeUserTokens(User $user): void
    {
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function sign(string $value): string
    {
        return $this->encode(hash_hmac('sha256', $value, $this->secret(), true));
    }

    private function encode(array|string $value): string
    {
        $raw = is_array($value) ? json_encode($value) : $value;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }

    private function secret(): string
    {
        return (string) config('jwt.secret', config('app.key'));
    }
}
