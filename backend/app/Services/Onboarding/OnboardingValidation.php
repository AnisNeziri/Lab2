<?php

namespace App\Services\Onboarding;

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\OnboardingRow;
use App\Models\OnboardingSession;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\UnitConversionService;
use App\Support\BarcodeIdentity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnboardingValidation
{
    public const MODELS = ['products' => Product::class, 'customers' => Customer::class, 'suppliers' => Supplier::class, 'warehouses' => Warehouse::class, 'locations' => WarehouseLocation::class, 'cash_bank' => FinancialAccount::class];

    private array $existing = [];

    private array $indexes = [];

    private array $stagedIdentifiers = [];

    private array $stagedMatches = [];

    public function validate(int $id, int $revision): array
    {
        $service = app(OnboardingService::class);
        $service->permit('manage');

        return DB::transaction(function () use ($id, $revision, $service) {
            $s = $service->lock($id);
            $service->revision($s, $revision);
            $service->editable($s);
            if ($s->batches()->where('is_active', true)->where('status', '!=', 'staged')->exists()) {
                throw ValidationException::withMessages(['file' => 'Finish or intentionally reprocess the failed source before validation.']);
            }
            $service->invalidate($s, 'validation.started');
            $s->update(['status' => 'validating']);
            $this->loadExisting();
            $this->stagedIdentifiers = [];
            $this->stagedMatches = [];
            $sourceBatches = $s->batches()->where('is_active', true)->get()->keyBy('id');
            foreach ($s->rows()->active()->whereIn('domain', array_keys(self::MODELS))->cursor() as $source) {
                if (($source->resolution['action'] ?? '') === 'skip') {
                    continue;
                }
                $mapped = [];
                foreach (OnboardingSchema::DOMAINS[$source->domain] as $field) {
                    $column = $sourceBatches->get($source->onboarding_batch_id)?->mapping[$field] ?? null;
                    $mapped[$field] = trim((string) ($source->overrides[$field] ?? ($column ? $source->raw[$column] ?? '' : '')));
                }
                if (! empty($mapped['phone'])) {
                    $mapped['phone'] = preg_replace('/[\s()\-]+/', '', $mapped['phone']);
                }
                foreach ($this->identities($source->domain, $mapped) as $identity) {
                    $this->stagedMatches[$source->domain][$identity][] = ['row_id' => $source->id, 'name' => $mapped['name'] ?? ''];
                }
                if ($source->domain !== 'products') {
                    continue;
                }
                foreach (['sku', 'barcode'] as $field) {
                    $column = $sourceBatches->get($source->onboarding_batch_id)?->mapping[$field] ?? null;
                    $value = trim((string) ($source->overrides[$field] ?? ($column ? $source->raw[$column] ?? '' : '')));
                    if ($value !== '') {
                        $key = $field.':'.($field === 'barcode' ? BarcodeIdentity::normalize($value) : $this->identity($value));
                        $this->stagedIdentifiers[$key] = ($this->stagedIdentifiers[$key] ?? 0) + 1;
                    }
                }
            }
            // Master rows normalize before their referencing opening positions.
            foreach (array_keys(OnboardingSchema::DOMAINS) as $domain) {
                OnboardingRow::active()->where('onboarding_session_id', $s->id)->where('domain', $domain)->with('batch')->orderBy('id')->chunkById(100, function ($rows) use ($s) {
                    foreach ($rows as $row) {
                        $this->validateRow($s, $row);
                    }
                });
                // Location parents may appear later in the uploaded file.
                if ($domain === 'locations') {
                    OnboardingRow::active()->where('onboarding_session_id', $s->id)->where('domain', $domain)->with('batch')->orderBy('id')->chunkById(100, function ($rows) use ($s) {
                        foreach ($rows as $row) {
                            $this->validateRow($s, $row);
                        }
                    });
                }
            }
            $duplicates = OnboardingRow::active()->where('onboarding_session_id', $s->id)->where('status', '!=', 'skipped')->whereNotNull('external_ref')->selectRaw('domain, external_ref, COUNT(*) as total')->groupBy('domain', 'external_ref')->havingRaw('COUNT(*) > 1')->get();
            foreach ($duplicates as $duplicate) {
                foreach (OnboardingRow::active()->where('onboarding_session_id', $s->id)->where('domain', $duplicate->domain)->where('external_ref', $duplicate->external_ref)->where('status', '!=', 'skipped')->get() as $row) {
                    $row->update(['status' => 'error', 'errors' => [...($row->errors ?? []), 'duplicate_source_reference']]);
                }
            }
            foreach ($s->batches()->where('is_active', true)->get() as $batch) {
                $counts = $batch->rows()->selectRaw('status,COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
                $batch->update(['accepted_count' => $counts['valid'] ?? 0, 'warning_count' => $batch->rows()->whereNotNull('warnings')->where('warnings', '!=', '[]')->count(), 'error_count' => ($counts['error'] ?? 0) + ($counts['review'] ?? 0)]);
            }
            $report = app(OnboardingReconciliation::class)->report($s->fresh());
            $ready = $report['ready_for_review'];
            $s->update(['status' => $ready ? 'ready_for_approval' : 'needs_review', 'stage' => 'reconciliation', 'review' => ['dry_run' => $report, 'at' => now()->toIso8601String()]]);
            $service->event($s, 'validation.completed', ['ready' => $ready, 'counts' => $report['counts'], 'totals' => $report['totals']]);

            return $service->detail($s->id);
        });
    }

    private function loadExisting(): void
    {
        foreach (self::MODELS as $domain => $class) {
            $this->existing[$domain] = $class::query()->get()->keyBy('id');
            $this->indexes[$domain] = [];
            foreach ($this->existing[$domain] as $record) {
                foreach ($this->identities($domain, $record->getAttributes()) as $key) {
                    $this->indexes[$domain][$key][] = $record->id;
                }
            }
        }
        foreach (ProductBarcode::where('is_active', true)->get() as $barcode) {
            $this->indexes['products']['barcode:'.BarcodeIdentity::normalize($barcode->barcode)][] = $barcode->product_id;
        }
    }

    private function identities(string $domain, array $data): array
    {
        $fields = match ($domain) {
            'products' => ['sku', 'barcode', 'name'],'customers' => ['email', 'phone', 'tax_number', 'business_name', 'name'],'suppliers' => ['email', 'phone', 'name'],'warehouses','locations' => ['code', 'name'],'cash_bank' => ['name']
        };
        $keys = [];
        foreach ($fields as $field) {
            if (trim((string) ($data[$field] ?? '')) !== '') {
                $value = $field === 'barcode' ? BarcodeIdentity::normalize($data[$field]) : $this->identity((string) $data[$field]);
                if (in_array($field, ['name', 'business_name'], true)) {
                    $value = preg_replace('/[^a-z0-9]/', '', Str::ascii($value));
                }
                if ($field === 'phone') {
                    $value = preg_replace('/[\s()\-]+/', '', $value);
                }
                $keys[] = $field.':'.$value;
            }
        }

        return $keys;
    }

    private function identity(string $s): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    private function validateRow(OnboardingSession $s, OnboardingRow $row): void
    {
        $data = [];
        $errors = [];
        $warnings = [];
        $candidates = [];
        if (($row->resolution['action'] ?? '') === 'skip') {
            $row->update(['status' => 'skipped', 'errors' => [], 'warnings' => ['excluded_with_reason'], 'normalized' => null]);

            return;
        }
        foreach (OnboardingSchema::DOMAINS[$row->domain] as $field) {
            $column = $row->batch->mapping[$field] ?? null;
            $original = $column ? ($row->raw[$column] ?? null) : null;
            if (in_array($field, ['external_ref', 'sku', 'barcode', 'code', 'product_ref', 'customer_ref', 'supplier_ref', 'warehouse_ref', 'location_ref', 'parent_ref'], true) && is_float($original) && (! isset($row->overrides[$field]) || (string) $row->overrides[$field] === (string) $original)) {
                $errors[] = 'identifier_requires_text';
            }
            $data[$field] = trim((string) ($row->overrides[$field] ?? ($column ? $row->raw[$column] ?? '' : '')));
        }
        if ($data['external_ref'] === '') {
            $data['external_ref'] = $data['sku'] ?? ($data['reference'] ?? '');
        }
        if ($data['external_ref'] === '') {
            $errors[] = 'missing_external_ref';
        }
        foreach ($data as $field => $value) {
            if (mb_strlen($value) > match ($field) {
                'external_ref','product_ref','customer_ref','supplier_ref','warehouse_ref','location_ref','parent_ref' => 160,'sku','barcode','code' => 100,'email' => 254,'name','business_name','reference' => 255,'address' => 2000,default => 160
            }) {
                $errors[] = 'too_long_'.$field;
            }
        }
        $required = match ($row->domain) {
            'products' => ['name', 'unit'],'customers','suppliers' => ['name'],'warehouses' => ['code', 'name'],'locations' => ['warehouse_ref', 'code', 'name'],'inventory' => ['product_ref', 'warehouse_ref', 'quantity', 'unit', 'unit_cost'],'receivables','customer_advances' => ['customer_ref', 'amount', 'reference'],'payables','supplier_advances' => ['supplier_ref', 'amount', 'reference'],'cash_bank' => ['name', 'type', 'amount', 'reference']
        };
        foreach ($required as $field) {
            if (($data[$field] ?? '') === '') {
                $errors[] = 'missing_'.$field;
            }
        }
        $separator = $row->batch->options['decimal_separator'] ?? '.';
        foreach (['amount' => 2, 'unit_cost' => 6, 'purchase_price' => 2, 'selling_price' => 2, 'quantity' => 3, 'conversion_factor' => 6, 'value' => 2] as $field => $scale) {
            if (isset($data[$field]) && $data[$field] !== '') {
                try {
                    $data[$field] = OnboardingSchema::number($data[$field], $separator, $scale);
                } catch (ValidationException) {
                    $errors[] = 'invalid_'.$field;
                }
            }
        }
        foreach (['amount', 'quantity'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '' && ! in_array('invalid_'.$field, $errors, true) && Money::compareDecimal($data[$field], '0') <= 0) {
                $errors[] = 'nonpositive_'.$field;
            }
        }
        foreach (['unit', 'conversion_unit'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $before = $data[$field];
                $data[$field] = OnboardingSchema::unit($before);
                if ($before !== $data[$field]) {
                    $warnings[] = 'unit_alias_normalized';
                }
                if ($row->domain === 'products' && $field === 'unit' && ! in_array($data[$field], ['pcs', 'm', 'kg', 'l', 'roll', 'box', 'pack', 'set', 'm2', 'm3'], true)) {
                    $errors[] = 'unknown_'.$field;
                }
                if ($field === 'conversion_unit' && ! preg_match('/^[\pL\pN][\pL\pN_.-]{0,29}$/u', $data[$field])) {
                    $errors[] = 'invalid_conversion_unit';
                }
            }
        }
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'invalid_email';
        }
        if (! empty($data['phone'])) {
            $data['phone'] = preg_replace('/[\s()\-]+/', '', $data['phone']);
        }
        if (array_key_exists('currency', $data)) {
            $data['currency'] = strtoupper($data['currency'] ?: $s->currency);
            if ($data['currency'] !== $s->currency) {
                $errors[] = 'foreign_currency_requires_reviewed_conversion';
            }
        }
        if (! empty($data['due_date'])) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.($row->batch->options['date_format'] ?? 'Y-m-d'), $data['due_date']);
                if (! $date || $date->format($row->batch->options['date_format'] ?? 'Y-m-d') !== $data['due_date']) {
                    throw new \RuntimeException;
                }$data['due_date'] = $date->toDateString();
            } catch (\Throwable) {
                $errors[] = 'invalid_due_date';
            }
        }
        if ($row->domain === 'products') {
            $data['tracking_mode'] = $data['tracking_mode'] ?: 'none';
            if (! in_array($data['tracking_mode'], ['none', 'batch', 'serial'], true)) {
                $errors[] = 'invalid_tracking_mode';
            }
            if ($data['sku'] === '' && $data['barcode'] === '') {
                $errors[] = 'missing_product_identifier';
            }
            if (($data['conversion_unit'] === '') !== ($data['conversion_factor'] === '')) {
                $errors[] = 'incomplete_conversion';
            }
            if ($data['conversion_unit'] !== '' && $data['conversion_unit'] === $data['unit']) {
                $errors[] = 'invalid_conversion_unit';
            }
            if ($data['conversion_factor'] !== '' && ! in_array('invalid_conversion_factor', $errors, true) && Money::compareDecimal($data['conversion_factor'], '0') <= 0) {
                $errors[] = 'invalid_conversion_factor';
            }
            foreach (['sku', 'barcode'] as $key) {
                if ($data[$key] !== '') {
                    $identity = $key.':'.($key === 'barcode' ? BarcodeIdentity::normalize($data[$key]) : $this->identity($data[$key]));
                    if (($this->stagedIdentifiers[$identity] ?? 0) > 1) {
                        $errors[] = 'duplicate_'.$key;
                    }
                }
            }
        }
        if (isset(self::MODELS[$row->domain])) {
            foreach ($this->identities($row->domain, $data) as $key) {
                $ids = $this->indexes[$row->domain][$key] ?? [];
                foreach ($ids as $candidate) {
                    $candidates[$candidate] = ['id' => $candidate, 'name' => $this->existing[$row->domain]->get($candidate)?->name, 'evidence' => strtok($key, ':')];
                }if ($ids && str_starts_with($key, 'sku:')) {
                    break;
                }
            }
            if (! $candidates && ! empty($data['name'])) { // Bounded fuzzy candidate scan; suggestions never choose a record.
                $needle = $this->identity($data['name']);
                $scanned = 0;
                foreach ($this->existing[$row->domain] as $record) {
                    if (substr($this->identity($record->name ?? ''), 0, 1) !== substr($needle, 0, 1)) {
                        continue;
                    }if (++$scanned > 100) {
                        break;
                    }$distance = levenshtein(Str::ascii($needle), Str::ascii($this->identity($record->name ?? '')));
                    if ($distance <= 2) {
                        $candidates[$record->id] = ['id' => $record->id, 'name' => $record->name, 'evidence' => 'similar_name'];
                    }if (count($candidates) >= 5) {
                        break;
                    }
                }
            }
            foreach ($this->identities($row->domain, $data) as $identity) {
                foreach ($this->stagedMatches[$row->domain][$identity] ?? [] as $staged) {
                    if ($staged['row_id'] === $row->id) {
                        continue;
                    }
                    $candidates['staged_'.$staged['row_id']] = ['id' => null, 'staged_row_id' => $staged['row_id'], 'name' => $staged['name'], 'evidence' => 'source_duplicate'];
                    if ($row->domain === 'warehouses' && str_starts_with($identity, 'code:')) {
                        $errors[] = 'duplicate_code';
                    }
                    if (count($candidates) >= 10) {
                        break 2;
                    }
                }
            }
            $action = $row->resolution['action'] ?? null;
            if ($action === 'use_existing') {
                $target = $this->existing[$row->domain]->get($row->match_id);
                if (! $target) {
                    $errors[] = 'unauthorized_or_missing_match';
                } else {
                    $data['_matched_snapshot'] = $this->snapshot($target);
                    if (($target->lifecycle_status ?? 'active') === 'archived' || isset($target->is_active) && ! $target->is_active) {
                        $errors[] = 'inactive_match';
                    }
                    if ($row->domain === 'products' && $target->unit !== $data['unit']) {
                        $errors[] = 'matched_unit_conflict';
                    }
                }
            } elseif ($action === 'create') {
                foreach ($candidates as $candidate) {
                    if (in_array($candidate['evidence'], ['sku', 'barcode', 'code'], true)) {
                        $errors[] = 'duplicate_unique_identifier';
                    }
                }
            } elseif ($candidates) {
                $warnings[] = 'duplicate_candidates_require_review';
            }
            if ($row->domain === 'cash_bank' && ! in_array($data['type'], ['cashbox', 'bank'], true)) {
                $errors[] = 'invalid_account_type';
            }
        }
        if ($row->domain === 'locations') {
            $warehouse = $this->reference($s, 'warehouses', $data['warehouse_ref']);
            if (! $warehouse) {
                $errors[] = 'unknown_warehouse_ref';
            }
            if ($row->match_id) {
                $target = $this->existing['locations']->get($row->match_id);
                if ($target && (! isset($warehouse['_id']) || (int) $target->warehouse_id !== (int) $warehouse['_id'])) {
                    $errors[] = 'matched_location_warehouse_conflict';
                }
            }
            $parentTypes = ['zone' => null, 'rack' => 'zone', 'shelf' => 'rack', 'bin' => 'shelf'];
            if (! array_key_exists($data['type'], $parentTypes)) {
                $errors[] = 'invalid_location_type';
            } elseif ($data['type'] === 'zone') {
                if ($data['parent_ref'] !== '') {
                    $errors[] = 'zone_cannot_have_parent';
                }
            } else {
                $parent = $this->reference($s, 'locations', $data['parent_ref']);
                if (! $parent || ($parent['type'] ?? '') !== $parentTypes[$data['type']]) {
                    $errors[] = 'invalid_location_parent';
                } elseif (isset($parent['warehouse_ref']) && $parent['warehouse_ref'] !== $data['warehouse_ref']) {
                    $errors[] = 'location_warehouse_mismatch';
                }
            }
        }
        if ($row->domain === 'inventory') {
            $product = $this->reference($s, 'products', $data['product_ref']);
            if ($product) {
                $data['_product_key'] = isset($product['_id']) ? 'existing:'.$product['_id'] : 'staged:'.($product['_row_id'] ?? $data['product_ref']);
            }
            $warehouse = $this->reference($s, 'warehouses', $data['warehouse_ref']);
            if (! $product) {
                $errors[] = 'unknown_product_ref';
            }if (! $warehouse) {
                $errors[] = 'unknown_warehouse_ref';
            }
            if ($data['location_ref'] !== '') {
                $location = $this->reference($s, 'locations', $data['location_ref']);
                if (! $location) {
                    $errors[] = 'unknown_location_ref';
                } elseif ($warehouse && isset($location['warehouse_id']) && isset($warehouse['_id']) && (int) $location['warehouse_id'] !== (int) $warehouse['_id']) {
                    $errors[] = 'location_warehouse_mismatch';
                } elseif (isset($location['warehouse_ref']) && $location['warehouse_ref'] !== $data['warehouse_ref']) {
                    $errors[] = 'location_warehouse_mismatch';
                }
            }
            $data['stock_state'] = $data['stock_state'] ?: 'available';
            if (! in_array($data['stock_state'], ['available', 'damaged', 'quarantine', 'blocked'], true)) {
                $errors[] = 'invalid_stock_state';
            }
            if ($product && ($product['tracking_mode'] ?? 'none') !== 'none') {
                $errors[] = 'tracked_opening_requires_trace_workflow';
            }
            if ($product && ! array_intersect($errors, ['invalid_quantity', 'unknown_unit', 'missing_quantity', 'missing_unit'])) {
                try {
                    $base = $product['unit'];
                    $q = $data['quantity'];
                    if ($data['unit'] !== $base) {
                        if (isset($product['_id'])) {
                            $master = Product::with('units')->findOrFail($product['_id']);
                            $definitions = $master->units->filter(fn ($u) => $u->is_active && OnboardingSchema::unit($u->code) === $data['unit']);
                            if ($definitions->count() > 1) {
                                throw new \RuntimeException('Ambiguous declared unit alias.');
                            }
                            $resolved = app(UnitConversionService::class)->resolve($master, (float) $q, $definitions->first()?->code ?? $data['unit']);
                            $data['_unit_definition'] = ['code' => $resolved['unit'], 'factor' => $resolved['conversion_factor'], 'mode' => $resolved['conversion_mode']];
                            $q = Money::normalizeDecimal($resolved['base_quantity'], 3);
                        } elseif (($product['conversion_unit'] ?? '') === $data['unit'] && Money::compareDecimal($product['conversion_factor'] ?? '0', '0') > 0) {
                            $q = Money::multiply($q, $product['conversion_factor'], 3);
                            $data['_unit_definition'] = ['code' => $product['conversion_unit'], 'factor' => $product['conversion_factor'], 'mode' => 'fixed'];
                        } else {
                            throw new \RuntimeException;
                        }
                    }
                    app(UnitConversionService::class)->assertPrecision((float) $q, $base);
                    $data['base_quantity'] = $q;
                    $data['base_unit'] = $base;
                    if (! in_array('invalid_unit_cost', $errors, true) && $data['unit_cost'] !== '') {
                        $data['calculated_value'] = Money::multiply($q, $data['unit_cost'], 6);
                        if (Money::compareDecimal($data['calculated_value'], '999999999.99') > 0) {
                            $errors[] = 'inventory_value_over_limit';
                        } elseif ($data['value'] !== '' && ! in_array('invalid_value', $errors, true) && Money::compare($data['calculated_value'], $data['value']) !== 0) {
                            $errors[] = 'inventory_value_conflict';
                        }
                    }
                } catch (\Throwable) {
                    $errors[] = 'unconfigured_or_invalid_conversion';
                }
            }
        }
        foreach (['customer_ref' => 'customers', 'supplier_ref' => 'suppliers'] as $field => $domain) {
            if (isset($data[$field]) && ! $this->reference($s, $domain, $data[$field])) {
                $errors[] = 'unknown_'.$field;
            }
        }
        if (in_array($row->domain, ['receivables', 'payables'], true) && empty($data['due_date'])) {
            $warnings[] = 'opening_due_date_unknown';
        }
        $status = $errors ? 'error' : (in_array('duplicate_candidates_require_review', $warnings, true) ? 'review' : 'valid');
        $row->update(['external_ref' => $data['external_ref'] ?: null, 'normalized' => $data, 'candidates' => array_slice(array_values($candidates), 0, 5), 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'status' => $status]);
    }

    public function snapshot($record): array
    {
        return collect($record->getAttributes())->except(['updated_at', 'created_at', 'notes', 'address', 'password', 'api_token', 'image_data', 'photo_data', 'proof_data', 'image_base64'])->sortKeys()->all();
    }

    public function reference(OnboardingSession $s, string $domain, string $ref): ?array
    {
        if ($ref === '') {
            return null;
        }
        $rows = OnboardingRow::active()->where('onboarding_session_id', $s->id)->where('domain', $domain)->where('external_ref', $ref)->where('status', '!=', 'skipped')->limit(2)->get();
        if ($rows->count() === 1) {
            $row = $rows->first();
            if ($row->match_id) {
                $class = self::MODELS[$domain];
                $existing = $class::find($row->match_id);

                return $existing ? $existing->toArray() + ['_id' => $existing->id, '_row_id' => $row->id] : null;
            }

            return $row->normalized ? ($row->normalized + ['_row_id' => $row->id] + ($row->posted_entity_id ? ['_id' => $row->posted_entity_id] : [])) : null;
        }
        if ($rows->count() > 1) {
            return null;
        }
        $class = self::MODELS[$domain];
        $fields = match ($domain) {
            'products' => ['sku', 'barcode'],'customers' => ['email', 'tax_number', 'name'],'suppliers' => ['email', 'name'],'warehouses','locations' => ['code'],'cash_bank' => ['name']
        };
        $matches = $class::where(function ($q) use ($fields, $ref) {
            foreach ($fields as $i => $field) {
                $i === 0 ? $q->where($field, $ref) : $q->orWhere($field, $ref);
            }
            if ($fields === ['sku', 'barcode']) {
                $q->orWhereHas('alternativeBarcodes', fn ($barcodes) => $barcodes->where('is_active', true)->where('barcode', $ref));
            }
        })->limit(2)->get();

        return $matches->count() === 1 ? ($matches->first()->toArray() + ['_id' => $matches->first()->id]) : null;
    }
}
