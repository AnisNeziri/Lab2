<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Services\FinancialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialAccountController extends Controller
{
    public function __construct(private readonly FinancialAccountService $accounts) {}

    public function index(): JsonResponse { return response()->json($this->accounts->accounts()); }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['cashbox', 'bank'])], 'name' => ['required', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'], 'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'], 'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date'], 'is_active' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json($this->accounts->createAccount($data), 201);
    }

    public function update(Request $request, FinancialAccount $financialAccount): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'], 'opening_balance' => ['sometimes', 'numeric'],
            'opening_date' => ['nullable', 'date'], 'is_active' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json($this->accounts->updateAccount($financialAccount, $data));
    }

    public function transactions(Request $request, FinancialAccount $financialAccount): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'string', 'max:30'], 'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($this->accounts->transactions($financialAccount, $filters));
    }

    public function post(Request $request, FinancialAccount $financialAccount): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['inflow', 'outflow', 'adjustment_in', 'adjustment_out', 'refund_in', 'refund_out'])],
            'amount' => ['required', 'numeric', 'gt:0'], 'transaction_date' => ['required', 'date'],
            'counterparty' => ['nullable', 'string', 'max:255'], 'reference_number' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['nullable', 'uuid'],
        ]);
        return response()->json($this->accounts->post($financialAccount, $data), 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'destination_account_id' => ['required', 'integer', 'different:source_account_id', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'], 'transfer_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        return response()->json($this->accounts->transfer($data), 201);
    }

    public function reverse(Request $request, FinancialAccountTransaction $transaction): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json($this->accounts->reverse($transaction, $data['reason']));
    }
}
