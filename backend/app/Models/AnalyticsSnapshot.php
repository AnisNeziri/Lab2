<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class AnalyticsSnapshot extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    public $timestamps=true;
    protected function casts(): array { return ['facts'=>'array','snapshot_date'=>'date','observed_at'=>'datetime']; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Analytical observations and datasets are immutable. Create a new version.'));
        static::deleting(fn()=>throw new \LogicException('Historical analytical observations cannot be deleted.'));
    }
}
