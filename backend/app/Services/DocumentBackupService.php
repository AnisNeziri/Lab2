<?php

namespace App\Services;

use App\Contracts\DocumentStorageProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The existing portable archive is in-memory. Bound document bytes explicitly,
 * rather than exhausting memory or silently omitting evidence. */
class DocumentBackupService
{
    public const MAX_BYTES = 33554432;

    public function export(array $versions, ?string $passphrase): array
    {
        if (! $versions) {
            return [];
        }
        app(DocumentService::class)->permit('documents.manage');
        if (! $passphrase) {
            throw ValidationException::withMessages(['passphrase' => 'Document evidence requires an encrypted backup passphrase.']);
        }
        $storage = app(DocumentStorageProvider::class);
        $files = [];
        $total = 0;
        foreach ($versions as $v) {
            $key = $v['storage_key'];
            if (isset($files[$key])) {
                continue;
            }$total += (int) $v['size'];
            if ($total > self::MAX_BYTES) {
                throw ValidationException::withMessages(['documents' => 'Document files exceed the 32 MB portable-archive safety limit. Use a complete encrypted installation backup; no files were silently omitted.']);
            }if (($v['provider'] ?? 'local') !== 'local' || ! hash_equals($v['checksum'], (string) $storage->checksum($key))) {
                throw ValidationException::withMessages(['documents' => 'A document file is missing or corrupt. Verify and recover it before backup.']);
            }$stream = $storage->read($key);
            try {
                $files[$key] = ['checksum' => $v['checksum'], 'bytes' => base64_encode(stream_get_contents($stream))];
            } finally {
                fclose($stream);
            }
        }

        return $files;
    }

    public function restoreFiles(array $versions, array $files, int $company): array
    {
        if (! $versions) {
            return [];
        }
        app(DocumentService::class)->permit('documents.manage');
        $total = 0;
        $decoded = [];
        foreach ($versions as $v) {
            $old = $v['storage_key'];
            if (isset($decoded[$old])) {
                if(strlen($decoded[$old])!==(int)$v['size']||!hash_equals($v['checksum'],hash('sha256',$decoded[$old])))throw ValidationException::withMessages(['file'=>'Document versions disagree about their shared file bytes.']);
                continue;
            }$entry = $files[$old] ?? null;
            $bytes = is_array($entry) ? base64_decode($entry['bytes'] ?? '', true) : false;
            if ($bytes === false || strlen($bytes) !== (int) $v['size'] || ! hash_equals($v['checksum'], hash('sha256', $bytes))) {
                throw ValidationException::withMessages(['file' => 'The backup is missing valid document bytes.']);
            }$total += strlen($bytes);
            if ($total > self::MAX_BYTES) {
                throw ValidationException::withMessages(['file' => 'The document backup exceeds the safe restore size.']);
            }$decoded[$old] = $bytes;
        }
        $storage = app(DocumentStorageProvider::class);
        $keys = [];
        try {
        foreach ($decoded as $old => $bytes) {
            $key = $company.'/'.substr(hash('sha256', $bytes), 0, 2).'/'.Str::uuid();
            $keys[$old]=$key;
            $stream = fopen('php://temp', 'w+b');
            try {
                fwrite($stream, $bytes);
                rewind($stream);
                $storage->put($key, $stream);
            } finally {
                fclose($stream);
            }$keys[$old] = $key;
        }
        } catch (\Throwable $error) {
            $this->discardStaged($keys);
            throw $error;
        }

        return $keys;
    }

    public function discardStaged(array $keys): void
    {
        foreach(array_unique(array_values($keys)) as $key){
            if(\App\Models\DocumentVersion::withoutGlobalScopes()->where('storage_key',$key)->exists())continue;
            try { app(DocumentStorageProvider::class)->discardUnreferenced($key); }
            catch(\Throwable $error){ report($error); }
        }
    }
}
