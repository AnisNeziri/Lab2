<?php

namespace App\Services\Kosovo;

use App\Models\Expense;
use App\Models\ActivityLog;
use App\Models\FinancialAccount;
use App\Models\InvoiceProfile;
use App\Models\PurchaseOrder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class CashPaymentPolicy
{
    public function applies(): bool
    {
        return InvoiceProfile::query()->where('country_code', 'XK')->exists();
    }

    public function isElectronic(?string $method, ?FinancialAccount $account): bool
    {
        return ($account === null || $account->type === 'bank') && in_array($method, ['bank_transfer', 'card'], true);
    }

    public function evidencedDomesticOrderValue(PurchaseOrder $order): ?string
    {
        $bills = Expense::query()->where('purchase_order_id', $order->id)->where('supplier_id', $order->supplier_id)
            ->where('document_type', 'purchase_invoice')->where('status', 'posted')->where('source_type', 'domestic')
            ->selectRaw('SUM(gross_amount_eur) as invoiced_eur')->toBase()->value('invoiced_eur');
        if ($bills === null) {
            return null;
        }
        return Money::maximum($bills, $this->orderBaseValue($order));
    }

    public function orderBaseValue(PurchaseOrder $order): string
    {
        if ($order->currency !== 'EUR' && (Money::compareDecimal($order->exchange_rate ?? 0, 0) <= 0
            || ! $order->exchange_rate_date || blank($order->exchange_rate_source))) {
            throw ValidationException::withMessages(['exchange_rate' => ['Verify the historical EUR value of the complete order before recording this cash payment.']]);
        }

        return Money::multiply($order->total_amount, $order->currency === 'EUR' ? 1 : $order->exchange_rate);
    }

    public function threshold(string $date, ?string $counterparty, bool $includesInKind = false): ?array
    {
        if (CarbonImmutable::parse($date)->toDateString() < '2024-01-27') {
            return null;
        }

        return match ($counterparty) {
            'business' => $includesInKind ? ['limit' => 100, 'rule' => '08/L-257, art. 6(10)'] : ['limit' => 300, 'rule' => '08/L-257, art. 6(9)'],
            'individual' => CarbonImmutable::parse($date)->toDateString() >= '2026-06-01'
                ? ['limit' => 2000, 'rule' => $includesInKind ? '08/L-257, art. 6(12) and Decision 19/2026' : 'Decision 19/2026'] : null,
            default => null,
        };
    }

    /** Apply the limit to the complete evidenced transaction in EUR, including tax. */
    public function assertAllowed(string $paymentDate, mixed $transactionEur, ?string $counterparty, ?string $method, ?FinancialAccount $account = null, ?Model $source = null): void
    {
        $paymentDate = CarbonImmutable::parse($paymentDate)->toDateString();
        $rule = $this->threshold($paymentDate, $counterparty);
        if ($this->isElectronic($method, $account)) {
            $this->audit($source, $paymentDate, $rule, 'electronic');
            return;
        }
        if ($paymentDate < '2024-01-27') {
            $this->audit($source, $paymentDate, $rule, 'before_effective_date');
            return;
        }

        $sq = (Auth::user()?->preferences['language'] ?? 'en') === 'sq';
        $limit = $rule['limit'] ?? null;
        if (! in_array($counterparty, ['business', 'individual'], true) && Money::compare($transactionEur, 300) > 0) {
            throw ValidationException::withMessages(['payment_method' => [$sq
                ? 'Për të regjistruar këtë pagesë në para të gatshme, identifikoni palën dhe vlerën e plotë të transaksionit. Përndryshe përdorni llogarinë bankare ose pagesën elektronike.'
                : 'To record this cash payment, identify the counterparty and full transaction value. Otherwise use a bank account or electronic payment.']]);
        }
        if ($limit === null || Money::compare($transactionEur, $limit) <= 0) {
            $basis = $limit !== null ? 'within_limit' : ($counterparty === 'individual' ? 'before_individual_limit' : 'unclassified_within_review_limit');
            $this->audit($source, $paymentDate, $rule, $basis);
            return;
        }

        throw ValidationException::withMessages(['payment_method' => [$sq
            ? "Kur vlera e plotë e transaksionit kalon {$limit} EUR, çdo këst duhet të paguhet përmes bankës ose në mënyrë elektronike."
            : "When the full transaction exceeds EUR {$limit}, every instalment must be paid through a bank account or electronically."]]);
    }

    private function audit(?Model $source, string $date, ?array $rule, string $basis): void
    {
        $user = Auth::user();
        if (! $source || ! $user || (int) $source->company_id !== (int) $user->company_id) {
            return;
        }
        ActivityLog::create(['company_id' => $user->company_id, 'user_id' => $user->id,
            'action' => 'accounting.kosovo_cash_control.checked', 'entity' => class_basename($source),
            'entity_id' => $source->id, 'description' => 'Kosovo settlement control checked.',
            'new_value' => ['payment_date' => $date, 'rule' => $rule['rule'] ?? null, 'limit' => $rule['limit'] ?? null, 'basis' => $basis],
        ]);
    }
}
