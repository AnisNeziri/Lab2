<?php

namespace App\Contracts;

interface DocumentStorageProvider
{
    public function put(string $key, $stream): void;

    public function read(string $key);

    public function exists(string $key): bool;

    public function checksum(string $key): ?string;

    /** Internal cleanup of newly staged bytes, never a business-document delete. */
    public function discardUnreferenced(string $key): void;
}
