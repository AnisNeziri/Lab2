<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class AnalyticsDataset extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    public $timestamps=true;
    protected function casts(): array { return ['feature_definitions'=>'array','date_from'=>'date','date_to'=>'date']; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Analytical observations and datasets are immutable. Create a new version.'));
        static::deleting(fn()=>throw new \LogicException('Versioned datasets cannot be deleted.'));
    }
}
