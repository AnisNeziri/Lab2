<?php

namespace App\Integrations\Sef;

use App\Models\FiscalConfiguration;
use Illuminate\Validation\ValidationException;

/** One SHA-256 over base64 Protobuf. Current C# profile uses P1363; Go publishes DER. */
class SefSigner
{
    public function sign(string $details, FiscalConfiguration $configuration): string
    {
        $key = $configuration->credentials['private_key'] ?? null;
        if (! $key) {
            $this->fail('No server-side ATK TEST signing credential is installed.');
        }
        $private = openssl_pkey_get_private($key, $configuration->credentials['passphrase'] ?? '');
        if (! $private || openssl_pkey_get_details($private)['type'] !== OPENSSL_KEYTYPE_EC) {
            $this->fail('An EC signing credential is required.');
        }
        if ((openssl_pkey_get_details($private)['ec']['curve_name'] ?? null) !== 'prime256v1') {
            $this->fail('Use the ATK P-256 credential profile.');
        }
        $signature = '';
        if (! openssl_sign($details, $signature, $private, OPENSSL_ALGO_SHA256)) {
            $this->fail('The signing operation failed. Review the server credential.');
        }
        if ($configuration->signature_format === 'ieee_p1363') {
            $signature = $this->toP1363($signature);
        } elseif ($configuration->signature_format !== 'der') {
            $this->fail('The configured signing profile is unsupported.');
        }

        return base64_encode($signature);
    }

    public function toP1363(string $der): string
    {
        // OpenSSL returns DER SEQUENCE(INTEGER r,INTEGER s), each P-256 scalar <=33 bytes.
        $bytes = array_values(unpack('C*', $der));
        if (count($bytes) < 8 || $bytes[0] !== 0x30 || $bytes[1] !== count($bytes) - 2 || $bytes[2] !== 2) {
            $this->fail('Unsupported ECDSA signature encoding.');
        }
        $rlen = $bytes[3];
        $at = 4 + $rlen;
        if (! isset($bytes[$at + 1]) || $bytes[$at] !== 2) {
            $this->fail('Unsupported ECDSA signature encoding.');
        }
        $slen = $bytes[$at + 1];
        if ($at + 2 + $slen !== count($bytes)) {
            $this->fail('Unsupported ECDSA signature length.');
        }
        $r = ltrim(substr($der, 4, $rlen), "\0");
        $s = ltrim(substr($der, $at + 2, $slen), "\0");
        if (strlen($r) > 32 || strlen($s) > 32) {
            $this->fail('The credential must use the P-256 curve.');
        }

        return str_pad($r, 32, "\0", STR_PAD_LEFT).str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['signing' => $message]);
    }
}
