<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class AnalyticsPrediction extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    public $timestamps=true;
    protected function casts(): array { return ['value'=>'array','actual_value'=>'array','evaluation'=>'array','generated_at'=>'datetime','valid_until'=>'datetime','evaluated_at'=>'datetime']; }
    
}

