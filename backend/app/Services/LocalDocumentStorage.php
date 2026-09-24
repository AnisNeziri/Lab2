<?php

namespace App\Services;

use App\Contracts\DocumentStorageProvider;
use Illuminate\Support\Facades\Storage;

class LocalDocumentStorage implements DocumentStorageProvider
{
    private function disk()
    {
        return Storage::build(['driver' => 'local', 'root' => storage_path('app/documents-private'), 'throw' => true]);
    }

    private function safe(string $key): string
    {
        if (! preg_match('~^\d+/[a-f0-9]{2}/[a-f0-9-]{36}$~D', $key)) {
            throw new \InvalidArgumentException('Invalid document storage identifier.');
        }

return $key;
    }

    public function put(string $key, $stream): void
    {
        $key = $this->safe($key);
        if ($this->disk()->exists($key)) {
            throw new \LogicException('Document bytes are immutable.');
        } $this->disk()->put($key, $stream);
    }

    public function read(string $key)
    {
        return $this->disk()->readStream($this->safe($key));
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($this->safe($key));
    }

    public function checksum(string $key): ?string
    {
        if (! $this->exists($key)) {
            return null;
        } $stream = $this->read($key);
        try {
            $h = hash_init('sha256');
            hash_update_stream($h, $stream);

            return hash_final($h);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function discardUnreferenced(string $key): void
    {
        $key=$this->safe($key);
        if(\App\Models\DocumentVersion::withoutGlobalScopes()->where('storage_key',$key)->exists()){
            throw new \LogicException('Referenced document evidence cannot be discarded.');
        }
        $this->disk()->delete($key);
    }
}
