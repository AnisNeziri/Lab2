<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityChecklistItem extends Model
{
    protected $fillable = ['quality_inspection_template_id', 'name', 'check_type', 'unit', 'minimum_value', 'maximum_value', 'tolerance', 'is_required', 'sort_order', 'instructions'];
    protected function casts(): array { return ['minimum_value'=>'decimal:6','maximum_value'=>'decimal:6','tolerance'=>'decimal:6','is_required'=>'boolean']; }
    public function template(): BelongsTo { return $this->belongsTo(QualityInspectionTemplate::class, 'quality_inspection_template_id'); }
}
