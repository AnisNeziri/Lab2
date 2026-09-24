<?php

namespace App\Contracts;

/** Vendor-neutral boundary reserved for a future local or remote intelligence provider. */
interface IntelligenceProvider
{
    public function name(): string;
    public function available(): bool;

    /** @param array<int, array<string, mixed>> $messages */
    public function respond(array $messages, array $allowedTools = []): array;
}
