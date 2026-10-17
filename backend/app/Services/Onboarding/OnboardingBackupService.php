<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingBackup;
use App\Services\PortableBackupService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class OnboardingBackupService
{
    private function cli(): string
    {
        $cli = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : dirname(PHP_BINARY).(PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        if (! is_file($cli)) {
            $cli = (new ExecutableFinder)->find('php') ?? '';
        }
        if (! is_file($cli)) {
            throw ValidationException::withMessages(['backup' => 'The PHP CLI runtime is unavailable for independent restore verification.']);
        }

        return $cli;
    }

    public function assertRestoreRuntime(): void
    {
        $probe = new Process([$this->cli(), '-r', 'echo json_encode(["version"=>PHP_VERSION_ID,"sqlite"=>in_array("sqlite",PDO::getAvailableDrivers(),true)]);'], base_path(), null, null, 10);
        $probe->run();
        $info = json_decode($probe->getOutput(), true);
        if (! $probe->isSuccessful() || ! ($info['sqlite'] ?? false) || ($info['version'] ?? 0) < 80300) {
            throw ValidationException::withMessages(['backup' => 'Independent restore requires the PHP 8.3+ CLI runtime with the SQLite PDO driver. Configure it before posting.']);
        }
    }

    public function create(int $id, array $data): OnboardingBackup
    {
        $service = app(OnboardingService::class);
        $service->permit('manage');
        $v = validator($data, ['kind' => 'required|in:pre,post', 'revision' => 'required|integer', 'passphrase' => 'required|string|min:12|max:200'])->validate();

        return DB::transaction(function () use ($id, $v, $service) {
            $s = $service->lock($id);
            $service->revision($s, (int) $v['revision']);
            if ($v['kind'] === 'pre') {
                $service->editable($s);
                $this->assertRestoreRuntime();
                if (! app(OnboardingReconciliation::class)->operationallyEmpty()) {
                    throw ValidationException::withMessages(['backup' => 'The company baseline is no longer empty. Resolve it before creating an opening backup.']);
                }
                $service->invalidate($s, 'backup.pre_requested');
            } elseif ($s->status !== 'verified') {
                throw ValidationException::withMessages(['backup' => 'Verify all posted positions before creating the post-cutover backup.']);
            }
            $contents = app(PortableBackupService::class)->export(null, $v['passphrase'])->getContent();
            $verified = app(PortableBackupService::class)->verifyContents($contents, $v['passphrase']);
            $backup = OnboardingBackup::create(['company_id' => $s->company_id, 'onboarding_session_id' => $s->id, 'kind' => $v['kind'], 'checksum' => hash('sha256', $contents),
                'encrypted_contents' => $contents, 'operational_fingerprint' => app(OnboardingFingerprint::class)->operational(),
                'verification' => ['authenticated' => true, 'revision' => $s->revision, 'archive' => $verified], 'created_by' => Auth::id()]);
            $s->update([$v['kind'].'_backup_id' => $backup->id, 'restore_verification' => null]);
            $service->event($s, 'backup.created', ['kind' => $v['kind'], 'backup_id' => $backup->id, 'checksum' => $backup->checksum, 'revision' => $s->revision]);

            return $backup;
        });
    }

    public function download(int $id, int $backupId)
    {
        $service = app(OnboardingService::class);
        $service->permit('manage');
        $s = $service->get($id);
        $backup = $s->backups()->findOrFail($backupId);
        if (! hash_equals($backup->checksum, hash('sha256', $backup->encrypted_contents))) {
            abort(409, 'Backup integrity failed.');
        }
        $service->event($s, 'backup.downloaded', ['backup_id' => $backup->id]);

        return response($backup->encrypted_contents, 200, ['Content-Type' => 'application/vnd.aims.backup+encrypted', 'Content-Disposition' => 'attachment; filename="AIMS-opening-'.$s->public_id.'-'.$backup->kind.'.aimsbackup"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function restoreVerify(int $id, array $data): array
    {
        $service = app(OnboardingService::class);
        $service->permit('lock');
        $v = validator($data, ['passphrase' => 'required|string|min:12|max:200'])->validate();
        $s = $service->get($id);
        if ($s->status !== 'verified' || ! $s->post_backup_id) {
            throw ValidationException::withMessages(['backup' => 'A verified post-cutover backup is required.']);
        }
        $backup = $s->backups()->findOrFail($s->post_backup_id);
        if (! hash_equals($backup->checksum, hash('sha256', $backup->encrypted_contents))) {
            abort(409, 'Backup integrity failed.');
        }
        app(PortableBackupService::class)->verifyContents($backup->encrypted_contents, $v['passphrase']);
        $destination = (string) Str::uuid();
        $root = storage_path('app/onboarding-verifications/'.$destination);
        mkdir($root, 0700, true);
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
            mkdir($root.'/runtime/'.$dir, 0700, true);
        }
        file_put_contents($root.'/source.aimsbackup', $backup->encrypted_contents, LOCK_EX);
        touch($root.'/restore.sqlite');
        $env = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/restore.sqlite', 'DB_URL' => '', 'DATABASE_URL' => '',
            'DB_HOST' => '127.0.0.1', 'LARAVEL_STORAGE_PATH' => $root.'/runtime', 'APP_CONFIG_CACHE' => $root.'/config.php',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'log',
            'FILESYSTEM_DISK' => 'local', 'AIMS_DOCUMENT_ROOT' => $root.'/runtime/documents',
            'ONBOARDING_RESTORE_ROOT' => $root, 'ONBOARDING_RESTORE_PARENT' => storage_path('app/onboarding-verifications')];
        $cli = $this->cli();
        $process = new Process([$cli, base_path('scripts/verify-onboarding-restore.php')], base_path(), $env, null, 180);
        // Passphrases travel only on stdin, never arguments, files, environment, or logs.
        $process->setInput(json_encode(['passphrase' => $v['passphrase'], 'session' => $s->public_id, 'checksum' => $backup->checksum], JSON_THROW_ON_ERROR));
        $process->run();
        $result = json_decode($process->getOutput(), true);
        $passed = $process->isSuccessful() && is_array($result) && ($result['passed'] ?? false);
        $evidence = ['passed' => $passed, 'at' => now()->toIso8601String(), 'backup_checksum' => $backup->checksum, 'destination_ref' => $destination,
            'database_engine' => 'sqlite', 'independent_database' => true, 'result' => $passed ? $result : ['code' => 'restore_verification_failed']];
        DB::transaction(function () use ($id, $service, $backup, $evidence) {
            $s = $service->lock($id);
            if ($s->post_backup_id !== $backup->id) {
                abort(409, 'The selected backup changed.');
            }$s->update(['restore_verification' => $evidence]);
            $service->event($s, 'restore.verified', $evidence);
        });

        return $evidence;
    }
}
