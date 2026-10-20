<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PilotFeedback extends Model
{
    use BelongsToCompany;

    protected $table = 'pilot_feedback';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
