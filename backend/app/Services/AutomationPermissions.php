<?php
namespace App\Services;
use App\Models\{Permission, Role};
use Illuminate\Support\Facades\Cache;
class AutomationPermissions {
    public static function install(bool $preserveExisting = false, array $newRoleSlugs = []): void {
        foreach (['automations'=>['view','create','edit','enable','disable','test','executions.view'],'tasks'=>['view','create','assign','complete','manage']] as $group=>$names) {
            foreach ($names as $name) {
                $p=Permission::firstOrCreate(['slug'=>$group.'.'.$name],['name'=>ucfirst($group).' '.str_replace('.',' ',$name),'group'=>$group]);
                foreach (['admin','manager','superadmin','staff'] as $slug) {
                    if ($preserveExisting && ! $p->wasRecentlyCreated && !in_array($slug, $newRoleSlugs, true)) continue;
                    if ($slug==='staff' && !in_array($p->slug,['tasks.view','tasks.complete'],true)) continue;
                    if ($role=Role::where('slug',$slug)->first()) $role->permissions()->syncWithoutDetaching([$p->id]);
                    Cache::forget('role_permissions:'.$slug);
                }
            }
        }
    }
}
