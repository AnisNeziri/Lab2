<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:sync-expiry-alerts')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Artisan::command('documents:expiry-alerts',function(){ $this->info(app(\App\Services\DocumentMaintenanceService::class)->expiryAlerts().' notifications created.'); });
Schedule::command('documents:expiry-alerts')->dailyAt('07:00')->withoutOverlapping();
Artisan::command('documents:setup', function () {
    $slugs = ['view','upload','update_metadata','new_version','archive','download','review','manage','confidential'];
    $permissions = collect($slugs)->mapWithKeys(function ($slug) {
        $permission = \App\Models\Permission::firstOrCreate(['slug'=>'documents.'.$slug], ['name'=>'Documents '.str_replace('_',' ',$slug),'group'=>'documents']);
        return [$slug => $permission->id];
    });
    foreach (['admin','manager','staff'] as $slug) {
        $role = \App\Models\Role::where('slug',$slug)->first();
        if (!$role) continue;
        $ids = $slug === 'staff' ? $permissions->only(['view','upload','download','update_metadata']) : $permissions;
        $role->permissions()->syncWithoutDetaching($ids->values()->all());
        \Illuminate\Support\Facades\Cache::forget('role_permissions:'.$slug);
    }
    $this->info('Document permissions added; existing permissions preserved.');
})->purpose('Enable Document Center without resetting existing role permissions');
Artisan::command('documents:verify {company}',function(){ $this->line(json_encode(app(\App\Services\DocumentMaintenanceService::class)->verifyCompany((int)$this->argument('company')),JSON_PRETTY_PRINT)); });
