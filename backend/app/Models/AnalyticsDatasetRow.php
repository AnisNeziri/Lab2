<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class AnalyticsDatasetRow extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    public $timestamps=false;
    protected function casts(): array { return ['values'=>'array']; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Analytical observations and datasets are immutable. Create a new version.'));
        static::deleting(fn()=>throw new \LogicException('Versioned dataset rows cannot be deleted.'));
    }
}
