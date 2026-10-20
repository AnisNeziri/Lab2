<?php

namespace App\Services\Workforce;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** One pure decimal calculator. Rule inputs and outputs are frozen on each payroll line. */
class KosovoPayrollCalculator
{
    public const CURRENT = [
        'jurisdiction' => 'XK', 'currency' => 'EUR', 'monthly_bands' => [
            ['up_to' => '250.00', 'rate' => '0'], ['up_to' => '450.00', 'rate' => '0.08'], ['up_to' => null, 'rate' => '0.10'],
        ], 'secondary_rate' => '0.10', 'pension_min' => '5', 'pension_max' => '15',
        'declaration_due_day' => 15, 'rounding' => 'HALF_UP_2',
    ];

    public function calculate(array $rule, array $input): array
    {
        $gross = Money::normalize($input['gross']);
        if (Money::compare($gross, 0) < 0) {
            $this->fail('Gross earnings cannot be negative.');
        }
        $classification = $input['employer_classification'] ?? '';
        if (! in_array($classification, ['primary', 'secondary'], true)) {
            $this->fail('Select the employee’s declared primary or secondary employer classification.');
        }
        $treatment = $input['pension_treatment'] ?? '';
        if (! in_array($treatment, ['resident_contributor', 'foreign_temporary_excluded'], true)) {
            $this->fail('Pension residency/age treatment needs accountant review.');
        }
        $rates = [$input['employee_pension_rate'], $input['employer_pension_rate']];
        foreach ($rates as $rate) {
            if ($treatment === 'foreign_temporary_excluded') {
                if (Money::compareDecimal($rate, 0) !== 0 || blank($input['pension_basis'] ?? null)) {
                    $this->fail('A temporary foreign non-resident exclusion needs a documented basis and zero contributions. Resident foreign employees must not be automatically exempted.');
                }
            } elseif (Money::compareDecimal($rate, $rule['pension_min']) < 0 || Money::compareDecimal($rate, $rule['pension_max']) > 0) {
                $this->fail('Each pension contribution must be within the applicable statutory range.');
            }
        }
        $employee = Money::product([$gross, Money::divide($rates[0], 100, 8)]);
        $employer = Money::product([$gross, Money::divide($rates[1], 100, 8)]);
        $taxable = Money::subtract($gross, $employee);
        $tax = '0.00';
        $previous = '0.00';
        $bands = [];
        if ($classification === 'secondary') {
            $tax = Money::multiply($taxable, $rule['secondary_rate']);
        } else {
            foreach ($rule['monthly_bands'] as $band) {
                $upper = $band['up_to'] ?? $taxable;
                $basis = Money::maximum(0, Money::subtract(Money::minimum($taxable, $upper), $previous));
                $amount = Money::multiply($basis, $band['rate']);
                $bands[] = ['basis' => $basis, 'rate' => $band['rate'], 'tax' => $amount];
                $tax = Money::add($tax, $amount);
                $previous = $upper;
            }
        }
        $deductions = Money::normalize($input['deductions'] ?? 0);
        $advance = Money::normalize($input['advance_repayment'] ?? 0);
        if (Money::compare($deductions, 0) < 0 || Money::compare($advance, 0) < 0) {
            $this->fail('Deductions and advance repayments must be non-negative.');
        }
        $net = Money::subtract(Money::subtract(Money::subtract($taxable, $tax), $deductions), $advance);
        if (Money::compare($net, 0) < 0) {
            $this->fail('Deductions exceed the available net salary.');
        }

        return ['gross' => $gross, 'employee_pension' => $employee, 'employer_pension' => $employer,
            'taxable' => $taxable, 'tax' => $tax, 'deductions' => $deductions, 'advance_repayment' => $advance,
            'net' => $net, 'employer_cost' => Money::add($gross, $employer), 'bands' => $bands];
    }

    public function estimateGross(array $rule, array $input, string $target): array
    {
        $low = 0;
        $high = max(100, Money::minor($target) * 3);
        if (Money::compare($target, 0) <= 0) {
            $this->fail('Desired net must be positive.');
        }
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $result = $this->calculate($rule, [...$input, 'gross' => Money::decimal($mid), 'deductions' => 0, 'advance_repayment' => 0]);
            if (Money::compare($result['net'], $target) < 0) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return ['planning_only' => true, ...$this->calculate($rule, [...$input, 'gross' => Money::decimal($low), 'deductions' => 0, 'advance_repayment' => 0])];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payroll' => $message]);
    }
}
