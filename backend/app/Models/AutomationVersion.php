<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class AutomationVersion extends Model {
    use BelongsToCompany;
    protected $guarded = ['id'];
    protected function casts(): array { return ['definition'=>'array']; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Automation versions are immutable.'));
        static::deleting(fn()=>throw new \LogicException('Automation versions are immutable.'));
    }
}
