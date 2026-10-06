<?php
namespace App\Services;
use App\Models\{Permission,Role};
use Illuminate\Support\Facades\Cache;
final class AnalyticsPermissions {
    public static function install(bool $preserveExisting = false, array $newRoleSlugs = []):void {
        foreach(['view','finance','export','data_quality','ml_datasets'] as $name){$p=Permission::firstOrCreate(['slug'=>'analytics.'.$name],['name'=>'Analytics '.str_replace('_',' ',$name),'group'=>'analytics']);
            foreach(['admin','manager'] as $slug){
                if ($preserveExisting && ! $p->wasRecentlyCreated && !in_array($slug, $newRoleSlugs, true)) continue;
                if($role=Role::where('slug',$slug)->first())$role->permissions()->syncWithoutDetaching([$p->id]);Cache::forget('role_permissions:'.$slug);
            }
        }
    }
}
