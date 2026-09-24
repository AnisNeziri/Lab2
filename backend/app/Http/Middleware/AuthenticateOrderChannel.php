<?php
namespace App\Http\Middleware;
use App\Models\{OrderChannel,OrderChannelKey,User};
use Illuminate\Support\Facades\{Auth,RateLimiter};
use Illuminate\Http\Request;
use Closure;
class AuthenticateOrderChannel {
    public function handle(Request $r, Closure $next, string $scope) {
        abort_if(strlen($r->getContent())>262144,413,'Order payload exceeds 256 KB.');
        $token=$r->bearerToken(); abort_unless($token && strlen($token)<=200,401);
        $key=OrderChannelKey::where('token_hash',hash('sha256',$token))->first();
        abort_unless($key && !$key->revoked_at && (!$key->expires_at || $key->expires_at->isFuture()),401);
        abort_unless(in_array($scope,$key->scopes??[],true),403,'This channel credential does not permit this operation.');
        $channel=OrderChannel::withoutGlobalScopes()->where('company_id',$key->company_id)->find($key->order_channel_id);
        abort_unless($channel?->enabled,403,'Order channel is disabled.');
        $limit='order-channel:'.$key->id; abort_if(RateLimiter::tooManyAttempts($limit,60),429,'Channel rate limit exceeded. Retry later.'); RateLimiter::hit($limit,60);
        $actor=User::where('company_id',$key->company_id)->find($key->user_id); abort_unless($actor && $actor->is_active!==false,401);
        $previous=Auth::user(); Auth::setUser($actor); $r->setUserResolver(fn()=>$actor);
        $r->attributes->set('order_channel',$channel); $r->attributes->set('order_channel_key',$key);
        $key->update(['last_used_at'=>now()]);
        try { return $next($r); } finally { $previous ? Auth::setUser($previous) : Auth::forgetUser(); }
    }
}
