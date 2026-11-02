<?php
namespace App\Console\Commands;

use App\Services\Commercial\SignedDocument;
use Illuminate\Console\Command;

class VerifyRelease extends Command
{
    protected $signature='aims:verify-release {--file=} {--artifacts-root=} {--release-root=}';
    protected $description='Verify an issuer-signed update manifest and every staged artifact without executing it';
    public function handle(SignedDocument $verifier): int
    {
        try {
            $file=$this->option('file'); $root=realpath($this->option('artifacts-root')??'');
            if (!$file || !is_readable($file) || filesize($file)>131072 || !$root || !is_dir($root)) throw new \RuntimeException();
            $data=app(\App\Services\Commercial\UpdateManifest::class)->read(json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR));
            if (!version_compare($data['version'],\App\Support\Release::version(),'>') || !version_compare(\App\Support\Release::version(),$data['minimum_version'],'>=') || !version_compare(PHP_VERSION,$data['minimum_php'],'>=')) throw new \RuntimeException();
            if (!is_array($data['artifacts']??null) || count($data['artifacts'])<1 || count($data['artifacts'])>10) throw new \RuntimeException();
            foreach($data['artifacts'] as $artifact) {
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}$/D',$artifact['filename']??'') || !preg_match('/^[a-f0-9]{64}$/D',$artifact['sha256']??'')) throw new \RuntimeException();
                $path=$root.DIRECTORY_SEPARATOR.$artifact['filename'];
                if (is_link($path) || !is_file($path) || dirname(realpath($path))!==$root || !hash_equals($artifact['sha256'],hash_file('sha256',$path))) throw new \RuntimeException();
            }
            $release=$this->option('release-root');
            if (!$release || !preg_match('/^[a-f0-9]{64}$/D',$data['tree_sha256']??'') || !hash_equals($data['tree_sha256'],app(\App\Services\Commercial\ReleaseTree::class)->digest($release))) throw new \RuntimeException();
            $this->line(json_encode(['status'=>'verified','target'=>$data['version']??null,'artifacts'=>count($data['artifacts']),'executed'=>false]));return self::SUCCESS;
        } catch(\Throwable) { $this->error('Release verification failed. No artifact was executed. Check the trusted public key, signature and staged artifact checksums.');return self::FAILURE; }
    }
}
