<?php

namespace App\Services;

use App\Models\AccountingPostingMapping;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetEntry;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    public const CONTROLS = ['fixed_asset_cost', 'fixed_asset_accumulated_depreciation', 'fixed_asset_accumulated_impairment'];

    public function __construct(private readonly AccountingService $accounting) {}

    public function approveCategory(array $data): FixedAssetCategory
    {
        return DB::transaction(function () use ($data) {
            $this->lockCompany();
            if (FixedAssetCategory::query()->where('name', $data['name'])->exists()) {
                $this->fail('name', 'Choose a unique category name. Use a new version for a changed policy.');
            }
            $category = FixedAssetCategory::create([...$data, 'approved_by' => Auth::id()]);
            $this->audit('accounting.fixed_asset.policy_approved', $category, $category->getAttributes());

            return $category;
        });
    }

    public function prepareBill(Expense $expense, array $data): FixedAsset
    {
        return DB::transaction(function () use ($expense, $data) {
            $this->lockCompany();
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status !== 'draft' || ! $this->capitalizationRequired($expense)
                || ! in_array($expense->document_type, ['purchase_invoice', 'fiscal_receipt', 'customs_document'], true)) {
                $this->fail('expense_id', 'Select a draft investment bill or dedicated acquisition cost document.');
            }
            if (FixedAssetEntry::query()->where('expense_id', $expense->id)->exists()) {
                $this->fail('expense_id', 'This source already has a capitalization decision. Withdraw the draft decision before changing it.');
            }
            $this->assertNonStockBill($expense);
            $cost = $this->billCost($expense);
            $date = $expense->invoice_date->toDateString();
            if (Money::compare($cost, 0) <= 0) {
                $this->fail('expense_id', 'The source has no net capitalizable cost after recoverable VAT.');
            }

            $existingAsset = $data['fixed_asset_id'] ?? null;
            if ($existingAsset) {
                $asset = FixedAsset::query()->lockForUpdate()->findOrFail($existingAsset);
                $this->assertCostAddition($asset, $date);
                $kind = 'addition';
            } else {
                $category = FixedAssetCategory::query()->findOrFail($data['category_id']);
                if (Money::compare($cost, $category->minimum_cost) < 0 || Money::compare($data['residual_value'], $cost) >= 0) {
                    $this->fail('expense_id', 'Check the approved capitalization threshold and enter a residual value below the acquisition cost.');
                }
                if (FixedAsset::query()->where('asset_number', $data['asset_number'])->exists()) {
                    $this->fail('asset_number', 'This asset number is already in use.');
                }
                $asset = FixedAsset::create([
                    'category_id' => $category->id, 'asset_number' => $data['asset_number'], 'name' => $data['name'],
                    'residual_value' => $data['residual_value'], 'created_by' => Auth::id(),
                ]);
                $kind = 'acquisition';
            }
            $entry = $asset->entries()->create([
                'expense_id' => $expense->id, 'kind' => $kind, 'effective_on' => $date, 'cost_delta' => $cost,
                'request_hash' => $this->billFingerprint($expense), 'reason' => $data['reason'], 'created_by' => Auth::id(),
            ]);
            $this->audit('accounting.fixed_asset.capitalization_approved', $entry, [
                'asset_id' => $asset->id, 'expense_id' => $expense->id, 'cost' => $cost, 'reason' => $data['reason'],
            ]);

            return $asset;
        });
    }

    public function withdrawBill(Expense $expense, string $reason): void
    {
        DB::transaction(function () use ($expense, $reason) {
            $this->lockCompany();
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $entry = FixedAssetEntry::query()->where('expense_id', $expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->status !== 'draft' || $entry->journal_entry_id) {
                $this->fail('expense_id', 'Only an unposted capitalization decision can be withdrawn.');
            }
            $this->audit('accounting.fixed_asset.capitalization_withdrawn', $entry, ['reason' => $reason, 'expense_id' => $expense->id]);
            $asset = $entry->asset;
            $entry->delete();
            if ($entry->kind === 'acquisition') {
                $asset->delete();
            }
        });
    }

    public function billPostingEntry(Expense $expense): ?FixedAssetEntry
    {
        $entry = FixedAssetEntry::query()->where('expense_id', $expense->id)->lockForUpdate()->first();
        if ($entry?->journal_entry_id) {
            return $entry;
        }
        if (! $entry && ! $this->capitalizationRequired($expense) && $expense->document_type !== 'credit_note') {
            return null;
        }
        if ($expense->document_type === 'credit_note') {
            if ($this->capitalizationRequired($expense) || ($expense->original_expense_id
                && FixedAssetEntry::query()->where('expense_id', $expense->original_expense_id)->exists())) {
                $this->fail('expense_id', 'An asset supplier credit needs a reviewed source correction. Reverse dependent asset entries before correcting the original bill.');
            }

            return null;
        }
        if (! $entry || $entry->request_hash !== $this->billFingerprint($expense)) {
            $this->fail('expense_id', 'The accountant must approve capitalization for the current draft bill under Fixed assets.');
        }
        $this->assertNonStockBill($expense);
        if ($entry->kind === 'addition') {
            $this->assertCostAddition($entry->asset, $expense->invoice_date->toDateString());
        }

        return $entry;
    }

    public function reverseBill(Expense $expense, JournalEntry $journal, string $reason): void
    {
        $entry = FixedAssetEntry::query()->where('expense_id', $expense->id)->whereNotNull('journal_entry_id')->first();
        if (! $entry) {
            return;
        }
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'accounting.journal.reverse'), 403);
        $asset = $entry->asset;
        $this->assertLatestEntry($asset, $entry, $journal->posting_date->toDateString());
        if ($entry->kind === 'acquisition' && $asset->entries()->where('kind', 'addition')->whereNull('journal_entry_id')->exists()) {
            $this->fail('expense_id', 'Withdraw any unposted additions before reversing the acquisition.');
        }
        $this->recordReversal($entry, $journal, $reason);
    }

    public function prepareSale(FixedAsset $asset, Invoice $invoice, string $reason): FixedAssetEntry
    {
        return DB::transaction(function () use ($asset, $invoice, $reason) {
            $this->lockCompany();
            $invoice = Invoice::query()->with('items')->lockForUpdate()->findOrFail($invoice->id);
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $this->assertSaleDraft($invoice);
            if ($asset->entries()->whereNull('journal_entry_id')->exists() || FixedAssetEntry::query()->where('sale_invoice_id', $invoice->id)->exists()) {
                $this->fail('invoice_id', 'Post or withdraw any pending asset decisions before approving another sale.');
            }
            if (Money::compare($this->balance($asset, CompanyClock::today()->toDateString())['cost'], 0) <= 0) {
                $this->fail('asset', 'Only a posted asset still in the register can be sold.');
            }
            $entry = $asset->entries()->create([
                'sale_invoice_id' => $invoice->id, 'kind' => 'sale', 'effective_on' => $invoice->invoice_date->toDateString(),
                'reason' => $reason, 'request_hash' => $this->saleFingerprint($invoice), 'created_by' => Auth::id(),
            ]);
            $this->audit('accounting.fixed_asset.sale_approved', $entry, ['invoice_id' => $invoice->id, 'reason' => $reason]);

            return $entry;
        });
    }

    public function withdrawSale(FixedAssetEntry $entry, string $reason): void
    {
        DB::transaction(function () use ($entry, $reason) {
            $this->lockCompany();
            $entry = FixedAssetEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if (! $entry->sale_invoice_id || $entry->journal_entry_id || $entry->saleInvoice->issued_at) {
                $this->fail('invoice_id', 'Only an unissued asset-sale approval can be withdrawn.');
            }
            $this->audit('accounting.fixed_asset.sale_withdrawn', $entry, ['invoice_id' => $entry->sale_invoice_id, 'reason' => $reason]);
            $entry->delete();
        });
    }

    public function saleForIssue(Invoice $invoice, string $date): ?FixedAssetEntry
    {
        $entry = FixedAssetEntry::query()->where('sale_invoice_id', $invoice->id)->lockForUpdate()->first();
        if (! $entry) {
            return null;
        }
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'accounting.journal.post'), 403);
        $this->assertSaleDraft($invoice);
        if ($entry->request_hash !== $this->saleFingerprint($invoice)) {
            $this->fail('invoice_id', 'The asset-sale invoice changed. Ask the accountant to review it again before issuing it.');
        }
        $asset = $entry->asset;
        $this->guardDate($asset, $date);
        $state = $this->balance($asset, $date);
        if (Money::compare($state['cost'], 0) <= 0 || Money::compare($this->depreciationDue($asset, $date, $state), 0) > 0) {
            $this->fail('invoice_id', 'Verify the asset and post all depreciation through the sale date before issuing the invoice.');
        }

        return $entry;
    }

    public function postSale(FixedAssetEntry $entry, Invoice $invoice): void
    {
        $date = $invoice->invoice_date->toDateString();
        $state = $this->balance($entry->asset, $date);
        $netProceeds = Money::subtract($invoice->grand_total, $invoice->vat_total);
        [$lines, $delta] = $this->disposalLines($state, $netProceeds);
        $journal = $this->accounting->postMapped('fixed_assets', 'fixed_asset_entry', $entry->id, 'fixed-asset-entry:'.$entry->id,
            $date, 'Asset sale '.$invoice->invoice_number, $lines, CompanyCurrency::current());
        $entry->update([...$delta, 'effective_on' => $date, 'journal_entry_id' => $journal->id]);
    }

    public function reverseSale(Invoice $original, string $date, string $reason): void
    {
        $entry = FixedAssetEntry::query()->where('sale_invoice_id', $original->id)->whereNotNull('journal_entry_id')->first();
        if ($entry) {
            abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'accounting.journal.reverse'), 403);
            $this->assertLatestEntry($entry->asset, $entry, $date);
            $journal = $this->accounting->reverse($entry->journal, $date, $reason);
            $this->recordReversal($entry, $journal, $reason);
        }
    }

    public function placeInService(FixedAsset $asset, string $date, string $reason): FixedAsset
    {
        return DB::transaction(function () use ($asset, $date, $reason) {
            $this->lockCompany();
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $state = $this->balance($asset, $date);
            if ($asset->available_for_use_on || Money::compare($state['cost'], 0) <= 0 || $asset->entries()->whereNull('journal_entry_id')->exists()) {
                $this->fail('available_for_use_on', 'Post the acquisition and all approved costs first. An existing in-service date requires a reviewed source correction.');
            }
            $this->guardDate($asset, $date);
            $asset->update(['available_for_use_on' => $date]);
            $this->audit('accounting.fixed_asset.placed_in_service', $asset, ['date' => $date, 'reason' => $reason]);

            return $asset;
        });
    }

    public function balance(FixedAsset $asset, string $to): array
    {
        $amounts = $asset->entries()->whereNotNull('journal_entry_id')->where('effective_on', '<=', $to)
            ->selectRaw('SUM(cost_delta) AS cost, SUM(depreciation_delta) AS depreciation, SUM(impairment_delta) AS impairment')->first();
        $cost = Money::normalize($amounts->cost ?? 0);
        $dep = Money::normalize($amounts->depreciation ?? 0);
        $imp = Money::normalize($amounts->impairment ?? 0);

        return ['cost' => $cost, 'depreciation' => $dep, 'impairment' => $imp, 'net_book_value' => Money::subtract(Money::subtract($cost, $dep), $imp)];
    }

    public function depreciationDue(FixedAsset $asset, string $to, ?array $state = null): string
    {
        $start = $asset->available_for_use_on?->toImmutable();
        $state ??= $this->balance($asset, $to);
        if (! $start || $start->toDateString() > $to || Money::compare($state['cost'], 0) <= 0) {
            return '0.00';
        }
        $end = $start->addMonthsNoOverflow($asset->category->useful_life_months);
        $lastImpairment = $asset->entries()->where('kind', 'impairment')->where('effective_on', '<=', $to)
            ->whereNotIn('id', $asset->entries()->getQuery()->whereNotNull('reverses_entry_id')->where('effective_on', '<=', $to)->select('reverses_entry_id'))
            ->orderByDesc('effective_on')->orderByDesc('id')->first();
        $baseState = $lastImpairment ? $this->balance($asset, $lastImpairment->effective_on->toDateString()) : null;
        $start = $lastImpairment ? $lastImpairment->effective_on->toImmutable()->addDay() : $start;
        $duration = max(0, $start->diffInDays($end, false));
        if ($duration <= 0) {
            return '0.00';
        }
        $elapsed = min($duration, max(0, $start->diffInDays(CarbonImmutable::parse($to)->addDay(), false)));
        $basis = Money::maximum(0, Money::subtract($baseState['net_book_value'] ?? $state['cost'], $asset->residual_value));
        $target = Money::add($baseState['depreciation'] ?? 0, Money::multiply($basis, Money::divide($elapsed, $duration, 12)));

        return Money::maximum(0, Money::subtract($target, $state['depreciation']));
    }

    public function post(FixedAsset $asset, array $data): FixedAssetEntry
    {
        return DB::transaction(function () use ($asset, $data) {
            $this->lockCompany();
            $asset = FixedAsset::query()->lockForUpdate()->with('category')->findOrFail($asset->id);
            $date = $data['date'];
            $kind = $data['kind'];
            $amount = Money::normalize($data['amount'] ?? 0);
            $hash = hash('sha256', json_encode([$asset->id, $kind, $date, $amount, trim($data['reason'])], JSON_THROW_ON_ERROR));
            if ($existing = FixedAssetEntry::query()->where('request_key', $data['request_key'])->first()) {
                if ($existing->request_hash !== $hash) {
                    $this->fail('request_key', 'This request was already used with different accounting details.');
                }

                return $existing;
            }
            $this->guardDate($asset, $date);
            $state = $this->balance($asset, $date);
            if (Money::compare($state['cost'], 0) <= 0) {
                $this->fail('asset', 'Only an acquired asset still in the register can be posted.');
            }
            if ($asset->entries()->whereNull('journal_entry_id')->when($kind === 'depreciation', fn ($q) => $q->whereNull('sale_invoice_id'))->exists()) {
                $this->fail('asset', 'Post or withdraw outstanding acquisition cost decisions first.');
            }
            $due = $this->depreciationDue($asset, $date, $state);
            if ($kind !== 'depreciation' && Money::compare($due, 0) > 0) {
                $this->fail('date', 'Post depreciation through this date before recording impairment or disposal.');
            }
            $delta = [];
            if ($kind === 'depreciation') {
                if (! $asset->available_for_use_on || Money::compare($due, 0) <= 0) {
                    $this->fail('date', 'There is no unposted depreciation through this date.');
                }
                $delta = ['depreciation_delta' => $due];
                $lines = [['mapping' => 'fixed_asset_depreciation', 'debit' => $due], ['mapping' => self::CONTROLS[1], 'credit' => $due]];
            } elseif ($kind === 'impairment') {
                if (Money::compare($amount, 0) <= 0 || Money::compare($amount, $state['net_book_value']) > 0) {
                    $this->fail('amount', 'Enter a supported impairment loss up to the current net book value.');
                }
                if ($asset->entries()->where('kind', 'impairment')->where('effective_on', $date)->whereNotNull('journal_entry_id')->exists()) {
                    $this->fail('date', 'Only one impairment can be assessed for an asset on a given date.');
                }
                $delta = ['impairment_delta' => $amount];
                $lines = [['mapping' => 'fixed_asset_impairment', 'debit' => $amount], ['mapping' => self::CONTROLS[2], 'credit' => $amount]];
            } else {
                [$lines, $delta] = $this->disposalLines($state, '0.00');
            }
            $entry = $asset->entries()->create([
                ...$delta, 'kind' => $kind, 'effective_on' => $date, 'reason' => $data['reason'], 'created_by' => Auth::id(),
                'request_key' => $data['request_key'], 'request_hash' => $hash,
            ]);
            $journal = $this->accounting->postMapped('fixed_assets', 'fixed_asset_entry', $entry->id, 'fixed-asset-entry:'.$entry->id, $date, $asset->asset_number.' · '.$data['reason'], $lines, CompanyCurrency::current());
            $entry->update(['journal_entry_id' => $journal->id]);

            return $entry;
        });
    }

    public function reverse(FixedAssetEntry $entry, string $date, string $reason): FixedAssetEntry
    {
        return DB::transaction(function () use ($entry, $date, $reason) {
            $this->lockCompany();
            $entry = FixedAssetEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->expense_id || $entry->sale_invoice_id || ! $entry->journal_entry_id || $entry->reverses_entry_id) {
                $this->fail('entry', 'Use the original supplier bill or customer invoice workflow to correct this entry.');
            }
            if ($existing = FixedAssetEntry::query()->where('reverses_entry_id', $entry->id)->first()) {
                return $existing;
            }
            $this->assertLatestEntry($entry->asset, $entry, $date);
            $journal = $this->accounting->reverse($entry->journal, $date, $reason);

            return $this->recordReversal($entry, $journal, $reason);
        });
    }

    public function reconciliation(string $to): array
    {
        $register = FixedAssetEntry::query()->whereNotNull('journal_entry_id')->where('effective_on', '<=', $to)
            ->selectRaw('SUM(cost_delta) AS cost, SUM(depreciation_delta) AS depreciation, SUM(impairment_delta) AS impairment')->first();
        $mappings = AccountingPostingMapping::query()->whereIn('mapping_key', self::CONTROLS)->pluck('accounting_account_id', 'mapping_key');
        $gl = collect($this->accounting->trialBalance(null, $to)['rows'])->keyBy('id');
        $rows = [];
        foreach (['cost', 'depreciation', 'impairment'] as $index => $metric) {
            $expected = Money::normalize($register->{$metric} ?? 0);
            $recorded = Money::normalize($gl->get($mappings->get(self::CONTROLS[$index]))['closing_balance'] ?? 0);
            if ($index > 0) {
                $recorded = Money::subtract(0, $recorded);
            }
            $rows[$metric] = ['register' => $expected, 'ledger' => $recorded, 'difference' => Money::subtract($expected, $recorded)];
        }
        $unreviewed = Expense::query()->whereIn('status', ['posted', 'reversed'])->where('invoice_date', '<=', $to)
            ->where(fn ($q) => $q->where('status', 'posted')->orWhere('reversal_date', '>', $to))
            ->where(fn ($q) => $q->where('asset_treatment', 'investment')->orWhere('category', 'capital_asset'))
            ->whereNotIn('id', FixedAssetEntry::query()->whereNotNull('expense_id')->whereNotNull('journal_entry_id')->select('expense_id'))->count();

        return ['as_of' => $to, 'rows' => $rows, 'legacy_investment_bills' => $unreviewed,
            'reconciled' => $unreviewed === 0 && collect($rows)->every(fn ($row) => $row['difference'] === '0.00')];
    }

    public function outstandingDepreciationCount(string $to): int
    {
        $count = 0;
        FixedAsset::query()->with('category')->whereDate('available_for_use_on', '<=', $to)->chunkById(50, function ($assets) use ($to, &$count) {
            foreach ($assets as $asset) {
                if (Money::compare($this->depreciationDue($asset, $to), 0) > 0) {
                    $count++;
                }
            }
        });

        return $count;
    }

    private function disposalLines(array $state, string $proceeds): array
    {
        $lines = [['mapping' => self::CONTROLS[0], 'credit' => $state['cost']]];
        foreach (['depreciation', 'impairment'] as $index => $metric) {
            if (Money::compare($state[$metric], 0) > 0) {
                $lines[] = ['mapping' => self::CONTROLS[$index + 1], 'debit' => $state[$metric]];
            }
        }
        if (Money::compare($proceeds, 0) > 0) {
            // The issued invoice credited this amount. Reclassify only net proceeds, leaving its VAT and receivable intact.
            $lines[] = ['mapping' => 'sales_revenue', 'debit' => $proceeds];
        }
        $result = Money::subtract($proceeds, $state['net_book_value']);
        if (Money::compare($result, 0) > 0) {
            $lines[] = ['mapping' => 'fixed_asset_disposal_gain', 'credit' => $result];
        } elseif (Money::compare($result, 0) < 0) {
            $lines[] = ['mapping' => 'fixed_asset_disposal_loss', 'debit' => Money::subtract(0, $result)];
        }

        return [$lines, [
            'cost_delta' => Money::subtract(0, $state['cost']),
            'depreciation_delta' => Money::subtract(0, $state['depreciation']),
            'impairment_delta' => Money::subtract(0, $state['impairment']),
        ]];
    }

    private function assertCostAddition(FixedAsset $asset, string $date): void
    {
        if (Money::compare($this->balance($asset, $date)['cost'], 0) <= 0 || $asset->available_for_use_on) {
            $this->fail('expense_id', 'Additional acquisition costs can only be attached before the acquired asset is available for use.');
        }
        $this->guardDate($asset, $date);
    }

    private function assertNonStockBill(Expense $expense): void
    {
        if ($expense->category === 'inventory' || ($expense->purchase_order_id
            && $expense->purchaseOrder->items()->whereNotNull('product_id')->exists())) {
            $this->fail('expense_id', 'A stock receipt already uses inventory accounting. Resolve the purchasing classification before capitalizing an asset.');
        }
    }

    private function capitalizationRequired(Expense $expense): bool
    {
        return $expense->asset_treatment === 'investment' || $expense->category === 'capital_asset';
    }

    private function assertSaleDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft' || $invoice->document_type !== 'invoice' || $invoice->daily_sale_id
            || $invoice->currency !== CompanyCurrency::current() || $invoice->items->count() !== 1 || $invoice->items->first()->product_id
            || Money::compare($invoice->grand_total, $invoice->vat_total) <= 0) {
            $this->fail('invoice_id', 'Prepare a dedicated base-currency draft invoice with one non-stock asset line and a positive net price.');
        }
    }

    private function saleFingerprint(Invoice $invoice): string
    {
        // Exclude the line primary key because the invoice service regenerates draft lines on issue.
        return hash('sha256', json_encode([
            $invoice->customer_id, $invoice->buyer_snapshot, $invoice->currency, $invoice->grand_total, $invoice->vat_total,
            $invoice->items->sole()->only(['description', 'product_id', 'quantity', 'unit_price', 'vat_rate', 'tax_treatment', 'tax_code_id']),
        ], JSON_THROW_ON_ERROR));
    }

    private function billCost(Expense $expense): string
    {
        return Money::subtract(Money::add($expense->gross_amount_eur, $expense->self_assessed_vat_amount_eur), $expense->deductible_vat_amount_eur);
    }

    private function billFingerprint(Expense $expense): string
    {
        return hash('sha256', json_encode($expense->only([
            'document_type', 'document_number', 'vendor_name', 'supplier_id', 'purchase_order_id', 'invoice_date',
            'asset_treatment', 'category', 'currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source',
            'gross_amount_eur', 'deductible_vat_amount_eur', 'self_assessed_vat_amount_eur', 'tax_code_id', 'tax_snapshot',
        ]), JSON_THROW_ON_ERROR));
    }

    private function assertLatestEntry(FixedAsset $asset, FixedAssetEntry $entry, string $date): void
    {
        $this->guardDate($asset, $date);
        $activeLaterEntry = $asset->entries()->whereNotNull('journal_entry_id')->whereNull('reverses_entry_id')
            ->whereNotIn('id', FixedAssetEntry::query()->where('fixed_asset_id', $asset->id)->whereNotNull('reverses_entry_id')->select('reverses_entry_id'))
            ->where(fn ($q) => $q->where('effective_on', '>', $entry->effective_on->toDateString())
                ->orWhere(fn ($q) => $q->where('effective_on', $entry->effective_on->toDateString())->where('id', '>', $entry->id)))->exists();
        if ($activeLaterEntry) {
            $this->fail('entry', 'Reverse later asset activity first to preserve the carrying amount and history.');
        }
    }

    private function recordReversal(FixedAssetEntry $entry, JournalEntry $journal, string $reason): FixedAssetEntry
    {
        return $entry->asset->entries()->create([
            'kind' => 'reversal', 'effective_on' => $journal->posting_date->toDateString(), 'journal_entry_id' => $journal->id,
            'reverses_entry_id' => $entry->id, 'reason' => $reason, 'created_by' => Auth::id(),
            'cost_delta' => Money::subtract(0, $entry->cost_delta), 'depreciation_delta' => Money::subtract(0, $entry->depreciation_delta),
            'impairment_delta' => Money::subtract(0, $entry->impairment_delta),
        ]);
    }

    private function guardDate(FixedAsset $asset, string $date): void
    {
        if ($date > CompanyClock::today()->toDateString() || $asset->entries()->whereNotNull('journal_entry_id')->where('effective_on', '>', $date)->exists()) {
            $this->fail('date', 'Choose a date through today and on or after the most recent posted asset event.');
        }
        $this->accounting->assertDateOpen($date);
    }

    private function lockCompany(): void
    {
        $company = Auth::user()?->company_id;
        abort_unless($company, 403);
        DB::table('companies')->where('id', $company)->lockForUpdate()->first();
    }

    private function audit(string $action, object $entity, array $details): void
    {
        ActivityLog::create(['company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'action' => $action,
            'entity' => class_basename($entity), 'entity_id' => $entity->id, 'new_value' => $details, 'description' => $action]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
