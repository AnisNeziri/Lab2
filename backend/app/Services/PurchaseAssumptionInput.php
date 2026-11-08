<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Interprets explicit hypothetical unit prices. This never supplies a business price. */
final class PurchaseAssumptionInput
{
    public function unitPrice(string $question): ?array
    {
        $question = preg_replace('/€\s*(\d+(?:[.,]\d{1,4})?)/u', '$1 eur', $question);
        $text = Str::lower(Str::ascii($question));
        if (!preg_match('/\b(?:assum\w*|hypothet\w*|what if|estimate\w*|example|supoz\w*|shembull|llogari\w*|sikur)\b/', $text)
            || !preg_match('/\b(\d+(?:[.,]\d{1,4})?)\s*(eur|euro|usd|all|gbp|chf|cny)\s*(?:(?:\/|per|a|each)\s*)?(metrin|metres?|meters?|metra|meter|m|pieces?|pcs|cope|kg|rolls?|rrotulla)\b/', $text, $match)) {
            return null;
        }
        return ['price' => str_replace(',', '.', $match[1]),
            'currency' => $match[2] === 'euro' ? 'EUR' : strtoupper($match[2]), 'unit' => $this->unit($match[3])];
    }

    public function unit(string $unit): string
    {
        $unit = Str::lower(Str::ascii(trim($unit)));
        return match (true) {
            in_array($unit, ['m', 'meter', 'meters', 'metre', 'metres', 'metra', 'metrin'], true) => 'm',
            in_array($unit, ['pcs', 'piece', 'pieces', 'cope'], true) => 'pcs',
            in_array($unit, ['roll', 'rolls', 'rrotulla'], true) => 'roll',
            default => $unit,
        };
    }
}
