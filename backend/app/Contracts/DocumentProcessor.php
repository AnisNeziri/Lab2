<?php

namespace App\Contracts;

use App\Models\DocumentVersion;

/** Future optional extraction must return untrusted structured data for review;
 * a processor must never mutate business records or bypass document permissions. */
interface DocumentProcessor
{
    public function supports(string $mimeType): bool;
    public function extract(DocumentVersion $version, $authorizedStream): array;
}
