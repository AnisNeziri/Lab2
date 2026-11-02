<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(
        private JwtService $jwt
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);

            if ($token) {
                $payload = $this->jwt->validateAccessToken($token);

                if ($payload) {
                    $user = User::find($payload->sub ?? null);

                    if ($user && $user->is_active !== false && (int) $user->token_version === $payload->ver && (int)$user->company_id === (int)($payload->company_id ?? 0)) {
                        $session = app(\App\Services\Security\SessionSecurity::class)->find($payload->sid ?? null, $user);
                        if (isset($payload->sid) && !$session) return response()->json(['message'=>'This session has ended. Sign in again.'],401);
                        $request->attributes->set('auth_session', $session);
                        if ($session && \Carbon\Carbon::parse($session->last_active_at)->lt(now()->subMinute())) \Illuminate\Support\Facades\DB::table('auth_sessions')->where('id',$session->id)->update(['last_active_at'=>now()]);
                        Auth::login($user);
                        app(\App\Services\Commercial\Entitlements::class)->guardRequest();
                        app(\App\Services\Security\SessionSecurity::class)->guardRequest();

                        return $next($request);
                    }
                }

                $user = User::where('api_token', hash('sha256', $token))->first();

                if ($user && $user->is_active !== false && ($user->api_token_expires_at ?? $user->created_at->copy()->addDays(30))->isFuture()) {
                    Auth::login($user);
                    app(\App\Services\Commercial\Entitlements::class)->guardRequest();
                    app(\App\Services\Security\SessionSecurity::class)->guardRequest();

                    return $next($request);
                }
            }
        }

        return response()->json([
            'message' => 'Unauthorized. Please login again.',
        ], 401);
    }
}
