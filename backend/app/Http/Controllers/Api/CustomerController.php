<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerCreditOverrideRequest;
use App\Http\Requests\CustomerDebtEntryRequest;
use App\Http\Requests\CustomerDebtPaymentRequest;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\ReverseDebtTransactionRequest;
use App\Http\Requests\UpdateDebtTransactionRequest;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Models\ApprovalRequest;
use App\Services\CustomerCreditService;
use App\Services\CustomerDebtService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerDebtService $debts, private readonly CustomerCreditService $credit) {}

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
        $data = $request->validated();
        if (($data['credit_status'] ?? $customer->credit_status) === 'blocked') {
            if ($customer->credit_status !== 'blocked') {
                $data['credit_hold_at'] = now();
                $data['credit_hold_by'] = $request->user()->id;
            }
        } elseif (array_key_exists('credit_status', $data) && $customer->credit_status === 'blocked') {
            $data['credit_hold_at'] = null;
            $data['credit_hold_by'] = null;
            $data['credit_hold_reason'] = $data['credit_hold_reason'] ?? null;
        }
        $customer->update($data);

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
        $customer->setAttribute('credit_summary', $this->credit->exposure($customer));

        return response()->json($customer);
    }

    public function addDebt(CustomerDebtEntryRequest $request, Customer $customer): JsonResponse
    {
        $type = $request->boolean('opening_balance') ? 'opening_balance' : 'debt_added';

        return response()->json($this->debts->addDebt($customer, $request->validated(), $type), 201);
    }

    public function requestCreditOverride(CustomerCreditOverrideRequest $request, Customer $customer): JsonResponse
    {
        $type = $request->boolean('opening_balance') ? 'opening_balance' : 'debt_added';
        $approval = $this->debts->requestCreditOverride($customer, $request->validated(), $type);
        $created = $approval->wasRecentlyCreated;
        $approval->setAttribute('duplicate', ! $created);

        return response()->json($approval->load('decisions'), $created ? 201 : 200);
    }

    public function creditOverrideStatus(Customer $customer, ApprovalRequest $approvalRequest): JsonResponse
    {
        abort_unless(
            $approvalRequest->entity_type === \App\Services\ApprovalService::CUSTOMER_CREDIT_OVERRIDE
                && $approvalRequest->rule_type === \App\Services\ApprovalService::CUSTOMER_CREDIT_OVERRIDE
                && (int) $approvalRequest->entity_id === (int) $customer->id,
            404,
        );

        return response()->json($approvalRequest->load('decisions'));
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

    public function creditReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'filter' => ['nullable', 'in:all,blocked,over_limit,overdue,90_plus'],
            'blocked' => ['nullable', 'boolean'],
            'over_limit' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'overdue_90_plus' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:exposure_desc,exposure_asc,utilization_desc,utilization_asc,overdue_desc,overdue_asc'],
            'sort_by' => ['nullable', 'in:customer,exposure,utilization,overdue'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach (['blocked', 'over_limit', 'overdue', 'overdue_90_plus'] as $booleanFilter) {
            if ($request->has($booleanFilter)) {
                $validated[$booleanFilter] = $request->boolean($booleanFilter);
            }
        }

        $customers = Customer::query()->orderBy('name')->get();

        $rows = $customers->map(fn (Customer $customer): array => $this->creditReportRow($customer));
        $summary = $this->creditReportSummary($rows);
        $filtered = $this->filterCreditReport($rows, $validated);
        [$sortBy, $direction] = $this->creditReportSort($validated);
        $sorted = $this->sortCreditReport($filtered, $sortBy, $direction)->values();

        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 20;
        $total = $sorted->count();

        return response()->json([
            'data' => $sorted->forPage($page, $perPage)->values(),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'summary' => $summary,
        ]);
    }

    private function creditReportRow(Customer $customer): array
    {
        $credit = $this->credit->exposure($customer);
        $isBlocked = strtolower((string) $customer->credit_status) === 'blocked';
        $isOverLimit = $credit['credit_limit'] !== null
            && Money::compare($credit['total_exposure'], $credit['credit_limit']) > 0;
        $status = $isBlocked
            ? 'BLOCKED'
            : ($isOverLimit
                ? 'OVER LIMIT'
                : (strtolower((string) $customer->credit_status) === 'warning' ? 'WARNING' : 'NORMAL'));

        return [
            'customer_id' => $customer->id,
            'customer' => $customer->name,
            'business_name' => $customer->business_name,
            'debt' => $credit['current_debt'],
            'advance' => $credit['advance'],
            'exposure' => $credit['total_exposure'],
            'overdue' => $credit['overdue'],
            'overdue_90_plus' => $credit['aging']['90_plus'],
            'credit_limit' => $credit['credit_limit'],
            'available_credit' => $credit['available_credit'],
            'utilization_percent' => $credit['utilization_percent'],
            'credit_status' => $customer->credit_status,
            'status' => $status,
            'oldest_overdue_date' => $credit['oldest_overdue_date'],
            'aging' => $credit['aging'],
        ];
    }

    private function creditReportSummary(Collection $rows): array
    {
        return [
            'total_receivables' => $rows->reduce(
                fn (string $total, array $row): string => Money::add($total, $row['debt']),
                '0.00'
            ),
            'total_overdue' => $rows->reduce(
                fn (string $total, array $row): string => Money::add($total, $row['overdue']),
                '0.00'
            ),
            'total_90_plus' => $rows->reduce(
                fn (string $total, array $row): string => Money::add($total, $row['overdue_90_plus']),
                '0.00'
            ),
            'total_advances' => $rows->reduce(
                fn (string $total, array $row): string => Money::add($total, $row['advance']),
                '0.00'
            ),
        ];
    }

    private function filterCreditReport(Collection $rows, array $validated): Collection
    {
        $filter = $validated['filter'] ?? 'all';

        return $rows->filter(function (array $row) use ($validated, $filter): bool {
            if ($search = $validated['search'] ?? null) {
                $haystack = strtolower($row['customer'].' '.($row['business_name'] ?? ''));
                if (! str_contains($haystack, strtolower($search))) {
                    return false;
                }
            }

            $isBlocked = $row['status'] === 'BLOCKED';
            $isOverLimit = $row['credit_limit'] !== null
                && Money::compare($row['exposure'], $row['credit_limit']) > 0;
            $isOverdue = Money::compare($row['overdue'], '0.00') > 0;
            $isNinetyPlus = Money::compare($row['overdue_90_plus'], '0.00') > 0;

            if ($filter === 'blocked' && ! $isBlocked
                || $filter === 'over_limit' && ! $isOverLimit
                || $filter === 'overdue' && ! $isOverdue
                || $filter === '90_plus' && ! $isNinetyPlus) {
                return false;
            }

            return ! (($validated['blocked'] ?? false) && ! $isBlocked)
                && ! (($validated['over_limit'] ?? false) && ! $isOverLimit)
                && ! (($validated['overdue'] ?? false) && ! $isOverdue)
                && ! (($validated['overdue_90_plus'] ?? false) && ! $isNinetyPlus);
        });
    }

    private function creditReportSort(array $validated): array
    {
        if ($sort = $validated['sort'] ?? null) {
            [$sortBy, $direction] = explode('_', $sort, 2);

            return [$sortBy, $direction];
        }

        return [$validated['sort_by'] ?? 'exposure', $validated['sort_direction'] ?? 'desc'];
    }

    private function sortCreditReport(Collection $rows, string $sortBy, string $direction): Collection
    {
        return $rows->sort(function (array $left, array $right) use ($sortBy, $direction): int {
            $leftValue = match ($sortBy) {
                'customer' => $left['customer'],
                'utilization' => $left['utilization_percent'],
                'overdue' => $left['overdue'],
                default => $left['exposure'],
            };
            $rightValue = match ($sortBy) {
                'customer' => $right['customer'],
                'utilization' => $right['utilization_percent'],
                'overdue' => $right['overdue'],
                default => $right['exposure'],
            };

            if ($leftValue === null || $rightValue === null) {
                $comparison = $leftValue === $rightValue ? 0 : ($leftValue === null ? 1 : -1);
            } elseif ($sortBy === 'customer') {
                $comparison = strcasecmp((string) $leftValue, (string) $rightValue);
            } elseif ($sortBy === 'utilization') {
                $comparison = $leftValue <=> $rightValue;
            } else {
                $comparison = Money::compare($leftValue, $rightValue);
            }

            if ($comparison !== 0) {
                return $leftValue === null || $rightValue === null
                    ? $comparison
                    : ($direction === 'desc' ? -$comparison : $comparison);
            }

            return strcasecmp($left['customer'], $right['customer']);
        });
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
