<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityInspectionTemplate extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $fillable = ['company_id', 'name', 'description', 'is_active', 'created_by', 'updated_by'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function items(): HasMany { return $this->hasMany(QualityChecklistItem::class)->orderBy('sort_order')->orderBy('id'); }
}
