<?php

namespace Tests\Feature;

use App\Services\Synthetic\{SyntheticCompanyScenario, SyntheticDatabase};
use Tests\TestCase;

class SyntheticCompanyTest extends TestCase
{
    public function test_database_path_is_fixed_to_synthetic_storage_and_rejects_invalid_seeds(): void
    {
        $database = new SyntheticDatabase;
        $this->assertSame(storage_path('app/synthetic/aims-pm3-42.sqlite'), $database->path(42));
        $this->expectException(\RuntimeException::class);
        $database->path(-1);
    }

    public function test_reset_refuses_an_unrecognized_database_without_changing_its_bytes(): void
    {
        $database = new SyntheticDatabase;
        $path = $database->path(2147483601);
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $this->assertFileDoesNotExist($path);
        file_put_contents($path, 'not a synthetic database');
        $before = hash_file('sha256', $path);
        try {
            $database->prepare(2147483601, true);
            $this->fail('An unrecognized file was accepted for reset.');
        } catch (\Throwable $e) {
            $this->assertSame($before, hash_file('sha256', $path));
        } finally { unlink($path); }
    }

    public function test_seeded_business_samples_are_reproducible_and_seed_specific(): void
    {
        $one = new SyntheticCompanyScenario; $two = new SyntheticCompanyScenario;
        $one->config = $two->config = ['seed' => 20261006];
        $a = $b = [];
        for ($day = 0; $day < 90; $day++) { $a[] = $one->sample('day-'.$day, 0, 100000); $b[] = $two->sample('day-'.$day, 0, 100000); }
        $this->assertSame($a, $b);
        $two->config['seed']++;
        $this->assertNotSame($a[0], $two->sample('day-0', 0, 100000));
        $this->assertSame($one->key('order'), (function () { $x = new SyntheticCompanyScenario; $x->config = ['seed' => 20261006]; return $x->key('order'); })());
    }

    public function test_owned_database_with_a_different_seed_is_not_reset(): void
    {
        $database = new SyntheticDatabase;
        $path = $database->path(2147483602);
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $this->assertFileDoesNotExist($path);
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('create table _aims_synthetic_manifest (marker text, seed integer, status text)');
        $pdo->prepare('insert into _aims_synthetic_manifest values (?, ?, ?)')->execute([SyntheticDatabase::MARKER, 1, 'complete']);
        $pdo = null;
        $before = hash_file('sha256', $path);
        try { $database->prepare(2147483602, true); $this->fail('Mismatched seed accepted.'); }
        catch (\RuntimeException $error) { $this->assertSame($before, hash_file('sha256', $path)); }
        finally { unlink($path); }
    }
}
