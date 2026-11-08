<?php
require __DIR__.'/api.php';
$owner = dc16Login('primary');
foreach (['accountant','sales','purchasing','warehouse','hr','manager','read_only'] as $role) {
    $email = $role.'@dc16.example.test';
    $user = App\Models\User::withoutGlobalScopes()->where('email', $email)->first();
    $password = $dc16['owner_password'].'-'.$role;
    if (!$user) {
        dc16Request('POST', 'users', ['name' => 'DC16 '.ucfirst($role), 'email' => $email, 'role' => $role, 'temporary_password' => $dc16['owner_password'], 'temporary_password_confirmation' => $dc16['owner_password']], $owner);
        $user = App\Models\User::withoutGlobalScopes()->where('email', $email)->firstOrFail();
    } elseif (!$user->email_verified_at) {
        dc16Request('POST', 'verify-email/resend', ['email' => $email]);
    }
    if (!$user->email_verified_at) {
        if (!preg_match('/token=([a-zA-Z0-9]{64})/', $capturedMail, $match)) throw new RuntimeException('No verification email');
        dc16Request('POST', 'verify-email', ['email' => $email, 'token' => $match[1]]);
    }
    if ($user->must_change_password) {
        $login = dc16Request('POST', 'login', ['email' => $email, 'password' => $dc16['owner_password']]);
        dc16Request('POST', 'change-password', ['password' => $password, 'password_confirmation' => $password], $login['access_token'] ?? $login['token']);
    }
    $login = dc16Request('POST', 'login', ['email' => $email, 'password' => $password]);
    $accounts = json_decode(file_get_contents($dc16Root.'/accounts.json'), true);
    $accounts[$role] = ['company_id' => $user->company_id, 'user_id' => $user->id, 'email' => $email, 'password' => $password, 'token' => $login['access_token'] ?? $login['token']];
    file_put_contents($dc16Root.'/accounts.json', json_encode($accounts, JSON_PRETTY_PRINT));
    echo $role." verified, password changed and signed in.\n";
}
dc16Evidence('roles', ['roles' => array_keys($accounts), 'normal_user_creation' => true, 'temporary_password_change' => true]);
// Company owner explicitly delegates opening review, not posting or approval.
Illuminate\Support\Facades\Auth::setUser(App\Models\User::withoutGlobalScopes()->findOrFail($accounts['accountant']['user_id']));
$permissions = app(App\Services\PermissionService::class)->forRole('accountant');
dc16Request('PUT','security/roles/accountant',['permissions'=>array_values(array_unique([...$permissions,'onboarding.view','onboarding.review']))],$owner);
dc16Evidence('reviewer-delegation',['role'=>'accountant','added'=>['onboarding.view','onboarding.review'],'approved_by'=>$accounts['primary']['user_id'],'reviewer'=>$accounts['accountant']['user_id']]);
