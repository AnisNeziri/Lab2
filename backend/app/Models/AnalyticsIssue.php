<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class AnalyticsIssue extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    public $timestamps=true;
    protected function casts(): array { return ['details'=>'array','detected_at'=>'datetime','resolved_at'=>'datetime']; }
    
}

