<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryCountRecountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
