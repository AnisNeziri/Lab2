<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['bank_account', 'personal_number'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'termination_date' => 'date', 'bank_account' => 'encrypted', 'personal_number' => 'encrypted'];
    }

    public function terms()
    {
        return $this->hasMany(EmploymentTerm::class)->orderBy('effective_from');
    }

    public function records()
    {
        return $this->hasMany(WorkforceRecord::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Archive an employee; employment history cannot be deleted.'));
    }
}
