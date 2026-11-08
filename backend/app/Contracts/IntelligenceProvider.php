<?php

namespace App\Contracts;

/** Provider-neutral inference boundary. Server-side tools remain the business authority. */
interface IntelligenceProvider
{
    public function name(): string;
    public function available(): bool;

    /** @param array<int, array<string, mixed>> $messages */
    public function respond(array $messages, array $allowedTools = []): array;
}
