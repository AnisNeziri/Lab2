<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMapping;
use App\Models\ActivityLog;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Warehouse;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Authorized manual-document boundary; AccountingService remains the posting authority. */
class ManualJournalService
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function controlledAccountIds(): array
    {
        $accounts = AccountingAccount::query()->get(['id', 'parent_id', 'code', 'name']);
        $controlled = AccountingPostingMapping::query()->whereIn('mapping_key',['cash','bank','accounts_receivable','customer_advances','accounts_payable','supplier_advances','inventory','sales_revenue','cost_of_goods_sold','goods_receipt_clearing','input_vat','output_vat'])->pluck('accounting_account_id')
            ->merge(FinancialAccount::query()->whereNotNull('accounting_account_id')->pluck('accounting_account_id'))->unique()->all();
        do {
            $old = count($controlled);
            $controlled = array_values(array_unique([...$controlled, ...$accounts->whereIn('parent_id', $controlled)->pluck('id')->all()]));
        } while (count($controlled) !== $old);

        return $controlled;
    }

    public function controlledAccounts(JournalEntry $entry): array
    {
        return $entry->lines()->with('account:id,code,name')->whereIn('accounting_account_id', $this->controlledAccountIds())
            ->get()->pluck('account')->unique('id')->values()->all();
    }

    public function save(array $data, ?JournalEntry $entry = null): JournalEntry
    {
        return DB::transaction(function () use ($data, $entry) {
            $this->lockCompany();
            if ($entry) {
                $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
                $this->manual($entry);
                if ($entry->status !== 'draft') {
                    throw ValidationException::withMessages(['journal' => 'Only a manual draft can be edited.']);
                }
                abort_unless((int) ($data['revision'] ?? 0) === $entry->revision, 409, 'This draft changed. Reload before editing.');
            }
            $this->accounting->assertDateOpen($data['posting_date'], true);
            $base = CompanyCurrency::forCompanyId(Auth::user()->company_id);
            $currency = strtoupper($data['currency'] ?? $base);
            $foreign = $currency !== $base;
            if ($foreign && (empty($data['exchange_rate']) || empty($data['exchange_rate_date']) || empty($data['exchange_rate_source']) || $data['exchange_rate_date'] > $data['posting_date'])) {
                throw ValidationException::withMessages(['exchange_rate' => 'Record an authorized historical rate, its date and source. A future rate cannot value an earlier posting.']);
            }
            $rate = $foreign ? Money::normalizeDecimal($data['exchange_rate'], 8) : '1.00000000';
            foreach ($data['lines'] as $i => &$line) {
                if ($foreign && (Money::compare(Money::multiply($line['foreign_debit'] ?? 0, $rate), $line['debit'] ?? 0) !== 0
                    || Money::compare(Money::multiply($line['foreign_credit'] ?? 0, $rate), $line['credit'] ?? 0) !== 0)) {
                    throw ValidationException::withMessages(["lines.$i" => 'Base amounts must equal the foreign amounts at the recorded rate.']);
                }
                if (! $foreign) {
                    $line['foreign_debit'] = '0.00';
                    $line['foreign_credit'] = '0.00';
                }
                $dimensions = $line['dimensions'] ?? [];
                if (! empty($dimensions['warehouse_id'])) {
                    Warehouse::query()->findOrFail($dimensions['warehouse_id']);
                }
                if (! empty($dimensions['sales_channel']) && ! in_array($dimensions['sales_channel'], ['retail', 'wholesale', 'online', 'other'], true)) {
                    throw ValidationException::withMessages(["lines.$i.dimensions" => 'Choose a supported sales channel.']);
                }
                $line['dimensions'] = array_intersect_key($dimensions, array_flip(['warehouse_id', 'sales_channel']));
            }
            unset($line);
            $fields = ['posting_date' => $data['posting_date'], 'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'], 'currency' => $currency, 'exchange_rate' => $rate,
                'exchange_rate_date' => $foreign ? $data['exchange_rate_date'] : null,
                'exchange_rate_source' => $foreign ? $data['exchange_rate_source'] : null];
            if (! $entry) {
                $entry = $this->accounting->createDraft([...$fields, 'source_module' => 'manual', 'lines' => $data['lines']]);
            } else {
                // The locked manual draft is the only replaceable financial document.
                $entry->lines()->delete();
                $entry->fill([...$fields, 'revision' => $entry->revision + 1, 'control_acknowledgement' => null])->save();
                foreach ($data['lines'] as $i => $line) {
                    $account = AccountingAccount::query()->findOrFail($line['accounting_account_id']);
                    if (! $account->is_active || ! $account->is_posting || (Money::compare($line['debit'] ?? 0, 0) > 0) === (Money::compare($line['credit'] ?? 0, 0) > 0)) {
                        throw ValidationException::withMessages(['lines' => 'Use active posting accounts and one positive debit or credit per line.']);
                    }
                    $entry->lines()->create([...$line, 'line_number' => $i + 1]);
                }
                $entry->update(['total_debit' => Money::normalize($entry->lines()->sum('debit')), 'total_credit' => Money::normalize($entry->lines()->sum('credit'))]);
            }
            $entry->update(['exchange_rate_date' => $fields['exchange_rate_date'], 'exchange_rate_source' => $fields['exchange_rate_source']]);
            if ($data['acknowledge_controls'] ?? false) {
                $this->acknowledge($entry, $data);
            }
            $this->audit('accounting.manual_journal.saved', $entry, ['revision' => $entry->revision]);

            return $entry->fresh('lines.account');
        });
    }

    public function post(JournalEntry $entry, array $data): JournalEntry
    {
        return DB::transaction(function () use ($entry, $data) {
            $this->lockCompany();
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $this->manual($entry);
            if ($entry->status === 'posted') {
                return $entry->load('lines.account');
            }
            if (isset($data['revision'])) {
                abort_unless((int) $data['revision'] === $entry->revision, 409, 'The draft changed. Reload before posting.');
            }
            if ($data['acknowledge_controls'] ?? false) {
                $this->acknowledge($entry, $data);
            }
            if ($this->controlledAccounts($entry)) {
                $this->permit('accounting.journal.controlled');
                if (! $entry->control_acknowledgement || ($entry->control_acknowledgement['revision'] ?? null) !== $entry->revision) {
                    throw ValidationException::withMessages(['control_reason' => 'This journal touches system controls. Prefer the source correction workflow, or explicitly acknowledge the impact with a reason.']);
                }
            }
            $soft = (bool) ($data['soft_close_adjustment'] ?? false);
            if ($soft) {
                $this->permit('accounting.periods.adjust');
                if (mb_strlen(trim($data['control_reason'] ?? '')) < 10) {
                    throw ValidationException::withMessages(['control_reason' => 'Explain this soft-close adjustment.']);
                }
                $this->audit('accounting.soft_close_adjustment', $entry, ['reason' => trim($data['control_reason'])]);
            }

            return $this->accounting->post($entry, $soft);
        });
    }

    public function correct(JournalEntry $entry, string $date, string $reason): array
    {
        return DB::transaction(function () use ($entry, $date, $reason) {
            $this->lockCompany();
            $entry = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($entry->id);
            $this->manual($entry);
            if ($entry->status !== 'posted') {
                throw ValidationException::withMessages(['journal' => 'Correct a posted manual journal. Existing reversals remain in its history.']);
            }
            $reversal = $this->accounting->reverse($entry, $date, $reason);
            $draft = $this->accounting->createDraft(['posting_date' => $date, 'description' => 'Correction: '.$entry->description,
                'reference_number' => $entry->reference_number, 'source_module' => 'manual', 'currency' => $entry->currency,
                'exchange_rate' => $entry->exchange_rate, 'lines' => $entry->lines->map(fn ($l) => $l->only(['accounting_account_id', 'description', 'debit', 'credit', 'foreign_debit', 'foreign_credit', 'dimensions']))->all()]);
            $draft->update(['correction_of_id' => $entry->id, 'exchange_rate_date' => $entry->exchange_rate_date, 'exchange_rate_source' => $entry->exchange_rate_source]);
            $this->audit('accounting.manual_journal.corrected', $entry, ['reversal_id' => $reversal->id, 'draft_id' => $draft->id, 'reason' => $reason]);

            return ['reversal' => $reversal, 'draft' => $draft->fresh('lines.account')];
        });
    }

    private function acknowledge(JournalEntry $entry, array $data): void
    {
        $this->permit('accounting.journal.controlled');
        if (mb_strlen(trim($data['control_reason'] ?? '')) < 10) {
            throw ValidationException::withMessages(['control_reason' => 'Explain why a manual control-account adjustment is necessary.']);
        }
        $entry->update(['control_acknowledgement' => ['user_id' => Auth::id(), 'at' => now()->toIso8601String(), 'revision' => $entry->revision, 'reason' => trim($data['control_reason'])]]);
    }

    private function manual(JournalEntry $entry): void
    {
        abort_unless($entry->source_module === 'manual', 422, 'Use the source transaction workflow. Its operational subledger owns this journal.');
    }

    private function permit(string $permission): void
    {
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission), 403);
    }

    private function lockCompany(): void
    {
        DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
    }

    private function audit(string $action, JournalEntry $entry, array $facts): void
    {
        ActivityLog::create(['company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'action' => $action,
            'entity' => 'JournalEntry', 'entity_id' => $entry->id, 'description' => str_replace('.', ' ', $action), 'new_value' => $facts]);
    }
}
