<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankStatement;
use App\Models\BankStatementRow;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Services\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $reconciliation) {}

    public function index(): JsonResponse { return response()->json($this->reconciliation->statements()); }
    public function show(BankStatement $bankStatement): JsonResponse { return response()->json($this->reconciliation->show($bankStatement)); }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'column_mapping' => ['required'], 'delimiter' => ['nullable', Rule::in(['auto', ',', ';', 'tab'])],
        ]);
        $mapping = is_string($data['column_mapping']) ? json_decode($data['column_mapping'], true) : $data['column_mapping'];
        if (! is_array($mapping)) abort(422, 'Column mapping must be valid JSON.');
        $delimiter = ($data['delimiter'] ?? 'auto') === 'tab' ? "\t" : ($data['delimiter'] ?? 'auto');
        return response()->json($this->reconciliation->import(
            FinancialAccount::findOrFail($data['financial_account_id']), $request->file('file'), $mapping, $delimiter,
        ), 201);
    }

    public function suggestions(BankStatementRow $row): JsonResponse
    {
        return response()->json($this->reconciliation->suggestions($row));
    }

    public function reconcile(Request $request, BankStatementRow $row): JsonResponse
    {
        $data = $request->validate(['transaction_id' => ['required', 'integer', 'exists:financial_account_transactions,id']]);
        return response()->json($this->reconciliation->reconcile($row, FinancialAccountTransaction::findOrFail($data['transaction_id'])));
    }

    public function ignore(Request $request, BankStatementRow $row): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json($this->reconciliation->ignore($row, $data['reason']));
    }

    public function unmatch(Request $request, BankStatementRow $row): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json($this->reconciliation->unmatch($row, $data['reason']));
    }
}
