<?php

namespace App\Services;

use App\Contracts\IntelligenceProvider;

class DisabledIntelligenceProvider implements IntelligenceProvider
{
    public function name(): string { return 'disabled'; }
    public function available(): bool { return false; }

    public function respond(array $messages, array $allowedTools = []): array
    {
        return ['status' => 'unavailable', 'message' => 'No intelligence provider is configured. AIMS business functions remain fully independent.'];
    }
}
