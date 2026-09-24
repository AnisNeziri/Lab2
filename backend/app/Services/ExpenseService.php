<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\InvoiceProfile;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(private readonly FinancialAccountService $accounts, private readonly AccountingService $accounting) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $query = Expense::query()
            ->withSum(['payments as payments_sum_amount' => fn ($q) => $q->where('status', 'completed')], 'amount')
            ->withSum('purchaseOrderPaymentAllocations', 'amount')
            ->withSum('supplierCredits as supplier_credits_sum_gross_amount', 'gross_amount')
            ->with(['payments', 'creator:id,name']);
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('vendor_name', 'like', "%{$search}%")
                ->orWhere('document_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }
        foreach (['status', 'category', 'document_type', 'source_type', 'asset_treatment'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (array_key_exists('input_vat_eligible', $filters)) {
            $query->where('input_vat_eligible', (bool) $filters['input_vat_eligible']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('invoice_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('invoice_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['payment_status'])) {
            $directPaid = "(select coalesce(sum(ep.amount), 0) from expense_payments ep where ep.expense_id = expenses.id and ep.company_id = expenses.company_id and ep.status = 'completed')";
            $advancePaid = "(select coalesce(sum(sipa.amount), 0) from supplier_invoice_payment_allocations sipa where sipa.expense_id = expenses.id and sipa.company_id = expenses.company_id)";
            $creditPaid = "(select coalesce(sum(sc.gross_amount), 0) from expenses sc where sc.original_expense_id = expenses.id and sc.company_id = expenses.company_id and sc.document_type = 'credit_note' and sc.status = 'posted' and sc.deleted_at is null)";
            $paid = "({$directPaid} + {$advancePaid} + {$creditPaid})";
            match ($filters['payment_status']) {
                'unpaid' => $query->where('document_type', '!=', 'credit_note')->where('status', '!=', 'reversed')->whereRaw("{$paid} = 0"),
                'partially_paid' => $query->where('document_type', '!=', 'credit_note')->where('status', '!=', 'reversed')->whereRaw("{$paid} > 0 and {$paid} < expenses.gross_amount"),
                'paid' => $query->where('document_type', '!=', 'credit_note')->where('status', '!=', 'reversed')->whereRaw("{$paid} >= expenses.gross_amount"),
                'not_applicable' => $query->where(fn ($notApplicable) => $notApplicable
                    ->where('document_type', 'credit_note')->orWhere('status', 'reversed')),
                default => null,
            };
        }

        $page = $query->latest('invoice_date')->latest('id')->paginate($filters['per_page'] ?? 20);
        $page->getCollection()->transform(fn (Expense $expense) => $this->decorate($expense));

        return $page;
    }

    public function find(Expense $expense): Expense
    {
        return $this->decorate($expense->load([
            'payments' => fn ($q) => $q->with(['creator:id,name', 'reversedBy:id,name'])->orderBy('payment_date')->orderBy('id'),
            'purchaseOrderPaymentAllocations.payment', 'supplierCredits', 'creator:id,name',
        ]));
    }

    public function create(array $data): Expense
    {
        return DB::transaction(function () use ($data) {
            try {
                $expense = Expense::create([
                    ...$this->prepare($data),
                    'company_id' => Auth::user()->company_id,
                    'status' => 'draft',
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            } catch (QueryException $exception) {
                $this->throwDuplicateDocument($exception);
                throw $exception;
            }

            return $this->find($expense);
        });
    }

    public function update(Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $this->ensureDraft($expense);
            $prepared = $this->prepare($data);
            $settled = Money::add(
                $expense->payments()->where('status', 'completed')->sum('amount'),
                $expense->purchaseOrderPaymentAllocations()->sum('amount'),
                $expense->supplierCredits()->sum('gross_amount'),
            );
            if (Money::compare($prepared['gross_amount'], $settled) < 0) {
                throw ValidationException::withMessages([
                    'gross_amount' => ['The expense total cannot be lower than payments, allocated advances, and posted supplier credits.'],
                ]);
            }
            try {
                $expense->update([...$prepared, 'updated_by' => Auth::id()]);
            } catch (QueryException $exception) {
                $this->throwDuplicateDocument($exception);
                throw $exception;
            }

            return $this->find($expense->fresh());
        });
    }

    public function deleteDraft(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $this->ensureDraft($expense);
            if ($expense->payments()->exists() || $expense->purchaseOrderPaymentAllocations()->exists()) {
                throw ValidationException::withMessages(['expense' => ['A draft with payments cannot be deleted.']]);
            }
            $expense->update([
                'vendor_key' => hash('sha256', $expense->vendor_key.'#deleted#'.$expense->id),
                'document_number_normalized' => $expense->document_number_normalized.'#deleted#'.$expense->id,
                'updated_by' => Auth::id(),
            ]);
            $expense->delete();
        });
    }

    public function post(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status === 'posted') {
                return $this->find($expense);
            }
            $this->ensureDraft($expense);
            if ($expense->document_type === 'credit_note' && blank($expense->original_document_number)) {
                throw ValidationException::withMessages(['original_document_number' => ['A credit note must reference the original vendor document.']]);
            }
            $taxPoint = collect([$expense->invoice_date, $expense->received_date, $expense->supply_date])
                ->filter()->map(fn ($value) => CarbonImmutable::parse($value))->sortDesc()->first();
            $expense->update([
                'status' => 'posted',
                // Input VAT cannot be claimed before it is both chargeable and
                // supported by a received supplier/customs document.
                'vat_period' => $taxPoint->format('Y-m'),
                'posted_at' => now(),
                'posted_by' => Auth::id(),
                'retention_until' => CarbonImmutable::create($expense->invoice_date->year + 10, 12, 31)->toDateString(),
                'updated_by' => Auth::id(),
            ]);
            $this->accounting->postExpense($expense->fresh());

            return $this->find($expense->fresh());
        });
    }

    public function reverse(Expense $expense, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $reason) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status === 'reversed') {
                return $this->find($expense);
            }
            if ($expense->status !== 'posted') {
                throw ValidationException::withMessages(['expense' => ['Only a posted expense can be reversed.']]);
            }
            if ($expense->payments()->where('status', 'completed')->exists()
                || $expense->purchaseOrderPaymentAllocations()->exists()
                || $expense->supplierCredits()->exists()) {
                throw ValidationException::withMessages(['expense' => ['Reverse active payments before reversing this expense.']]);
            }
            $expense->update([
                'status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => Auth::id(),
                'reversal_reason' => trim($reason), 'updated_by' => Auth::id(),
            ]);
            $this->accounting->reverseSource('supplier_accounting', 'expense:'.$expense->id, now()->toDateString(), $reason);

            return $this->find($expense->fresh());
        });
    }

    public function recordPayment(Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status !== 'posted') {
                throw ValidationException::withMessages(['expense' => ['Payments can be recorded only for posted expenses.']]);
            }
            if ($expense->document_type === 'credit_note') {
                throw ValidationException::withMessages(['expense' => ['A vendor credit note reduces the payable and cannot receive an outgoing payment.']]);
            }
            $paymentRate = Money::normalizeDecimal($expense->currency === 'EUR' ? '1' : ($data['exchange_rate'] ?? '0'), 6);
            if ($expense->currency !== 'EUR' && (Money::compareDecimal($paymentRate, '0') <= 0 || empty($data['exchange_rate_date']) || blank($data['exchange_rate_source'] ?? null))) {
                throw ValidationException::withMessages(['exchange_rate' => ['A foreign-currency payment requires its payment-date EUR rate, date and source.']]);
            }
            if (! empty($data['idempotency_key'])) {
                $existing = ExpensePayment::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    if ($existing->status !== 'completed'
                        || (int) $existing->expense_id !== (int) $expense->id
                        || Money::compare($existing->amount, $data['amount']) !== 0
                        || $existing->payment_date?->toDateString() !== (string) $data['payment_date']
                        || $existing->payment_method !== $data['payment_method']
                        || ($existing->reference_number ?? '') !== ($data['reference_number'] ?? '')
                        || ($existing->note ?? '') !== ($data['note'] ?? '')
                        || Money::compareDecimal($existing->exchange_rate, $paymentRate) !== 0
                        || ($existing->exchange_rate_date?->toDateString() ?? '') !== ($data['exchange_rate_date'] ?? '')
                        || ($existing->exchange_rate_source ?? '') !== ($data['exchange_rate_source'] ?? '')
                        || (int) ($existing->financial_account_id ?? 0) !== (int) ($data['financial_account_id'] ?? 0)) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This payment key is already used with different payment details.']]);
                    }

                    return $this->find($expense);
                }
            }
            $paid = Money::add(
                $expense->payments()->where('status', 'completed')->sum('amount'),
                $expense->purchaseOrderPaymentAllocations()->sum('amount'),
                $expense->supplierCredits()->sum('gross_amount'),
            );
            $remaining = Money::maximum('0.00', Money::subtract($expense->gross_amount, $paid));
            $amount = Money::normalize($data['amount']);
            if (Money::compare($amount, '0.00') <= 0) {
                throw ValidationException::withMessages(['amount' => ['Payment amount must be at least 0.01 after currency rounding.']]);
            }
            if (Money::compare($amount, $remaining) > 0) {
                throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the remaining expense balance.']]);
            }
            $payment = ExpensePayment::create([
                'company_id' => $expense->company_id, 'expense_id' => $expense->id,
                'amount' => $amount, 'amount_eur' => Money::multiply($amount, $paymentRate),
                'exchange_rate' => $paymentRate,
                'exchange_rate_date' => $expense->currency === 'EUR' ? null : $data['exchange_rate_date'],
                'exchange_rate_source' => $expense->currency === 'EUR' ? null : trim($data['exchange_rate_source']),
                'status' => 'completed',
                'payment_date' => $data['payment_date'], 'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
                'financial_account_id' => $data['financial_account_id'] ?? null,
            ]);

            if (! empty($data['financial_account_id'])) {
                $ledger = $this->accounts->post((int) $data['financial_account_id'], [
                    'type' => 'outflow', 'amount' => Money::multiply($amount, $paymentRate),
                    'transaction_date' => $data['payment_date'], 'source_type' => 'expense_payment',
                    'source_id' => $payment->id, 'counterparty' => $expense->vendor_name,
                    'reference_number' => $data['reference_number'] ?? null,
                    'description' => 'Supplier/expense payment '.$expense->document_number,
                    'idempotency_key' => ($data['idempotency_key'] ?? $payment->idempotency_key).'-ledger',
                ]);
                $payment->update(['financial_account_transaction_id' => $ledger->id]);
            }
            $this->accounting->postExpensePayment($payment->fresh('expense'));

            return $this->find($expense->fresh());
        });
    }

    public function reversePayment(ExpensePayment $payment, string $reason): Expense
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = ExpensePayment::query()->lockForUpdate()->findOrFail($payment->id);
            $expense = Expense::query()->lockForUpdate()->findOrFail($payment->expense_id);
            abort_unless((int) $payment->company_id === (int) $expense->company_id, 404);
            if ($payment->status !== 'completed' || $payment->reversed_at) {
                throw ValidationException::withMessages(['payment' => ['Only an active payment can be reversed.']]);
            }
            $payment->update([
                'status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => Auth::id(),
                'reversal_reason' => trim($reason),
            ]);
            if ($payment->financial_account_transaction_id) {
                $ledger = \App\Models\FinancialAccountTransaction::query()->find($payment->financial_account_transaction_id);
                if ($ledger && $ledger->status === 'posted') {
                    $this->accounts->reverse($ledger, 'Expense payment reversal: '.trim($reason));
                }
            }
            $this->accounting->reverseSource('supplier_accounting', 'expense-payment:'.$payment->id, now()->toDateString(), $reason);

            return $this->find($expense->fresh());
        });
    }

    public function storeAttachment(Expense $expense, UploadedFile $file): Expense
    {
        $data = $file->get();
        $mime = $file->getMimeType();
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['proof' => ['The uploaded proof must be a genuine PDF, JPG or PNG file.']]);
        }
        $filename = preg_replace('/[\x00-\x1F\x7F]+/', '', basename($file->getClientOriginalName()));
        $filename = substr($filename ?: 'expense-proof', 0, 200);
        return DB::transaction(function () use ($expense, $file, $data, $mime, $filename) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status === 'reversed' || ($expense->status === 'posted' && $expense->has_attachment)) {
                throw ValidationException::withMessages(['proof' => ['Posted proof is immutable; reverse the record instead of replacing it.']]);
            }
            $version=app(DocumentEvidenceService::class)->store($file,'Payment Evidence',[['expense',$expense->id]]);
            $expense->update([
                'proof_filename' => $filename, 'proof_mime' => $mime,
                'proof_size' => strlen($data), 'proof_sha256' => hash('sha256', $data),
                'proof_data' => null, 'document_version_id'=>$version->id, 'updated_by' => Auth::id(),
            ]);

            return $this->find($expense->fresh());
        });
    }

    public function deleteAttachment(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $this->ensureDraft($expense);
            $expense->update([
                'proof_filename' => null, 'proof_mime' => null, 'proof_size' => null,
                'proof_sha256' => null, 'proof_data' => null, 'document_version_id'=>null, 'updated_by' => Auth::id(),
            ]);

            return $this->find($expense->fresh());
        });
    }

    private function prepare(array $data): array
    {
        $currency = strtoupper($data['currency'] ?? 'EUR');
        $rate = $currency === 'EUR' ? 1.0 : round((float) ($data['exchange_rate'] ?? 0), 6);
        if ($currency !== 'EUR' && ($rate <= 0 || empty($data['exchange_rate_date']) || blank($data['exchange_rate_source'] ?? null))) {
            throw ValidationException::withMessages(['exchange_rate' => ['Non-EUR expenses require a positive EUR conversion rate, rate date and source.']]);
        }
        $net = round((float) $data['net_amount'], 2);
        $vat = round((float) $data['vat_amount'], 2);
        $vatRate = (float) $data['vat_rate'];
        $treatment = $data['vat_treatment'];
        if (($treatment === 'standard' && $vatRate !== 18.0) || ($treatment === 'reduced' && $vatRate !== 8.0)) {
            throw ValidationException::withMessages(['vat_rate' => ['Standard VAT uses 18%; reduced VAT uses 8%.']]);
        }
        if (in_array($treatment, ['exempt', 'non_vat'], true) && ($vatRate !== 0.0 || $vat !== 0.0)) {
            throw ValidationException::withMessages(['vat_amount' => ['This VAT treatment requires a zero rate and zero VAT amount.']]);
        }
        if ($treatment === 'reverse_charge' && ! in_array($vatRate, [8.0, 18.0], true)) {
            throw ValidationException::withMessages(['vat_rate' => ['Reverse charge requires the applicable 18% or 8% self-assessment rate.']]);
        }
        if ($treatment === 'reverse_charge' && $vat !== 0.0) {
            throw ValidationException::withMessages(['vat_amount' => ['Supplier VAT must be zero for reverse charge; use the self-assessed VAT field.']]);
        }
        if (in_array($treatment, ['standard', 'reduced', 'import_vat'], true)) {
            $expected = round($net * $vatRate / 100, 2);
            if (abs($vat - $expected) > 0.02) {
                throw ValidationException::withMessages(['vat_amount' => ["VAT amount must match the {$vatRate}% rate (expected EUR/original {$expected})."]]);
            }
        }
        if (in_array($treatment, ['exempt', 'reverse_charge'], true) && blank($data['tax_legal_reference'] ?? null)) {
            throw ValidationException::withMessages(['tax_legal_reference' => ['Enter the legal VAT basis for this treatment.']]);
        }
        $selfAssessed = $treatment === 'reverse_charge' ? round($net * $vatRate / 100, 2) : 0.0;
        if ($treatment === 'reverse_charge' && isset($data['self_assessed_vat_amount'])
            && abs((float) $data['self_assessed_vat_amount'] - $selfAssessed) > 0.02) {
            throw ValidationException::withMessages(['self_assessed_vat_amount' => ["Self-assessed VAT must match the {$vatRate}% rate (expected {$selfAssessed})."]]);
        }
        $claimableVat = $treatment === 'reverse_charge' ? $selfAssessed : $vat;
        $eligible = (bool) $data['input_vat_eligible'];
        $deductible = $eligible ? round((float) ($data['deductible_vat_amount'] ?? $claimableVat), 2) : 0.0;
        if ($deductible > $claimableVat + 0.001) {
            throw ValidationException::withMessages(['deductible_vat_amount' => ['Deductible input VAT cannot exceed document VAT.']]);
        }
        $nonDeductible = round($claimableVat - $deductible, 2);
        $gross = round($net + $vat, 2);
        $maximum = 9_000_000_000_000;
        if (max($net * $rate, $vat * $rate, $selfAssessed * $rate, $gross * $rate, $deductible * $rate, $nonDeductible * $rate) > $maximum) {
            throw ValidationException::withMessages(['exchange_rate' => ['Converted EUR totals exceed the supported accounting limit.']]);
        }
        if ($eligible && $vat > 0 && ($data['source_type'] ?? 'domestic') === 'domestic' && blank($data['vendor_vat_number'] ?? null)) {
            throw ValidationException::withMessages(['vendor_vat_number' => ['Eligible domestic input VAT requires the supplier VAT number.']]);
        }
        $profile = InvoiceProfile::query()->first();
        if ($eligible && $claimableVat > 0 && ! $profile?->is_vat_registered) {
            throw ValidationException::withMessages(['input_vat_eligible' => ['Only a VAT-registered company can claim deductible input VAT.']]);
        }
        if (($data['document_type'] ?? null) === 'credit_note' && blank($data['original_document_number'] ?? null)) {
            throw ValidationException::withMessages(['original_document_number' => ['A vendor credit note must reference the original document.']]);
        }
        if ($treatment === 'import_vat' && (($data['source_type'] ?? null) !== 'import' || ($data['document_type'] ?? null) !== 'customs_document')) {
            throw ValidationException::withMessages(['vat_treatment' => ['Import VAT requires an import customs document.']]);
        }
        $vendorKey = $this->vendorKey($data);

        return [
            ...$data,
            'currency' => $currency, 'exchange_rate' => $rate,
            'exchange_rate_date' => $currency === 'EUR' ? null : $data['exchange_rate_date'],
            'exchange_rate_source' => $currency === 'EUR' ? null : trim($data['exchange_rate_source']),
            'vendor_name' => trim($data['vendor_name']), 'vendor_key' => $vendorKey,
            'document_number' => trim($data['document_number']),
            'document_number_normalized' => $this->normalizeDocumentNumber($data['document_number']),
            'net_amount' => $net, 'vat_rate' => $vatRate, 'vat_amount' => $vat,
            'self_assessed_vat_amount' => $selfAssessed,
            'gross_amount' => $gross, 'deductible_vat_amount' => $deductible,
            'non_deductible_vat_amount' => $nonDeductible,
            'net_amount_eur' => round($net * $rate, 2), 'vat_amount_eur' => round($vat * $rate, 2),
            'self_assessed_vat_amount_eur' => round($selfAssessed * $rate, 2),
            'gross_amount_eur' => round($gross * $rate, 2),
            'deductible_vat_amount_eur' => round($deductible * $rate, 2),
            'non_deductible_vat_amount_eur' => round($nonDeductible * $rate, 2),
        ];
    }

    private function vendorKey(array $data): string
    {
        foreach (['vendor_vat_number', 'vendor_fiscal_number', 'vendor_business_number'] as $field) {
            if (filled($data[$field] ?? null)) {
                return hash('sha256', $field.':'.strtoupper(preg_replace('/\s+/', '', $data[$field])));
            }
        }

        return hash('sha256', 'name:'.strtolower(preg_replace('/\s+/', ' ', trim($data['vendor_name']))));
    }

    private function normalizeDocumentNumber(string $number): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', trim($number)));
    }

    private function ensureDraft(Expense $expense): void
    {
        if ($expense->status !== 'draft') {
            throw ValidationException::withMessages(['expense' => ['Only a draft expense can be edited or deleted.']]);
        }
    }

    private function decorate(Expense $expense): Expense
    {
        $hasAllocatedAdvance = $this->hasAllocatedAdvance($expense);
        $expense->setAttribute('can_edit', $expense->status === 'draft');
        $expense->setAttribute('can_delete', $expense->status === 'draft'
            && $expense->payments->isEmpty()
            && ! $hasAllocatedAdvance);
        $expense->setAttribute('can_post', $expense->status === 'draft');
        $expense->setAttribute('can_reverse', $expense->status === 'posted'
            && $expense->payments->where('status', 'completed')->isEmpty()
            && ! $hasAllocatedAdvance
            && ($expense->relationLoaded('supplierCredits')
                ? $expense->supplierCredits->isEmpty()
                : ! $expense->supplierCredits()->exists()));
        $expense->setAttribute('can_pay', $expense->status === 'posted'
            && $expense->document_type !== 'credit_note'
            && $expense->remaining_amount > 0);
        $warnings = [];
        if (blank($expense->vendor_business_number) && blank($expense->vendor_fiscal_number) && blank($expense->vendor_vat_number)) {
            $warnings[] = 'missing_supplier_identifier';
        }
        if (! $expense->has_attachment) {
            $warnings[] = 'missing_supporting_document';
        }
        if ($expense->document_type === 'credit_note' && blank($expense->original_document_number)) {
            $warnings[] = 'missing_original_document_reference';
        }
        $expense->setAttribute('data_quality_warnings', $warnings);

        return $expense;
    }

    private function hasAllocatedAdvance(Expense $expense): bool
    {
        if ($expense->relationLoaded('purchaseOrderPaymentAllocations')) {
            return $expense->purchaseOrderPaymentAllocations->isNotEmpty();
        }
        if (array_key_exists('purchase_order_payment_allocations_sum_amount', $expense->getAttributes())) {
            return (float) $expense->getAttribute('purchase_order_payment_allocations_sum_amount') > 0;
        }

        return $expense->purchaseOrderPaymentAllocations()->exists();
    }

    private function throwDuplicateDocument(QueryException $exception): void
    {
        if (str_contains(strtolower($exception->getMessage()), 'unique')) {
            throw ValidationException::withMessages([
                'document_number' => ['This vendor document number is already recorded for the same supplier.'],
            ]);
        }
    }
}
