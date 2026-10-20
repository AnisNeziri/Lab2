<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A bounded, non-mutating review. Approval never silently merges a likely duplicate. */
class PilotImportPreflight
{
    public const FIELDS = [
        'products' => ['sku', 'name', 'unit'], 'customers' => ['name'], 'suppliers' => ['name'],
        'categories' => ['name'], 'warehouse_locations' => ['warehouse_id', 'code', 'name'],
        'opening_stock' => ['sku', 'warehouse_id', 'unit', 'quantity'],
        'opening_balances' => ['source_reference', 'account_code', 'currency', 'amount', 'as_of'],
    ];

    private function normalize(mixed $value, bool $phone = false): string
    {
        return preg_replace($phone ? '/[^0-9]+/' : '/[^\pL\pN]+/u', '', mb_strtolower(trim((string) $value)));
    }

    public function inspect(string $dataset, UploadedFile $file): array
    {
        app(PilotService::class)->owner();
        $required = self::FIELDS[$dataset];
        $handle = fopen($file->getRealPath(), 'rb');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header || count($header) > 50) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Use a CSV with a header and at most 50 columns.']);
        }
        $header = array_map(fn ($v) => trim(ltrim((string) $v, "\xEF\xBB\xBF")), $header);
        if (count(array_unique($header)) !== count($header) || array_diff($required, $header)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Missing or repeated CSV headers. Required: '.implode(', ', $required)]);
        }
        $index = [];
        if (in_array($dataset, ['products', 'customers', 'suppliers', 'categories'])) {
            if (DB::table($dataset)->where('company_id', Auth::user()->company_id)->count() > 100000) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'This catalogue exceeds the preflight identity limit. Ask support for a scoped import review. Nothing has been imported.']);
            }
            $fields = match ($dataset) {
                'products' => ['sku', 'name'], 'customers' => ['name', 'business_name', 'phone', 'business_registration_number', 'tax_number'], 'suppliers' => ['name', 'phone'], default => ['name']
            };
            // Stream existing identity fields only, bounded memory. No record contents returned.
            foreach (DB::table($dataset)->where('company_id', Auth::user()->company_id)->orderBy('id')->cursor(['id', ...$fields]) as $record) {
                foreach ($fields as $field) {
                    $key = $this->normalize($record->$field, $field === 'phone');
                    if ($key !== '') {
                        $index[$field][$key] ??= (int) $record->id;
                    }
                }
            }
        }
        $summary = ['total_rows' => 0, 'accepted_rows' => 0, 'rejected_rows' => 0, 'warning_rows' => 0, 'duplicate_candidates' => 0, 'missing_required_fields' => 0];
        $details = [];
        $seen = [];
        $warehouseIds = DB::table('warehouses')->where('company_id', Auth::user()->company_id)->pluck('id')->flip()->all();
        $stockUnits = [];
        try {
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (++$summary['total_rows'] > 10000) {
                    throw ValidationException::withMessages(['file' => 'Split this import into batches of at most 10,000 rows. Nothing has been imported.']);
                }
                $row = $summary['total_rows'] + 1;
                $errors = [];
                $warnings = [];
                $duplicates = [];
                if (count($values) !== count($header) || array_sum(array_map(fn ($v) => strlen((string) $v), $values)) > 65536) {
                    $errors[] = 'column_count_or_row_size';
                    $data = [];
                } else {
                    $data = array_combine($header, $values);
                }
                if (! mb_check_encoding(implode('', $values), 'UTF-8')) {
                    $errors[] = 'invalid_utf8';
                }
                $missing = [];
                foreach ($required as $field) {
                    if (trim((string) ($data[$field] ?? '')) === '') {
                        $missing[] = $field;
                    }
                }
                if ($missing) {
                    $errors[] = 'missing_required_fields';
                    $summary['missing_required_fields'] += count($missing);
                }
                foreach (['quantity', 'amount'] as $field) {
                    if (isset($data[$field]) && ! preg_match('/^-?\d+(\.\d{1,6})?$/D', trim($data[$field]))) {
                        $errors[] = 'invalid_'.$field;
                    }
                }
                if ($dataset === 'opening_stock' && isset($data['quantity']) && is_numeric($data['quantity']) && $data['quantity'] < 0) {
                    $errors[] = 'negative_opening_stock';
                }
                if (isset($data['warehouse_id']) && ! isset($warehouseIds[$data['warehouse_id']])) {
                    $errors[] = 'unknown_company_warehouse';
                }
                if ($dataset === 'opening_stock') {
                    $sku = $data['sku'] ?? '';
                    if (! array_key_exists($sku, $stockUnits)) {
                        $stockUnits[$sku] = DB::table('products')->where('company_id', Auth::user()->company_id)->where('sku', $sku)->value('unit');
                    }
                    if ($stockUnits[$sku] === null || $stockUnits[$sku] !== ($data['unit'] ?? '')) {
                        $errors[] = 'unknown_sku_or_unit_mismatch';
                    }
                }
                if ($dataset === 'opening_balances') {
                    $parts = explode('-', $data['as_of'] ?? '');
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['as_of'] ?? '') || ! checkdate((int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0), (int) ($parts[0] ?? 0))) {
                        $errors[] = 'invalid_as_of';
                    }
                    if (! preg_match('/^[A-Z]{3}$/D', $data['currency'] ?? '')) {
                        $errors[] = 'invalid_currency';
                    }
                    $warnings[] = 'accountant_approval_and_reconciliation_required';
                }
                $identity = match ($dataset) {
                    'products' => ['sku', 'name'], 'customers' => ['name', 'business_name', 'phone', 'business_registration_number', 'tax_number'], 'suppliers' => ['name', 'phone'], 'categories' => ['name'], 'warehouse_locations' => ['warehouse_id', 'code'], 'opening_stock' => ['sku', 'warehouse_id'], default => ['source_reference']
                };
                foreach ($identity as $field) {
                    $key = $this->normalize($data[$field] ?? '', $field === 'phone');
                    if ($key === '') {
                        continue;
                    }
                    if (isset($index[$field][$key])) {
                        $duplicates[] = ['field' => $field, 'existing_id' => $index[$field][$key]];
                    }
                    if (isset($seen[$field][$key])) {
                        $duplicates[] = ['field' => $field, 'csv_row' => $seen[$field][$key]];
                    }
                    $seen[$field][$key] ??= $row;
                }
                // Warehouse and opening-stock identities are composite; sharing a warehouse is expected.
                if (in_array($dataset, ['warehouse_locations', 'opening_stock'])) {
                    $duplicates = [];
                    $key = ($data['warehouse_id'] ?? '').'|'.($data[$dataset === 'opening_stock' ? 'sku' : 'code'] ?? '').'|'.($data['location_code'] ?? '');
                    if (isset($seen['composite'][$key])) {
                        $duplicates[] = ['field' => 'composite_identity', 'csv_row' => $seen['composite'][$key]];
                    }
                    $seen['composite'][$key] ??= $row;
                }
                if ($dataset === 'opening_stock') {
                    $warnings[] = 'count_source_cost_and_location_review_required';
                }
                if ($duplicates) {
                    $warnings[] = 'review_duplicate_candidates_no_automatic_merge';
                    $summary['duplicate_candidates']++;
                }
                if ($warnings) {
                    $summary['warning_rows']++;
                }
                $summary[$errors ? 'rejected_rows' : 'accepted_rows']++;
                if (count($details) < 250) {
                    $details[] = ['row' => $row, 'result' => $errors ? 'rejected' : 'accepted_for_review', 'missing_fields' => $missing, 'errors' => array_values(array_unique($errors)), 'warnings' => $warnings, 'duplicates' => $duplicates];
                }
            }
        } finally {
            fclose($handle);
        }
        if (! $summary['total_rows']) {
            throw ValidationException::withMessages(['file' => 'No data rows found.']);
        }

        return ['dataset' => $dataset, 'sha256' => hash_file('sha256', $file->getRealPath()), 'summary' => $summary, 'rows' => $details, 'detail_limit' => 250, 'omitted_detail_rows' => max(0, $summary['total_rows'] - 250), 'imported_rows' => 0,
            'basis' => 'Preflight only. Accepted means structurally reviewable, not approved or imported. Review every duplicate and retain the source hash. Use existing domain imports/services; opening finance needs accountant approval. XLSX must first be exported to UTF-8 CSV.'];
    }
}
