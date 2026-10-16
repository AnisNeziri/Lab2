<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PilotProfile extends Model
{
    use BelongsToCompany;

    protected $table = 'pilot_profiles';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['business_days' => 'array', 'workstreams' => 'array', 'participants' => 'array', 'source_attestation' => 'array', 'cutover_date' => 'date', 'telemetry_enabled' => 'boolean', 'revision' => 'integer'];
    }
}
