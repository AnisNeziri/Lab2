<?php
namespace App\Services\Commercial;

/** Standard SHA-256 over sorted path/NUL/content-hash rows; covers unlisted executable additions too. */
final class ReleaseTree
{
    public function digest(string $directory): string
    {
        $root=realpath($directory);if (!$root || !is_dir($root) || is_link($directory)) throw new \RuntimeException('Invalid staged release directory.');
        $rows=[];$count=0;
        $walk=function(string $path,string $prefix='')use(&$walk,&$rows,&$count){
            foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry){
                $relative=$prefix.$entry->getFilename();
                if (in_array($relative,['artifacts','UPDATE.signed.json','backend/.env','backend/storage'],true)) continue;
                if ($entry->isLink() || preg_match('/[\x00-\x1f\\\\]/',$relative)) throw new \RuntimeException('Unsafe path in staged release.');
                if ($entry->isDir()) $walk($entry->getPathname(),$relative.'/');
                elseif($entry->isFile()) {if(++$count>100000)throw new \RuntimeException('Release inventory exceeds its supported bound.');$rows[$relative]=hash_file('sha256',$entry->getPathname());}
            }
        };
        $walk($root);ksort($rows,SORT_STRING);$hash=hash_init('sha256');
        foreach($rows as $path=>$digest)hash_update($hash,$path."\0".$digest."\n");
        return hash_final($hash);
    }
}
