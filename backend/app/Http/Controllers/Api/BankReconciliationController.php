<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankStatement;
use App\Models\BankStatementRow;
use App\Models\FinancialAccount;
use App\Services\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $reconciliation) {}

    public function index(): JsonResponse
    {
        return response()->json($this->reconciliation->statements());
    }

    public function show(BankStatement $bankStatement): JsonResponse
    {
        return response()->json($this->reconciliation->show($bankStatement));
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'column_mapping' => ['required'], 'format_options' => ['nullable', 'array'], 'page' => 'nullable|integer|min:1', 'delimiter' => ['nullable', Rule::in(['auto', ',', ';', 'tab'])],
        ]);
        $mapping = is_string($data['column_mapping']) ? json_decode($data['column_mapping'], true) : $data['column_mapping'];
        if (! is_array($mapping)) {
            abort(422, 'Column mapping must be valid JSON.');
        }
        $delimiter = ($data['delimiter'] ?? 'auto') === 'tab' ? "\t" : ($data['delimiter'] ?? 'auto');

        return response()->json($this->reconciliation->import(
            FinancialAccount::findOrFail($data['financial_account_id']), $request->file('file'), $mapping, $delimiter, $data['format_options'] ?? [],
        ), 201);
    }

    public function suggestions(BankStatementRow $row): JsonResponse
    {
        return response()->json($this->reconciliation->suggestions($row));
    }

    public function reconcile(Request $request, BankStatementRow $row): JsonResponse
    {
        $data = $request->validate(['transaction_id' => 'required_without:transaction_ids|integer|min:1', 'transaction_ids' => 'required_without:transaction_id|array|min:1|max:100', 'transaction_ids.*' => 'integer|min:1|distinct']);

        return response()->json($this->reconciliation->reconcileMany($row, $data['transaction_ids'] ?? [$data['transaction_id']]));
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

    public function balances(Request $request, BankStatement $bankStatement): JsonResponse
    {
        $d = $request->validate(['opening_balance' => 'required|numeric|decimal:0,2|between:-9000000000000,9000000000000', 'closing_balance' => 'required|numeric|decimal:0,2|between:-9000000000000,9000000000000', 'period_from' => 'required|date', 'period_to' => 'required|date|after_or_equal:period_from', 'reason' => 'required|string|min:10|max:1000']);

        return response()->json($this->reconciliation->setBalances($bankStatement, $d));
    }

    public function complete(BankStatement $bankStatement): JsonResponse
    {
        return response()->json($this->reconciliation->complete($bankStatement));
    }

    public function reopen(Request $request, BankStatement $bankStatement): JsonResponse
    {
        $d = $request->validate(['reason' => 'required|string|min:10|max:1000']);

        return response()->json($this->reconciliation->reopen($bankStatement, $d['reason']));
    }

    public function reviewDuplicate(Request $request, BankStatementRow $row): JsonResponse
    {
        $d = $request->validate(['reason' => 'required|string|min:10|max:1000']);

        return response()->json($this->reconciliation->reviewDuplicate($row, $d['reason']));
    }
}
