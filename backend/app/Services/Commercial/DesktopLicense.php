<?php
namespace App\Services\Commercial;

/** Compatibility bridge for shipped schema-1 desktop licenses; signed schema-2 company licenses use Entitlements. */
final class DesktopLicense
{
    public function state(): ?array
    {
        $file=config('security.desktop_license_file');if (!$file) return null;
        try {
            $keyFile=config('security.desktop_license_key');
            if (!is_readable($file) || filesize($file)>131072 || !$keyFile || !is_readable($keyFile)) throw new \RuntimeException();
            $doc=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            $raw=base64_decode(strtr($doc['payload'],'-_','+/'),true);$sig=base64_decode($doc['signature'],true);
            $key=openssl_pkey_get_public(file_get_contents($keyFile));$detail=$key?openssl_pkey_get_details($key):null;
            if (!$detail || $detail['bits']<2048 || $detail['type']!==OPENSSL_KEYTYPE_RSA || !$raw || !$sig || openssl_verify($raw,$sig,$key,OPENSSL_ALGO_SHA256)!==1) throw new \RuntimeException();
            $d=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if (($d['schema']??null)!==1 || ($d['product']??'')!=='AIMS' || !hash_equals((string)config('security.desktop_machine_id'),$d['machine_id']??'')) throw new \RuntimeException();
            if (isset($d['not_before']) && (!strtotime($d['not_before']) || now()->lt($d['not_before']))) throw new \RuntimeException();
            $expired=isset($d['expires_at']) && (!strtotime($d['expires_at']) || now()->gt($d['expires_at']));
            return ['status'=>$expired?'expired':'active','write_allowed'=>!$expired];
        }catch(\Throwable){return ['status'=>'verification_required','write_allowed'=>false];}
    }
}
