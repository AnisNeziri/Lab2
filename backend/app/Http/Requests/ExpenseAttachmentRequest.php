<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseAttachmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']];
    }
}
