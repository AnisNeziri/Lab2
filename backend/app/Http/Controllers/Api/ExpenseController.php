<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseAttachmentRequest;
use App\Http\Requests\ExpensePaymentRequest;
use App\Http\Requests\ExpenseRequest;
use App\Http\Requests\ExpenseReversalRequest;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,posted,reversed'],
            'category' => ['nullable', 'string', 'max:40'],
            'document_type' => ['nullable', 'in:purchase_invoice,fiscal_receipt,credit_note,customs_document,other'],
            'source_type' => ['nullable', 'in:domestic,import'],
            'asset_treatment' => ['nullable', 'in:ordinary,investment'],
            'input_vat_eligible' => ['nullable', 'boolean'],
            'payment_status' => ['nullable', 'in:unpaid,partially_paid,paid,not_applicable'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->expenses->list($filters));
    }

    public function show(Expense $expense): JsonResponse
    {
        return response()->json($this->expenses->find($expense));
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        return response()->json($this->expenses->create($request->validated()), 201);
    }

    public function update(ExpenseRequest $request, Expense $expense): JsonResponse
    {
        return response()->json($this->expenses->update($expense, $request->validated()));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->expenses->deleteDraft($expense);

        return response()->json(null, 204);
    }

    public function post(Expense $expense): JsonResponse
    {
        return response()->json($this->expenses->post($expense));
    }

    public function reverse(ExpenseReversalRequest $request, Expense $expense): JsonResponse
    {
        return response()->json($this->expenses->reverse($expense, $request->validated('reason')));
    }

    public function pay(ExpensePaymentRequest $request, Expense $expense): JsonResponse
    {
        return response()->json($this->expenses->recordPayment($expense, $request->validated()), 201);
    }

    public function reversePayment(ExpenseReversalRequest $request, ExpensePayment $expensePayment): JsonResponse
    {
        return response()->json($this->expenses->reversePayment($expensePayment, $request->validated('reason')));
    }

    public function uploadAttachment(ExpenseAttachmentRequest $request, Expense $expense): JsonResponse
    {
        return response()->json($this->expenses->storeAttachment($expense, $request->file('proof')));
    }

    public function downloadAttachment(Expense $expense)
    {
        if($expense->document_version_id)return app(\App\Services\DocumentEvidenceService::class)->download($expense->document_version_id);
        abort_unless($expense->proof_data && $expense->proof_filename, 404, 'No supporting document is stored.');
        abort_unless(hash_equals((string) $expense->proof_sha256, hash('sha256', $expense->proof_data)), 409, 'The supporting document failed its integrity check.');
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '-', $expense->proof_filename) ?: 'expense-proof';
        $utf8 = rawurlencode($expense->proof_filename);

        return response($expense->proof_data, 200, [
            'Content-Type' => $expense->proof_mime ?: 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$fallback}\"; filename*=UTF-8''{$utf8}",
            'Content-Length' => (string) strlen($expense->proof_data),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function deleteAttachment(Expense $expense): JsonResponse
    {
        return response()->json($this->expenses->deleteAttachment($expense));
    }
}
