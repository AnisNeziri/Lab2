<?php
namespace App\Services\Commercial;

final class UpdateManifest
{
    public function read(array $envelope): array
    {
        $d=app(SignedDocument::class)->verify($envelope,'update');
        validator($d,['version'=>'required|regex:/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$/D','build_id'=>'required|string|max:100',
            'commit'=>'required|regex:/^[a-f0-9]{40}$/D','migration_level'=>'required|string|max:180','minimum_php'=>'required|regex:/^\d+\.\d+\.\d+$/D',
            'minimum_version'=>'required|regex:/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$/D','released_at'=>'required|date','compatibility.api'=>'required|integer|in:1',
            'tree_sha256'=>'required|regex:/^[a-f0-9]{64}$/D','artifacts'=>'required|array|min:1|max:10',
            'artifacts.*.filename'=>'required|regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}$/D','artifacts.*.sha256'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
        return $d;
    }
}
