<?php
namespace App\Services\Security;

use App\Models\Company;
use App\Models\User;
use App\Services\AuthService;
use App\Services\JwtService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use lbuchs\WebAuthn\WebAuthn;

final class PasskeyService
{
    public static function encode(string $bytes): string { return rtrim(strtr(base64_encode($bytes),'+/','-_'),'='); }
    public static function decode(string $value): string
    {
        if (strlen($value)>90000 || !preg_match('/^[A-Za-z0-9_-]+$/D',$value)) throw ValidationException::withMessages(['passkey'=>'Invalid passkey response.']);
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        if ($raw===false) throw ValidationException::withMessages(['passkey'=>'Invalid passkey response.']);
        return $raw;
    }
    private function server(): WebAuthn
    {
        $rp=config('security.passkeys.rp_id');
        abort_unless(is_string($rp) && $rp!=='' && !str_contains($rp,':'),503,'Configure the passkey site address first.');
        return new WebAuthn('AIMS',$rp,['none'],true);
    }
    private function handle(User $u): string
    {
        return hash('sha256',Company::findOrFail($u->company_id)->public_id.':'.$u->id,true);
    }
    public function options(string $purpose): array
    {
        $u=$purpose==='login'?null:auth()->user();
        if ($purpose==='register') app(SessionSecurity::class)->recent();
        $server=$this->server();
        if ($purpose==='register') {
            $exclude=DB::table('passkey_credentials')->where('user_id',$u->id)->whereNull('revoked_at')->pluck('credential_id')->map(fn($id)=>self::decode($id))->all();
            abort_if(count($exclude)>=20,422,'Revoke an unused passkey before adding another.');
            $args=$server->getCreateArgs($this->handle($u),$u->email,$u->name,60,true,true,null,$exclude);
            $args->publicKey->attestation='none';
        } else $args=$server->getGetArgs([],60,true,true,true,true,true,true);
        $id=(string)Str::uuid();
        DB::table('auth_challenges')->insert(['id'=>$id,'user_id'=>$u?->id,'company_id'=>$u?->company_id,
            'auth_session_id'=>request()->attributes->get('auth_session')?->id,'purpose'=>$purpose,
            'challenge'=>self::encode($server->getChallenge()->getBinaryString()),'expires_at'=>now()->addMinutes(3),'created_at'=>now(),'updated_at'=>now()]);
        return ['challenge_id'=>$id,'publicKey'=>$args->publicKey];
    }
    private function consume(string $id, string $purpose): object
    {
        // Commit consumption before parsing or cryptographic verification. Failed attempts cannot be replayed.
        $row=DB::transaction(function()use($id,$purpose){
            $q=DB::table('auth_challenges')->where('id',$id)->where('purpose',$purpose)->whereNull('consumed_at')->where('expires_at','>',now());
            if ($purpose!=='login') $q->where('user_id',auth()->id())->where('company_id',auth()->user()->company_id)->where('auth_session_id',request()->attributes->get('auth_session')?->id);
            $row=(clone $q)->lockForUpdate()->first();
            if (!$row || $q->update(['consumed_at'=>now()])!==1) return null;
            return $row;
        });
        if (!$row) throw ValidationException::withMessages(['passkey'=>'This request has expired or was already used. Try again.']);
        return $row;
    }
    private function clientData(array $v): string
    {
        $raw=self::decode($v['response']['clientDataJSON']);
        $data=json_decode($raw,true);
        if (!is_array($data) || !in_array($data['origin']??null,config('security.passkeys.origins'),true)
            || ($data['crossOrigin']??false)!==false || isset($data['topOrigin'])) throw ValidationException::withMessages(['passkey'=>'The passkey was requested from an untrusted site.']);
        return $raw;
    }
    public function finish(array $v, string $purpose): array
    {
        $challenge=$this->consume($v['challenge_id'],$purpose);
        try {
            $client=$this->clientData($v); $server=$this->server();
            if ($purpose==='register') {
                $u=auth()->user();
                $data=$server->processCreate($client,self::decode($v['response']['attestationObject']),self::decode($challenge->challenge),true,true,false);
                if (($data->isBackedUp??false) && !($data->isBackupEligible??false)) throw new \RuntimeException();
                if (!hash_equals($data->credentialId,self::decode($v['rawId']))) throw new \RuntimeException();
                $hash=hash('sha256',$data->credentialId);
                // Revocation remains final; a previously registered credential cannot be reassigned.
                if (DB::table('passkey_credentials')->where('credential_hash',$hash)->exists()) throw new \RuntimeException();
                $id=DB::table('passkey_credentials')->insertGetId(['company_id'=>$u->company_id,'user_id'=>$u->id,'credential_hash'=>$hash,
                    'credential_id'=>self::encode($data->credentialId),'public_key'=>$data->credentialPublicKey,'name'=>$v['name']??'My passkey',
                    'sign_count'=>$data->signatureCounter??0,'backup_eligible'=>$data->isBackupEligible??false,
                    'transports'=>json_encode(array_values(array_intersect($v['response']['transports']??[],['usb','nfc','ble','internal','hybrid']))),
                    'created_at'=>now(),'updated_at'=>now()]);
                app(SecurityEvents::class)->record('passkey.added',$u,[], $id);
                return ['registered'=>true,'id'=>$id];
            }
            $credential=DB::table('passkey_credentials')->where('credential_hash',hash('sha256',self::decode($v['rawId'])))->whereNull('revoked_at')->first();
            if (!$credential) throw new \RuntimeException();
            $u=User::where('company_id',$credential->company_id)->where('is_active',true)->whereNotNull('email_verified_at')->find($credential->user_id);
            if (!$u || ($purpose==='step_up' && ($u->id!==auth()->id() || $u->company_id!==auth()->user()->company_id))) throw new \RuntimeException();
            if (!empty($v['response']['userHandle']) && !hash_equals($this->handle($u),self::decode($v['response']['userHandle']))) throw new \RuntimeException();
            // Serialise counter verification, including authenticators that legitimately keep a zero counter.
            DB::transaction(function()use($credential,$server,$v,$client,$challenge){
                $current=DB::table('passkey_credentials')->where('id',$credential->id)->whereNull('revoked_at')->lockForUpdate()->first();
                if (!$current) throw new \RuntimeException();
                $authData=self::decode($v['response']['authenticatorData']);
                $flags=new \lbuchs\WebAuthn\Attestation\AuthenticatorData($authData);
                if (($flags->getIsBackup() && !$flags->getIsBackupEligible()) || (bool)$current->backup_eligible!==$flags->getIsBackupEligible()) throw new \RuntimeException();
                $server->processGet($client,$authData,self::decode($v['response']['signature']),
                    $current->public_key,self::decode($challenge->challenge),(int)$current->sign_count,true,true);
                DB::table('passkey_credentials')->where('id',$current->id)->update(['sign_count'=>$server->getSignatureCounter()??0,'last_used_at'=>now(),'updated_at'=>now()]);
            });
            if ($purpose==='step_up') {
                DB::table('auth_sessions')->where('id',request()->attributes->get('auth_session')->id)->update(['authenticated_at'=>now(),'auth_method'=>'passkey']);
                app(SecurityEvents::class)->record('authentication.confirmed',$u,['method'=>'passkey']);
                return ['confirmed'=>true];
            }
            if ($u->must_change_password) throw new \RuntimeException();
            $u->forceFill(['last_login_at'=>now(),'login_count'=>(int)$u->login_count+1])->save();
            app(SecurityEvents::class)->record('authentication.login',$u,['method'=>'passkey']);
            $tokens=app(JwtService::class)->issueTokens($u,null,'passkey');
            return $tokens+['token'=>$tokens['access_token'],'user'=>app(AuthService::class)->formatUser($u)];
        } catch (ValidationException $e) { throw $e; }
        catch (\Throwable) { throw ValidationException::withMessages(['passkey'=>'Passkey verification failed. Try again or use your password.']); }
    }
}
