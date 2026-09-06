<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityInspectionResult extends Model
{
    protected $fillable = ['quality_inspection_id','quality_checklist_item_id','check_name','check_type','passed','numeric_value','text_value','minimum_value','maximum_value','tolerance','notes'];
    protected function casts(): array { return ['passed'=>'boolean','numeric_value'=>'decimal:6','minimum_value'=>'decimal:6','maximum_value'=>'decimal:6','tolerance'=>'decimal:6']; }
    public function inspection(): BelongsTo { return $this->belongsTo(QualityInspection::class, 'quality_inspection_id'); }
}
