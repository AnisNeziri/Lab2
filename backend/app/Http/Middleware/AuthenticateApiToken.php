<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            
            if ($token) {
                $user = User::where('api_token', $token)->first();
                
                if ($user) {
                    Auth::login($user);
                    return $next($request);
                }
            }
        }

        return response()->json([
            'message' => 'Unauthorized. Please login again.',
        ], 401);
    }
}
