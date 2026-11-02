<?php
namespace App\Observers;

use App\Models\User;
use App\Services\Security\SecurityEvents;
use App\Services\Security\SessionSecurity;

class SecurityUserObserver
{
    public function updated(User $u): void
    {
        if ($u->wasChanged('role') || ($u->wasChanged('is_active') && !$u->is_active)) {
            $roleChanged=$u->wasChanged('role');
            app(SessionSecurity::class)->revoke($u);
            $u->forceFill(['api_token'=>null,'token_version'=>(int)$u->token_version+1])->saveQuietly();
            app(SecurityEvents::class)->record($roleChanged?'role.changed':'user.disabled',auth()->user()??$u,['role'=>$u->role],$u->id);
        }
    }
}
