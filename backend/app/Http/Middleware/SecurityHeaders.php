<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request,Closure $next)
    {
        $response=$next($request);
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy','camera=(self), microphone=(), geolocation=(), publickey-credentials-get=(self), publickey-credentials-create=(self)');
        if ($request->isSecure()) $response->headers->set('Strict-Transport-Security','max-age=31536000');
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control','no-store, private');
            if (!$response->headers->has('Content-Security-Policy')) $response->headers->set('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        return $response;
    }
}
