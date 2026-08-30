<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $companyId = Auth::user()?->company_id;

            if ($companyId) {
                $builder->where($builder->getModel()->getTable().'.company_id', $companyId);
            }
        });

        static::creating(function ($model) {
            $companyId = Auth::user()?->company_id;
            if ($companyId) {
                // Never trust a client-supplied company_id. Background imports,
                // migrations and the company-less superadmin remain unaffected.
                $model->company_id = $companyId;
            }
        });

        static::updating(function ($model) {
            $companyId = Auth::user()?->company_id;
            if (! $companyId) {
                return;
            }
            if ((int) $model->getOriginal('company_id') !== (int) $companyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException('The record belongs to another company.');
            }
            if ($model->isDirty('company_id')) {
                $model->company_id = $companyId;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
