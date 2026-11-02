<?php

namespace App\Contracts;

interface BusinessEmbeddingProvider
{
    public function version(): string;

    public function tokens(string $text): array;

    public function embed(string $text): array;
}
