<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMapping;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceProfile;
use App\Models\TaxCode;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Effective-date rules enrich the existing invoice/expense sources; no separate tax balance authority. */
class TaxCodeService
{
    public const TREATMENTS = ['taxable', 'exempt_without_credit', 'exempt_with_credit', 'export', 'out_of_scope', 'non_vat', 'reverse_charge', 'import'];

    public function initialize(): array
    {
        return DB::transaction(function () {
            app(AccountingService::class)->initialize();
            $maps = AccountingPostingMapping::query()->pluck('accounting_account_id', 'mapping_key');
            $rules = [
                ['KS18', 'VAT 18%', '18', 'taxable', 'E', true, true],
                ['KS08', 'VAT 8%', '8', 'taxable', 'D', true, true],
                ['EX-NC', 'Exempt supply without credit right', '0', 'exempt_without_credit', 'A', true, true],
                ['EX-C', 'Exempt supply with credit right', '0', 'exempt_with_credit', 'A', true, true],
                ['EXPORT', 'Exports', '0', 'export', 'C', true, false],
                ['OUT', 'Outside the scope of VAT', '0', 'out_of_scope', null, true, true],
                ['NONVAT', 'Non-VAT registered supplier', '0', 'non_vat', 'A', true, true],
                ['IMPORT18', 'Import VAT 18%', '18', 'import', null, false, true],
                ['IMPORT08', 'Import VAT 8%', '8', 'import', null, false, true],
                ['RC18', 'Reverse-charge purchase 18%', '18', 'reverse_charge', null, false, true],
            ];
            foreach ($rules as [$code,$name,$rate,$treatment,$group,$sales,$purchases]) {
                TaxCode::firstOrCreate(['code' => $code, 'effective_from' => '2015-09-01'], [
                    'name' => $name, 'rate' => $rate, 'treatment' => $treatment, 'sales_enabled' => $sales, 'purchases_enabled' => $purchases,
                    'sef_tax_group' => $group, 'output_account_id' => $maps['output_vat'], 'input_account_id' => $maps['input_vat'],
                    'legal_reference' => $treatment === 'taxable' ? 'Law 05/L-037, Article 26' : null, 'created_by' => Auth::id(),
                ]);
            }

            return TaxCode::orderBy('code')->get()->toArray();
        });
    }

    public function save(array $data, ?TaxCode $code = null): TaxCode
    {
        return DB::transaction(function () use ($data, $code) {
            Company::query()->whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            if ($code) {
                $code = TaxCode::query()->lockForUpdate()->findOrFail($code->id);
            }
            if (! in_array($data['treatment'], self::TREATMENTS, true)) {
                $this->fail('Choose a supported tax treatment.');
            }
            $rate = Money::normalizeDecimal($data['rate'], 4);
            if (Money::compareDecimal($rate, 0) < 0 || Money::compareDecimal($rate, 100) > 0) {
                $this->fail('The VAT rate must be between zero and 100.');
            }
            if (! in_array($data['treatment'], ['taxable', 'import', 'reverse_charge'], true) && Money::compareDecimal($rate, 0) !== 0) {
                $this->fail('This treatment requires a zero tax rate.');
            }
            if (($data['effective_to'] ?? null) && $data['effective_to'] < $data['effective_from']) {
                $this->fail('The effective end cannot precede its start.');
            }
            $overlap = TaxCode::where('code', $data['code'])->where('id', '!=', $code?->id ?? 0)
                ->where('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $data['effective_from']))->where('active', true)->exists();
            if (($data['active'] ?? true) && $overlap) {
                $this->fail('Active versions of one code must not overlap. Retire the prior version or set an unused version end date.');
            }
            foreach (['output_account_id' => 'liability', 'input_account_id' => 'asset'] as $field => $type) {
                if (empty($data[$field])) {
                    if (Money::compareDecimal($rate, 0) > 0) {
                        $this->fail('Taxable rules require input/output control-account mappings.');
                    }

continue;
                }
                $account = AccountingAccount::query()->findOrFail($data[$field]);
                if (! $account->is_active || $account->type !== $type) {
                    $this->fail('Use an active company-local VAT control account of the correct type.');
                }
            }
            if (! empty($data['sef_tax_group'])) {
                $expected = ['A' => '0', 'C' => '0', 'D' => '8', 'E' => '18'];
                if (! isset($expected[$data['sef_tax_group']]) || Money::compareDecimal($rate, $expected[$data['sef_tax_group']]) !== 0) {
                    $this->fail('The SEF group must agree with the published ATK rate.');
                }
            }
            $values = [...$data, 'rate' => $rate];
            if ($code) {
                $code->update($values);
            } else {
                $code = TaxCode::create([...$values, 'created_by' => Auth::id()]);
            }

            return $code->fresh();
        });
    }

    public function resolve(int $id, string $date, string $direction): array
    {
        $code = TaxCode::query()->findOrFail($id);
        if (! $code->active || $code->effective_from->toDateString() > $date || ($code->effective_to && $code->effective_to->toDateString() < $date)) {
            $this->fail('The selected tax-code version is inactive or not effective on this transaction date.');
        }
        if (! $code->{$direction === 'sales' ? 'sales_enabled' : 'purchases_enabled'}) {
            $this->fail('The selected tax code is not enabled for this document direction.');
        }

        return ['id' => $code->id, 'code' => $code->code, 'name' => $code->name, 'rate' => $code->rate, 'treatment' => $code->treatment,
            'effective_from' => $code->effective_from->toDateString(), 'effective_to' => $code->effective_to?->toDateString(),
            'output_account_id' => $code->output_account_id, 'input_account_id' => $code->input_account_id,
            'sef_tax_group' => $code->sef_tax_group, 'legal_reference' => $code->legal_reference, 'direction' => $direction];
    }

    public function assertRequired(?int $id, string $date): void
    {
        $from = InvoiceProfile::query()->value('tax_codes_required_from');
        if (! $id && $from && $date >= substr((string) $from, 0, 10)) {
            $this->fail('This company requires an effective tax code. Legacy percentage-only entry is disabled for this date.');
        }
    }

    public function invoiceTreatment(array $snapshot): string
    {
        return match ($snapshot['treatment']) {
            'taxable' => 'standard','export' => 'zero_rated','exempt_without_credit','exempt_with_credit' => 'exempt','out_of_scope','non_vat' => 'non_vat','reverse_charge' => 'reverse_charge',default => throw ValidationException::withMessages(['tax_code' => 'This tax code cannot be used on a sale.'])
        };
    }

    /** Uses existing decimal-safe Money and the existing line-rounding policy. */
    public function amounts(mixed $price, mixed $quantity, mixed $discount, mixed $rate, string $mode): array
    {
        if (! in_array($mode, ['inclusive', 'exclusive'], true)) {
            $this->fail('Choose tax-inclusive or tax-exclusive pricing.');
        }
        $basis = Money::subtract(Money::multiply($price, $quantity), $discount);
        if (Money::compare($basis, 0) < 0) {
            $this->fail('The discount exceeds the line value.');
        }
        if ($mode === 'inclusive') {
            $net = Money::divide($basis, BigDecimal::of('1')->plus(BigDecimal::of((string) $rate)->dividedBy('100', 6)));

            return ['net' => $net, 'vat' => Money::subtract($basis, $net), 'gross' => $basis, 'net_discount' => Money::subtract(Money::divide(Money::multiply($price, $quantity), BigDecimal::of('1')->plus(BigDecimal::of((string) $rate)->dividedBy('100', 6))), $net)];
        }
        $vat = Money::product([$basis, $rate, '0.01']);

        return ['net' => $basis, 'vat' => $vat, 'gross' => Money::add($basis, $vat), 'net_discount' => Money::normalize($discount)];
    }

    public function outputLines(Invoice $invoice, bool $debit = false): array
    {
        return $invoice->items->groupBy(fn ($line) => (string) ($line->tax_snapshot['output_account_id'] ?? 'mapping'))
            ->map(function ($lines, $account) use ($debit) {
                $amount = Money::add(...$lines->pluck('vat_amount')->all());

                return [...($account === 'mapping' ? ['mapping' => 'output_vat'] : ['accounting_account_id' => (int) $account]), $debit ? 'debit' : 'credit' => $amount];
            })->filter(fn ($line) => Money::compare($line[$debit ? 'debit' : 'credit'], 0) > 0)->values()->all();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['tax_code' => $message]);
    }
}
