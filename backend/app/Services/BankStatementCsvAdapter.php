<?php

namespace App\Services;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Generic mapped CSV staging. Parsing never creates a financial transaction. */
class BankStatementCsvAdapter
{
    public function parse(string $contents, array $mapping, array $options = [], string $delimiter = 'auto'): array
    {
        $options = validator($options, ['date_format' => 'sometimes|in:Y-m-d,d/m/Y,m/d/Y,d.m.Y,d-m-Y,Y/m/d', 'decimal_separator' => 'sometimes|in:dot,comma',
            'thousands_separator' => 'sometimes|in:comma,dot,space,none', 'row_order' => 'sometimes|in:ascending,descending'])->validate();
        $options += ['date_format' => 'Y-m-d', 'decimal_separator' => 'dot', 'thousands_separator' => 'none', 'row_order' => 'ascending'];
        $decimal = $options['decimal_separator'] === 'dot' ? '.' : ',';
        $thousands = ['comma' => ',', 'dot' => '.', 'space' => ' ', 'none' => ''][$options['thousands_separator']];
        if ($decimal === $thousands) {
            $this->fail(1, 'Decimal and thousands separators must differ.');
        }
        if (strlen($contents) > 10 * 1024 * 1024) {
            $this->fail(1, 'Limit each CSV to 10 MB.');
        }
        if ($delimiter === 'auto') {
            $line = strtok($contents, "\r\n") ?: '';
            $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
        }
        if (! in_array($delimiter, [',', ';', "\t"], true)) {
            $this->fail(1, 'Choose comma, semicolon or tab.');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            $headers = fgetcsv($stream, 0, $delimiter);
            if (! $headers) {
                $this->fail(1, 'The file needs a header row.');
            }
            $headers = array_map(fn ($s) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $s)), $headers);
            if (count(array_unique($headers)) !== count($headers) || in_array('', $headers, true)) {
                $this->fail(1, 'Header names must be unique and nonempty.');
            }
            $mapping = array_intersect_key($mapping, array_flip(['date', 'amount', 'debit', 'credit', 'description', 'reference', 'balance']));
            $mapping = array_filter(array_map(fn ($s) => trim((string) $s), $mapping), fn ($s) => $s !== '');
            if (empty($mapping['date']) || (empty($mapping['amount']) && (empty($mapping['debit']) || empty($mapping['credit'])))) {
                $this->fail(1, 'Map date and amount, or date with both debit and credit.');
            }
            foreach ($mapping as $column) {
                if (! in_array($column, $headers, true)) {
                    $this->fail(1, 'A mapped column is missing: '.$column);
                }
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, $delimiter)) !== false) {
                $line++;
                if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    $this->fail($line, 'Column count differs from the header. Quote text containing delimiters.');
                }
                if (count($rows) >= 10000) {
                    $this->fail($line, 'Limit each staged import to 10,000 movements.');
                }
                $record = array_combine($headers, $values);
                $dateValue = trim($record[$mapping['date']]);
                try {
                    $date = CarbonImmutable::createFromFormat('!'.$options['date_format'], $dateValue);
                } catch (\Throwable) {
                    $date = null;
                }
                if (! $date || $date->format($options['date_format']) !== $dateValue) {
                    $this->fail($line, 'Date does not match the selected format '.$options['date_format'].'.');
                }
                if (isset($mapping['amount'])) {
                    $amount = $this->number($record[$mapping['amount']], $decimal, $thousands, $line);
                } else {
                    $credit = $this->number($record[$mapping['credit']], $decimal, $thousands, $line, true);
                    $debit = $this->number($record[$mapping['debit']], $decimal, $thousands, $line, true);
                    if (Money::compare($credit, 0) < 0 || Money::compare($debit, 0) < 0 || (Money::compare($credit, 0) > 0 && Money::compare($debit, 0) > 0)) {
                        $this->fail($line, 'Debit and credit must be nonnegative and only one side may have an amount.');
                    }
                    $amount = Money::subtract($credit, $debit);
                }
                if (Money::compare($amount, 0) === 0) {
                    $this->fail($line, 'A zero/nonmovement row needs to be excluded in the bank export template.');
                }
                $balance = isset($mapping['balance']) ? $this->number($record[$mapping['balance']], $decimal, $thousands, $line) : null;
                if (mb_strlen(trim($record[$mapping['reference'] ?? ''] ?? '')) > 255) {
                    $this->fail($line, 'Reference exceeds 255 characters.');
                }
                if (mb_strlen(trim($record[$mapping['description'] ?? ''] ?? '')) > 2000) {
                    $this->fail($line, 'Description exceeds 2,000 characters.');
                }
                $rows[] = ['date' => $date->toDateString(), 'amount' => $amount, 'balance' => $balance, 'description' => trim($record[$mapping['description'] ?? ''] ?? ''), 'reference' => trim($record[$mapping['reference'] ?? ''] ?? ''), 'line' => $line, 'raw' => $record];
            }
            if (! $rows) {
                $this->fail(1, 'The statement contains no movements.');
            }
        } finally {
            fclose($stream);
        }
        $ordered = $options['row_order'] === 'descending' ? array_reverse($rows) : $rows;
        $prior = null;
        $balance = null;
        foreach ($ordered as $r) {
            if ($prior !== null && $r['date'] < $prior) {
                $this->fail($r['line'], 'Dates do not follow the selected row order.');
            }
            if ($balance !== null && $r['balance'] !== null && Money::compare(Money::add($balance, $r['amount']), $r['balance']) !== 0) {
                $this->fail($r['line'], 'Running bank balance does not agree with the previous balance and this movement.');
            }
            $prior = $r['date'];
            $balance = $r['balance'];
        }
        $first = $ordered[0];
        $last = $ordered[count($ordered) - 1];

        return ['rows' => $rows, 'mapping' => $mapping, 'options' => $options, 'period_from' => $first['date'], 'period_to' => $last['date'],
            'opening_balance' => $first['balance'] === null ? null : Money::subtract($first['balance'], $first['amount']), 'closing_balance' => $last['balance']];
    }

    private function number(string $value, string $decimal, string $thousands, int $line, bool $blankZero = false): string
    {
        $value = trim($value);
        if ($blankZero && $value === '') {
            return '0.00';
        }
        $group = $thousands === '' ? '\d+' : '(?:\d+|\d{1,3}(?:'.preg_quote($thousands, '/').'\d{3})+)';
        if (! preg_match('/^[+-]?'.$group.'(?:'.preg_quote($decimal, '/').'\d{1,2})?$/D', $value)) {
            $this->fail($line, 'Amount does not match the selected decimal/thousands format.');
        }
        $value = $thousands === '' ? $value : str_replace($thousands, '', $value);
        $value = str_replace($decimal, '.', $value);
        if (Money::compare(Money::normalize($value), '9000000000000') > 0 || Money::compare(Money::normalize($value),'-9000000000000') < 0) {
            $this->fail($line,'Amount exceeds the supported bank movement limit.');
        }

        return Money::normalize($value);
    }

    private function fail(int $line,string $message): never
    {
        throw ValidationException::withMessages(['file' => 'CSV row '.$line.': '.$message]);
    }
}
