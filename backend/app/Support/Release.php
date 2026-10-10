<?php

namespace App\Support;

final class Release
{
    public static function metadata(): array
    {
        $file = base_path('../RELEASE.json');
        if (! is_file($file)) $file = base_path('RELEASE.json');
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function version(): string
    {
        return self::metadata()['version'];
    }

    public static function assertBackupCompatible(string $version): void
    {
        $release = self::metadata();
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$/D', $version)
            || version_compare($version, $release['compatibility']['minimum_backup_version'], '<')
            || version_compare($version, $release['version'], '>')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'The backup application version is incompatible. Restore using its original AIMS release, then follow the supported upgrade runbook.',
            ]);
        }
    }
}
