<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ShipmentIntelligence extends Model {
    use BelongsToCompany;
    protected $table = 'shipment_intelligence';
    protected $guarded = ['id'];
    protected function casts(): array {
        return ['is_current'=>'boolean','eta'=>'array','evidence'=>'array','impact'=>'array',
            'alternatives'=>'array','outcome'=>'array','generated_at'=>'datetime','evidence_cutoff'=>'datetime','checked_at'=>'datetime'];
    }
    protected static function booted(): void {
        static::updating(function ($row) {
            foreach (['company_id','shipment_id','version','fingerprint','risk','confidence','eta','evidence','impact','alternatives','generated_at','evidence_cutoff'] as $field) {
                if ($row->isDirty($field)) throw new \LogicException('Shipment predictions and evidence are frozen. Create a new version.');
            }
        });
    }
    public function shipment() { return $this->belongsTo(Shipment::class); }
}
