<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerCreditBlockedException extends ValidationException
{
    public function __construct(
        string $reason,
        public readonly array $creditControl,
    ) {
        $validator = Validator::make([], []);
        $validator->errors()->add('credit', $reason);

        parent::__construct($validator);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors(),
            'credit_control' => $this->creditControl,
        ], 422);
    }
}
