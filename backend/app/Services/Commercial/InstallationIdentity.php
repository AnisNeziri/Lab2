<?php
namespace App\Services\Commercial;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class InstallationIdentity
{
    public function read(string $key): string
    {
        $value=DB::table('installation_state')->where('key',$key)->value('value');
        if (!$value) throw new \RuntimeException('Installation encryption evidence is missing. Recover the original key and installation state.');
        try { return Crypt::decryptString($value); }
        catch (\Throwable) { throw new \RuntimeException('The application key cannot unlock this installation. Restore the original key; do not generate a replacement.'); }
    }
    public function verify(): bool { return hash_equals('AIMS-ENCRYPTION-CHECK-V1',$this->read('key_check')); }
    public function id(): string { if (!$this->verify()) throw new \RuntimeException('Encryption-key validation failed.'); return $this->read('identity'); }
    public function write(string $key, string $value): void
    {
        DB::table('installation_state')->updateOrInsert(['key'=>$key],['value'=>Crypt::encryptString($value),'updated_at'=>now()]);
    }
}
