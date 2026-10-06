<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class AutomationExecution extends Model {
    use BelongsToCompany;
    protected $guarded = ['id'];
    protected $attributes = ['status'=>'queued','attempts'=>0,'depth'=>0];
    protected function casts(): array { return ['context'=>'array','conditions_evaluated'=>'array','results'=>'array','next_retry_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime']; }
}
