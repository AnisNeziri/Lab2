<?php

namespace App\Console\Commands;

use App\Services\InstallationBackupService;
use Illuminate\Console\Command;

class InstallationBackup extends Command
{
    protected $signature = 'aims:installation-backup {operation=create : create, verify or restore} {--file=} {--key-file=} {--scheduled}';
    protected $description = 'Encrypted, verified installation recovery; restore into a separate empty target only';

    public function handle(InstallationBackupService $service): int
    {
        try {
            $keyFile = $this->option('key-file') ?: config('production.backup_passphrase_file');
            if (! $keyFile || ! is_readable($keyFile)) throw new \RuntimeException('Provide a protected passphrase file with --key-file; never pass the secret as a command argument.');
            $root = config('production.backup_root');
            if (! is_dir($root) && ! mkdir($root, 0700, true)) throw new \RuntimeException('Backup directory cannot be created.');
            $keyPath = strtolower(str_replace('\\', '/', realpath($keyFile)));
            $backupPath = strtolower(rtrim(str_replace('\\', '/', realpath($root)), '/').'/');
            if (str_starts_with($keyPath, $backupPath)) throw new \RuntimeException('Keep the backup passphrase file outside the backup directory.');
            $key = trim(file_get_contents($keyFile));
            $result = match ($this->argument('operation')) {
                'create' => $service->create($key, (bool) $this->option('scheduled')),
                'verify' => array_intersect_key($service->verify((string) $this->option('file'), $key), array_flip(['format', 'version', 'app_version', 'created_at', 'engine'])),
                'restore' => $service->restore((string) $this->option('file'), $key),
                default => throw new \RuntimeException('Operation must be create, verify or restore.'),
            };
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Process exceptions may contain host credential arguments or SQL.
            $this->error($error instanceof \Symfony\Component\Process\Exception\ProcessFailedException || $error instanceof \Illuminate\Database\QueryException || $error instanceof \PDOException ? 'Database backup/restore process failed. Verify client executable, DB connectivity and permissions. This installation is not certified.' : $error->getMessage());
            return self::FAILURE;
        }
    }
}
