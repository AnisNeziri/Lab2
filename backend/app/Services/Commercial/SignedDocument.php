<?php
namespace App\Services\Commercial;

use Illuminate\Validation\ValidationException;

/** Shares the existing desktop RSA-SHA256 envelope; private issuer keys never enter AIMS. */
final class SignedDocument
{
    public function verify(array $envelope, string $kind): array
    {
        try {
            if (!in_array($kind,['license','update'],true) || !is_string($envelope['payload']??null) || !is_string($envelope['signature']??null)
                || strlen($envelope['payload'])>90000 || !preg_match('/^[A-Za-z0-9_-]+$/D',$envelope['payload'])) throw new \RuntimeException();
            $bytes=base64_decode(strtr($envelope['payload'],'-_','+/'),true); $signature=base64_decode($envelope['signature'],true);
            $file=config('security.'.$kind.'_public_key');
            if (!$file || !is_readable($file)) throw new \RuntimeException();
            $key=openssl_pkey_get_public(file_get_contents($file)); $details=$key?openssl_pkey_get_details($key):false;
            if (!$details || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']<2048 || !$bytes || !$signature
                || openssl_verify($bytes,$signature,$key,OPENSSL_ALGO_SHA256)!==1) throw new \RuntimeException();
            $data=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
            if (($data['product']??null)!=='AIMS' || ($data['kind']??null)!==$kind || ($data['schema']??null)!==2) throw new \RuntimeException();
            return $data;
        } catch (\Throwable) { throw ValidationException::withMessages(['manifest'=>'The signed '.$kind.' document is invalid or its issuer key is not trusted.']); }
    }
}
