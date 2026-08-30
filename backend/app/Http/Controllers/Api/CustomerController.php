<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerDebtEntryRequest;
use App\Http\Requests\CustomerDebtPaymentRequest;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\ReverseDebtTransactionRequest;
use App\Http\Requests\UpdateDebtTransactionRequest;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Services\CustomerDebtService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerDebtService $debts) {}

    public function index(Request $request): JsonResponse
    {
        $businessDate = now('Europe/Tirane')->toDateString();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:all,active,paid,credit,overdue'],
            'min_debt' => ['nullable', 'numeric', 'min:0'],
            'max_debt' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:debt_desc,debt_asc,recent'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Customer::query()
            ->withCount('debtTransactions')
            ->withExists([
                'debtTransactions as has_overdue_debt' => fn ($transaction) => $transaction
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', $businessDate),
            ])
            ->withMax('debtTransactions', 'transaction_date')
            ->withMax([
                'debtTransactions as last_payment_at' => fn ($query) => $query->where('type', 'payment'),
            ], 'transaction_date');

        if ($search = $validated['search'] ?? null) {
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('business_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        match ($validated['status'] ?? 'all') {
            'active' => $query->where('current_debt', '>', 0),
            'paid' => $query->where('current_debt', 0),
            'credit' => $query->where('current_credit', '>', 0),
            'overdue' => $query->where('current_debt', '>', 0)->whereHas(
                'debtTransactions',
                fn ($transaction) => $transaction->whereNotNull('due_date')->whereDate('due_date', '<', $businessDate)
            ),
            default => null,
        };

        if (isset($validated['min_debt'])) {
            $query->where('current_debt', '>=', $validated['min_debt']);
        }
        if (isset($validated['max_debt'])) {
            $query->where('current_debt', '<=', $validated['max_debt']);
        }

        match ($validated['sort'] ?? 'debt_desc') {
            'debt_asc' => $query->orderBy('current_debt'),
            'recent' => $query->orderByDesc('debt_transactions_max_transaction_date'),
            default => $query->orderByDesc('current_debt'),
        };

        return response()->json($query->paginate($validated['per_page'] ?? 20));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        return response()->json(Customer::create($request->validated()), 201);
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return response()->json($customer->fresh());
    }

    public function destroy(Customer $customer): JsonResponse
    {
        if (Money::compare($customer->current_debt, '0.00') > 0
            || Money::compare($customer->current_credit, '0.00') > 0) {
            return response()->json([
                'message' => 'A customer with an outstanding debt or credit advance cannot be deleted. Settle the ledger first.',
            ], 422);
        }
        $customer->delete();

        return response()->json(['message' => 'Debt sheet deleted.']);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load([
            'debtTransactions' => fn ($query) => $query
                ->with('user:id,name')
                ->withCount('reversals')
                ->latest('id'),
        ]);

        $transactions = $customer->debtTransactions;
        $customer->setAttribute('ledger_summary', [
            'total_added' => (float) $transactions->whereIn('type', ['debt_added', 'opening_balance', 'positive_adjustment'])->sum('amount'),
            'total_paid' => (float) $transactions->whereIn('type', ['payment', 'negative_adjustment', 'return', 'cancellation'])->sum('amount'),
            'credit_sales' => $transactions->whereNotNull('daily_sale_id')->count(),
            'last_payment_at' => $transactions->where('type', 'payment')->max('transaction_date')?->toDateString(),
            'last_debt_at' => $transactions->whereIn('type', ['debt_added', 'opening_balance', 'positive_adjustment'])->max('transaction_date')?->toDateString(),
        ]);

        return response()->json($customer);
    }

    public function addDebt(CustomerDebtEntryRequest $request, Customer $customer): JsonResponse
    {
        $type = $request->boolean('opening_balance') ? 'opening_balance' : 'debt_added';

        return response()->json($this->debts->addDebt($customer, $request->validated(), $type), 201);
    }

    public function payment(CustomerDebtPaymentRequest $request, Customer $customer): JsonResponse
    {
        return response()->json($this->debts->recordPayment($customer, $request->validated()), 201);
    }

    public function reverse(ReverseDebtTransactionRequest $request, CustomerDebtTransaction $transaction): JsonResponse
    {
        return response()->json($this->debts->reverse($transaction, $request->validated('reason')), 201);
    }

    public function correct(UpdateDebtTransactionRequest $request, CustomerDebtTransaction $transaction): JsonResponse
    {
        return response()->json($this->debts->correct($transaction, $request->validated()));
    }

    public function summary(): JsonResponse
    {
        $businessDate = now('Europe/Tirane')->toDateString();
        $overdue = Customer::query()
            ->where('current_debt', '>', 0)
            ->whereHas('debtTransactions', fn ($query) => $query
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $businessDate));

        return response()->json([
            'total_debt' => (float) Customer::sum('current_debt'),
            'total_customer_credit' => (float) Customer::sum('current_credit'),
            'customers_with_debt' => Customer::where('current_debt', '>', 0)->count(),
            'payments_today' => (float) CustomerDebtTransaction::where('type', 'payment')->whereDate('transaction_date', $businessDate)->sum('amount'),
            'new_debt_today' => (float) CustomerDebtTransaction::whereIn('type', ['debt_added', 'opening_balance', 'positive_adjustment'])->whereDate('transaction_date', $businessDate)->sum('amount'),
            'overdue_debt' => (float) (clone $overdue)->sum('current_debt'),
            'overdue_customers' => (clone $overdue)->count(),
        ]);
    }

    public function statement(Request $request, Customer $customer): StreamedResponse
    {
        $transactions = $customer->debtTransactions()
            ->with('user:id,name')
            ->oldest('id')
            ->get();
        $company = $request->user()->company;

        return response()->streamDownload(function () use ($company, $customer, $transactions) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Company', $company?->name, 'Address', $company?->address]);
            fputcsv($out, ['Customer', $customer->name, 'Business', $customer->business_name]);
            fputcsv($out, ['Phone', $customer->phone, 'Email', $customer->email, 'Tax number', $customer->tax_number]);
            fputcsv($out, ['Period', $transactions->first()?->transaction_date?->toDateString(), $transactions->last()?->transaction_date?->toDateString()]);
            fputcsv($out, ['Opening debt', '0.00', 'Closing debt', $customer->current_debt, 'Customer credit', $customer->current_credit]);
            fputcsv($out, []);
            fputcsv($out, ['Date', 'Type', 'Reference', 'Sale', 'Amount', 'Debt Before', 'Debt After', 'Credit Before', 'Credit After', 'Payment Method', 'Note', 'User']);
            foreach ($transactions as $transaction) {
                fputcsv($out, [
                    $transaction->transaction_date->toDateString(),
                    $transaction->type,
                    $transaction->reference_number,
                    $transaction->daily_sale_id,
                    $transaction->amount,
                    $transaction->balance_before,
                    $transaction->balance_after,
                    $transaction->credit_before,
                    $transaction->credit_after,
                    $transaction->payment_method,
                    $transaction->note,
                    $transaction->user?->name,
                ]);
            }
            fclose($out);
        }, 'debt-statement-'.$customer->id.'.csv', ['Content-Type' => 'text/csv']);
    }
}
