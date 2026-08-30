<?php

namespace App\Support;

/**
 * Stable fingerprints for retryable business commands.
 *
 * Idempotency keys identify one command. The fingerprint prevents a caller
 * from accidentally reusing that key for a different payload and receiving a
 * misleading success response.
 */
final class RequestFingerprint
{
    public static function make(array $payload, array $except = []): string
    {
        foreach ($except as $key) {
            unset($payload[$key]);
        }

        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
