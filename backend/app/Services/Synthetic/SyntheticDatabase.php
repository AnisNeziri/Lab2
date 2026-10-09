<?php

namespace App\Services\Synthetic;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** This boundary never accepts a user-supplied database path or connection. */
class SyntheticDatabase
{
    public const MARKER = 'AIMS_PM3_SYNTHETIC_ONLY';

    public function path(int $seed): string
    {
        if ($seed < 1 || $seed > 2147483647) throw new RuntimeException('Seed must be between 1 and 2147483647.');
        return storage_path("app/synthetic/aims-pm3-{$seed}.sqlite");
    }

    public function prepare(int $seed, bool $reset): string
    {
        $path = $this->path($seed);
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        // Do not follow a symlink into another installation or database.
        if (is_link($path) || is_link(dirname($path))) throw new RuntimeException('Synthetic database path must not be a symbolic link.');
        if (file_exists($path)) {
            $pdo = new \PDO('sqlite:'.$path);
            try { $manifest = $pdo->query('select marker, seed from _aims_synthetic_manifest')->fetch(\PDO::FETCH_ASSOC); }
            catch (\Throwable) { throw new RuntimeException('Refusing to modify an unrecognized database.'); }
            if (($manifest['marker'] ?? null) !== self::MARKER || (int) ($manifest['seed'] ?? 0) !== $seed) throw new RuntimeException('Refusing to modify a non-synthetic or mismatched-seed database.');
            $pdo = null;
            if (! $reset) throw new RuntimeException('Synthetic database already exists. Use --reset to regenerate only this seed.');
            // SQLite sidecars belong to this exact verified database, not an arbitrary directory.
            $ownedFiles = [$path, $path.'-wal', $path.'-shm', $path.'.report.json'];
            foreach ($ownedFiles as $file) if (is_link($file)) throw new RuntimeException('Synthetic sidecars must not be symbolic links.');
            foreach ($ownedFiles as $file) if (is_file($file)) unlink($file);
        }
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('create table _aims_synthetic_manifest (marker text not null, seed integer not null, status text not null)');
        $pdo->prepare('insert into _aims_synthetic_manifest values (?, ?, ?)')->execute([self::MARKER, $seed, 'generating']);
        $pdo = null;
        $this->connect($seed);
        return $path;
    }

    public function assertReady(int $seed): void
    {
        $path = $this->path($seed);
        if (! is_file($path) || is_link($path) || is_link(dirname($path))) throw new RuntimeException('Owned synthetic database does not exist.');
        $pdo = new \PDO('sqlite:'.$path);
        $row = $pdo->query('select marker, seed, status from _aims_synthetic_manifest')->fetch(\PDO::FETCH_ASSOC);
        if (($row['marker'] ?? null) !== self::MARKER || (int) ($row['seed'] ?? 0) !== $seed || ($row['status'] ?? null) !== 'complete') throw new RuntimeException('Synthetic database is incomplete or ownership does not match.');
    }

    public function connect(int $seed): void
    {
        $path = $this->path($seed);
        if (! is_file($path) || is_link($path) || is_link(dirname($path))) throw new RuntimeException('Owned synthetic database does not exist.');
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => $path, 'database.connections.sqlite.url' => null,
            'database.connections.sqlite.foreign_key_constraints' => true,
            'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
            'mail.default' => 'array', 'broadcasting.default' => 'log',
            'synthetic.document_root' => dirname($path).'/documents-'.$seed,
            'cache.stores.file.path' => dirname($path).'/cache-'.$seed,
            'cache.stores.file.lock_path' => dirname($path).'/cache-'.$seed,
        ]);
        DB::purge('sqlite');
        $manifest = DB::table('_aims_synthetic_manifest')->first();
        if ($manifest?->marker !== self::MARKER || (int) $manifest?->seed !== $seed) throw new RuntimeException('Synthetic ownership check failed.');
        // Applies only to the verified synthetic database; atomic daily transactions remain durable.
        DB::statement('PRAGMA journal_mode = WAL');
        DB::statement('PRAGMA synchronous = NORMAL');
    }
}
