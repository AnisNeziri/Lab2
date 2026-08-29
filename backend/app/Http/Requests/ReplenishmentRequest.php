<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;

        return [
            'product_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'history_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'as_of' => ['nullable', 'date'],
            'include_ok' => ['nullable', 'boolean'],
        ];
    }
}
