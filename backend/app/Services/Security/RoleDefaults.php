<?php
namespace App\Services\Security;

use App\Models\Permission;
use App\Models\Role;

/** New templates only. Existing and company-specific grants are never resynchronised here. */
final class RoleDefaults
{
    public static function install(): void
    {
        $templates=[
            'sales'=>['dashboard.view','customers.manage','debts.view','invoices.manage','fulfillment.view','fulfillment.manage','inventory.view','shipments.view','documents.view','documents.download','tasks.view','tasks.claim','tasks.complete'],
            'purchasing'=>['dashboard.view','procurement.view','procurement.manage','purchase_orders.view','purchase_orders.manage','supplier_catalogue.view','supplier_catalogue.manage','replenishment.view','replenishment.manage','inventory.view','shipments.view','documents.view','documents.download','tasks.view','tasks.claim','tasks.complete'],
            'warehouse'=>['dashboard.view','inventory.view','inventory.counts.manage','warehouse_mobile.use','transfers.view','transfers.manage','transfers.dispatch','transfers.receive','fulfillment.view','fulfillment.pick','fulfillment.dispatch','quality.view','documents.view','documents.download','tasks.view','tasks.claim','tasks.complete'],
            'read_only'=>['dashboard.view','inventory.view','purchase_orders.view','shipments.view','documents.view','documents.download','reports.view'],
        ];
        foreach($templates as $slug=>$permissions) {
            $role=Role::firstOrCreate(['slug'=>$slug],['name'=>ucwords(str_replace('_',' ',$slug)),'description'=>'Default company role template']);
            if ($role->wasRecentlyCreated) $role->permissions()->sync(Permission::whereIn('slug',$permissions)->pluck('id'));
        }
    }
}
