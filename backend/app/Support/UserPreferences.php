<?php

namespace App\Support;

class UserPreferences
{
    public const DEFAULTS = [
        'theme' => 'light',
        'language' => 'en',
        'enable_3d_map' => false,
    ];

    public static function normalize(?array $preferences): array
    {
        $merged = array_merge(self::DEFAULTS, $preferences ?? []);

        if (! in_array($merged['theme'], ['light', 'dark'], true)) {
            $merged['theme'] = 'light';
        }

        if (! in_array($merged['language'], ['en', 'sq'], true)) {
            $merged['language'] = 'en';
        }

        $merged['enable_3d_map'] = (bool) $merged['enable_3d_map'];

        return $merged;
    }
}
