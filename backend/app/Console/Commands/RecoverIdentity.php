<?php
namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Commercial\InstallationIdentity;
use App\Services\Security\SecurityEvents;
use App\Services\Security\SessionSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RecoverIdentity extends Command
{
    protected $signature='aims:recover-identity {--company=} {--user=} {--key-file=} {--reason=}';
    protected $description='Installation-owner recovery of a lost administrator passkey; requires maintenance and a separately escrowed recovery key';
    public function handle(): int
    {
        try {
            if (!app()->isDownForMaintenance()) throw new \RuntimeException('Put the installation in maintenance mode and stop workers before owner recovery.');
            $file=$this->option('key-file');$reason=trim($this->option('reason')??'');$hash=config('security.recovery_key_hash');
            if (!$file || !is_readable($file) || filesize($file)>4096 || !$hash || strlen($reason)<10 || strlen($reason)>500) throw new \RuntimeException('Provide the protected recovery key file, configured recovery-key hash, exact company/user and a meaningful reason.');
            $secret=trim(file_get_contents($file));
            if (strlen($secret)<32 || !Hash::check($secret,$hash)) throw new \RuntimeException('The installation recovery credential is invalid.');
            app(InstallationIdentity::class)->id();
            $u=User::where('company_id',$this->option('company'))->where('id',$this->option('user'))->where('role','admin')->where('is_active',true)->first();
            if (!$u) throw new \RuntimeException('The specified active company administrator does not exist.');
            DB::transaction(function()use($u,$reason){
                DB::table('passkey_credentials')->where('company_id',$u->company_id)->where('user_id',$u->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
                app(SessionSecurity::class)->revoke($u);$u->forceFill(['api_token'=>null,'token_version'=>(int)$u->token_version+1])->save();
                $c=Company::whereKey($u->company_id)->lockForUpdate()->firstOrFail();$policy=$c->security_policy??[];$policy['privileged_passkey_required']=false;$c->update(['security_policy'=>$policy]);
                app(SecurityEvents::class)->record('identity.owner_recovery',$u,['reason_code'=>'installation_owner_verified']);
                // Deliberate administrative business evidence, separate from sanitized telemetry.
                \App\Models\ActivityLog::create(['company_id'=>$u->company_id,'user_id'=>$u->id,'action'=>'security.owner_recovery','entity'=>'User','entity_id'=>$u->id,'description'=>$reason,'new_value'=>['passkeys_revoked'=>true,'sessions_revoked'=>true,'password_changed'=>false]]);
            });
            $this->info('Recovery recorded. All administrator passkeys and sessions were revoked. Use the existing verified-email password recovery if needed, sign in, register replacement passkeys, then re-enable company policy.');return self::SUCCESS;
        }catch(\Throwable $e){$this->error($e instanceof \Illuminate\Database\QueryException?'Owner recovery failed. No recovery completion is recorded.':$e->getMessage());return self::FAILURE;}
    }
}
