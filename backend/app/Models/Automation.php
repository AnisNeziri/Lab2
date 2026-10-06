<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class Automation extends Model {
    use BelongsToCompany;
    protected $guarded = ['id'];
    protected $attributes = ['version'=>1,'enabled'=>false,'run_count'=>0,'failure_count'=>0,'event_cursor'=>0];
    protected function casts(): array { return ['enabled'=>'boolean','conditions'=>'array','actions'=>'array','schedule'=>'array','last_run_at'=>'datetime']; }
}
