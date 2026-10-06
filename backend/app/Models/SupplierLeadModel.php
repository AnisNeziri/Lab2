<?php
namespace App\Models;
class SupplierLeadModel extends InventoryForecastModel {
 protected $table='inventory_forecast_models';
 protected static function booted():void {
  parent::booted();static::addGlobalScope('supplier_domain',fn($q)=>$q->where('domain','supplier_lead'));
  static::creating(fn($m)=>$m->domain='supplier_lead');
 }
}
