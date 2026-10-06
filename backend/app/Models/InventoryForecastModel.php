<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryForecastModel extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    protected function casts():array{return ['artifact'=>'array','metrics'=>'array','comparison'=>'array','quality'=>'array','review'=>'array','training_cutoff'=>'date'];}
    protected static function booted():void {
        static::updating(function($m){foreach(['artifact','artifact_hash','metrics','comparison','quality','review','version','training_cutoff','analytics_dataset_id','domain','supplier_id','product_id'] as $field)
            if($m->isDirty($field))throw new \LogicException('Trained model evidence is immutable. Create a candidate version.');});
    }
}
