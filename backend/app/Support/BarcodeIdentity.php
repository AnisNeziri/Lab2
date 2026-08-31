<?php

namespace App\Support;

final class BarcodeIdentity
{
    public static function display(mixed $value): ?string
    {
        $display = trim((string) ($value ?? ''));

        return $display === '' ? null : $display;
    }

    public static function normalize(mixed $value): ?string
    {
        $display = self::display($value);
        if ($display === null) {
            return null;
        }

        // Barcode identity is independent of database collation. Preserve the
        // entered casing for display, but collapse whitespace and case-fold the
        // value used for lookup and uniqueness checks.
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', $display));
    }
}
