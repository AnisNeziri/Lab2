<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PortableBackupService
{
    public const FORMAT = 'aims-portable-backup';

    public const VERSION = 1;

    public const ENCRYPTED_FORMAT = 'aims-encrypted-backup';

    private const ENCRYPTION_AAD = 'AIMS-PORTABLE-BACKUP-V1';

    private const PBKDF2_ITERATIONS = 310000;

    /**
     * Tables are deliberately grouped by the feature names shown to users.
     * Authentication tokens, password reset data, sessions, jobs and caches are
     * never portable business data and are therefore not part of any module.
     */
    public const MODULES = [
        'company' => [],
        'categories' => ['categories'],
        'suppliers' => ['suppliers'],
        'products' => [
            'products', 'product_units', 'product_suppliers',
            'product_supplier_price_history',
        ],
        'inventory' => [
            'warehouse_stock', 'inventory_lots', 'inventory_trace_balances',
            'stock_movements', 'stock_movement_traces', 'inventory_count_sessions',
            'inventory_count_items', 'inventory_count_entries',
            'inventory_returns', 'inventory_return_items', 'inventory_return_events',
        ],
        'warehouses' => [
            'warehouses', 'warehouse_sections', 'warehouse_locations',
            'stock_transfers', 'stock_transfer_items', 'stock_transfer_pick_events', 'stock_transfer_receipts',
        ],
        'purchases' => [
            'purchase_orders', 'purchase_order_items', 'purchase_order_payments',
            'purchase_order_changes', 'goods_receipts', 'goods_receipt_items',
            'landed_costs', 'landed_cost_allocations', 'landed_cost_accounting_entries',
        ],
        'daily_sales' => ['daily_sales_days', 'daily_sales', 'daily_sale_items'],
        'customer_debts' => ['customer_debts', 'customer_debt_entries', 'customers', 'customer_debt_transactions'],
        'finance' => [
            'invoice_profiles', 'invoice_sequences', 'invoices', 'invoice_items',
            'payment_transactions', 'expenses', 'expense_payments',
            'supplier_invoice_items', 'supplier_invoice_payment_allocations',
            'expense_goods_receipts', 'supplier_match_events',
            'landed_cost_accounting_entries',
            'financial_accounts', 'financial_account_transactions',
            'financial_account_transfers', 'bank_statements', 'bank_statement_rows',
            'bank_reconciliation_events',
        ],
        'shipments' => [
            'shipments', 'shipment_histories', 'shipment_containers',
            'shipment_items', 'shipment_documents',
        ],
    ];

    /** Tables must be inserted in this order and removed in reverse order. */
    private const TABLE_ORDER = [
        'users',
        'categories', 'suppliers', 'warehouses', 'warehouse_sections', 'warehouse_locations',
        'customers', 'customer_debts', 'products', 'product_units',
        'product_suppliers', 'product_supplier_price_history', 'warehouse_stock',
        'inventory_lots', 'inventory_trace_balances',
        'daily_sales_days', 'daily_sales', 'daily_sale_items',
        'financial_accounts', 'invoice_profiles', 'invoice_sequences', 'invoices', 'invoice_items', 'payment_transactions',
        'expenses', 'expense_payments', 'customer_debt_entries', 'customer_debt_transactions',
        'purchase_orders', 'purchase_order_items', 'purchase_order_payments', 'purchase_order_changes',
        'supplier_invoice_payment_allocations',
        'goods_receipts', 'goods_receipt_items',
        'landed_costs', 'landed_cost_allocations', 'landed_cost_accounting_entries',
        'supplier_invoice_items', 'expense_goods_receipts', 'supplier_match_events',
        'stock_transfers', 'stock_transfer_items', 'stock_transfer_pick_events', 'stock_transfer_receipts', 'stock_movements',
        'stock_movement_traces', 'inventory_count_sessions', 'inventory_count_items', 'inventory_count_entries',
        'inventory_returns', 'inventory_return_items', 'inventory_return_events',
        'shipments', 'shipment_histories', 'shipment_containers', 'shipment_items', 'shipment_documents',
        'financial_account_transfers', 'financial_account_transactions',
        'bank_statements', 'bank_statement_rows', 'bank_reconciliation_events',
    ];

    /** Child tables that do not carry company_id are owned through this parent. */
    private const OWNER_RELATIONS = [
        'customer_debt_entries' => ['customer_debt_id', 'customer_debts'],
        'invoice_items' => ['invoice_id', 'invoices'],
        'purchase_order_items' => ['purchase_order_id', 'purchase_orders'],
        'purchase_order_payments' => ['purchase_order_id', 'purchase_orders'],
        'purchase_order_changes' => ['purchase_order_id', 'purchase_orders'],
        'goods_receipt_items' => ['goods_receipt_id', 'goods_receipts'],
        'stock_transfer_items' => ['stock_transfer_id', 'stock_transfers'],
        'inventory_return_items' => ['inventory_return_id', 'inventory_returns'],
        'expense_goods_receipts' => ['expense_id', 'expenses'],
    ];

    private const IDENTITY_COLUMNS = [
        'categories' => ['company_id', 'name'],
        'suppliers' => ['company_id', 'name'],
        'warehouse_sections' => ['warehouse_id', 'code'],
        'warehouse_locations' => ['warehouse_id', 'path'],
        'product_units' => ['product_id', 'code'],
        'product_suppliers' => ['company_id', 'product_id', 'supplier_id'],
        'product_supplier_price_history' => ['product_supplier_id', 'effective_at', 'purchase_price', 'currency'],
        'warehouse_stock' => ['warehouse_id', 'product_id', 'location_key'],
        'inventory_lots' => ['company_id', 'product_id', 'identity_key'],
        'inventory_trace_balances' => ['inventory_lot_id', 'warehouse_id', 'location_key', 'stock_state'],
        'stock_movement_traces' => ['stock_movement_id', 'inventory_lot_id'],
        'inventory_count_sessions' => ['company_id', 'count_number'],
        'inventory_count_items' => ['inventory_count_session_id', 'product_id', 'location_key', 'lot_key', 'stock_state'],
        'inventory_count_entries' => ['inventory_count_item_id', 'count_round', 'entry_type', 'entered_at'],
        'daily_sales_days' => ['company_id', 'sale_date'],
        'daily_sales' => ['company_id', 'sale_number'],
        'invoice_profiles' => ['company_id'],
        'invoice_sequences' => ['company_id', 'document_type', 'year'],
        'invoices' => ['company_id', 'invoice_number'],
        'expenses' => ['company_id', 'vendor_key', 'document_type', 'document_number_normalized'],
        'customer_debts' => ['company_id', 'customer_name'],
        'purchase_orders' => ['company_id', 'po_number'],
        'goods_receipts' => ['company_id', 'receipt_number'],
        'stock_transfers' => ['company_id', 'transfer_number'],
        'stock_transfer_pick_events' => ['company_id', 'idempotency_key'],
        'landed_cost_allocations' => ['landed_cost_id', 'goods_receipt_item_id'],
        'landed_cost_accounting_entries' => ['landed_cost_allocation_id'],
        'financial_accounts' => ['company_id', 'name'],
        'financial_account_transactions' => ['company_id', 'idempotency_key'],
        'financial_account_transfers' => ['company_id', 'idempotency_key'],
        'bank_statements' => ['company_id', 'financial_account_id', 'file_hash'],
        'bank_statement_rows' => ['bank_statement_id', 'row_hash'],
        'bank_reconciliation_events' => ['bank_statement_row_id', 'action', 'created_at'],
        'inventory_returns' => ['company_id', 'return_number'],
        'inventory_return_events' => ['inventory_return_id', 'action', 'created_at'],
        'expense_goods_receipts' => ['expense_id', 'goods_receipt_id'],
        'supplier_match_events' => ['expense_id', 'action', 'created_at'],
        'supplier_invoice_payment_allocations' => ['purchase_order_payment_id', 'expense_id'],
    ];

    public function __construct(private readonly InvoiceService $invoices) {}

    public function moduleCatalog(): array
    {
        return collect(self::MODULES)->map(fn (array $tables, string $id) => [
            'id' => $id,
            'tables' => array_values(array_filter($tables, fn (string $table) => Schema::hasTable($table))),
        ])->values()->all();
    }

    public function export(?array $requestedModules = null, ?string $passphrase = null): Response
    {
        $user = Auth::user();
        abort_unless($user?->company_id, 403, 'Select an explicit company context before creating a backup.');

        $modules = $this->normalizeModules($requestedModules);
        $company = Company::query()->findOrFail($user->company_id);
        $coreTables = $this->tablesForModules($modules);
        $rawData = [];

        foreach ($coreTables as $table) {
            $rawData[$table] = $this->ownedRows($table, (int) $company->id);
        }

        $rawData = $this->includeReferencedRows($rawData, (int) $company->id);
        [$data, $encodings] = $this->prepareForArchive($rawData);
        $tables = $this->sortTables(array_keys($data));
        $data = array_replace(array_fill_keys($tables, []), $data);

        $manifest = [
            'modules' => $modules,
            'module_tables' => collect(self::MODULES)->only($modules)->all(),
            'tables' => $tables,
            'counts' => collect($data)->map(fn (array $rows) => count($rows))->all(),
            'company' => [
                'name' => $company->name,
                'address' => $company->address,
            ],
            'app_version' => (string) config('app.version', '1.0.0'),
            'database_schema' => 'AIMS-'.self::VERSION,
        ];
        $payload = ['data' => $data, 'encodings' => $encodings];
        $archive = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'manifest' => $manifest,
            'payload' => $payload,
        ];
        $archive['checksum'] = 'sha256:'.hash('sha256', $this->canonicalJson($archive));
        $json = json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $suffix = count($modules) === count(self::MODULES) ? 'full' : implode('-', $modules);
        $filename = 'AIMS-'.$suffix.'-'.now()->format('Ymd-His').'.aimsbackup';

        if ($passphrase !== null) {
            if (mb_strlen($passphrase) < 12 || mb_strlen($passphrase) > 200) {
                throw ValidationException::withMessages([
                    'passphrase' => 'Use a backup passphrase between 12 and 200 characters.',
                ]);
            }
            $json = $this->encryptArchive($json, $passphrase);
        }

        return response($json, 200, [
            'Content-Type' => $passphrase === null
                ? 'application/vnd.aims.backup+json'
                : 'application/vnd.aims.backup+encrypted',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($json),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function restore(
        UploadedFile $file,
        ?array $requestedModules = null,
        string $mode = 'merge',
        ?string $passphrase = null,
    ): array {
        $user = Auth::user();
        abort_unless($user?->company_id, 403, 'Select an explicit company context before restoring a backup.');

        if (! in_array($mode, ['merge', 'replace'], true)) {
            throw ValidationException::withMessages(['mode' => 'Mode must be merge or replace.']);
        }

        try {
            $contents = file_get_contents($file->getRealPath());
            $archive = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (($archive['format'] ?? null) === self::ENCRYPTED_FORMAT) {
                if ($passphrase === null || $passphrase === '') {
                    throw ValidationException::withMessages(['passphrase' => 'Enter the passphrase used to create this encrypted backup.']);
                }
                $archive = json_decode($this->decryptArchive($archive, $passphrase), true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The selected file is not a valid AIMS backup.']);
        }

        $this->validateArchive($archive);
        $availableModules = $this->normalizeModules($archive['manifest']['modules'] ?? []);
        $modules = $requestedModules === null || $requestedModules === []
            ? $availableModules
            : $this->normalizeModules($requestedModules);

        if (array_diff($modules, $availableModules)) {
            throw ValidationException::withMessages(['modules' => 'The backup does not contain every selected module.']);
        }
        if ($mode === 'replace' && array_diff(array_keys(self::MODULES), $modules) !== []) {
            throw ValidationException::withMessages([
                'mode' => 'Replace mode is only available when restoring the complete operational backup. Use merge for selected modules.',
            ]);
        }

        $archiveData = $this->decodeArchiveRows(
            $archive['payload']['data'],
            $archive['payload']['encodings'] ?? []
        );
        $coreTables = $this->tablesForModules($modules);
        $activeData = $this->selectRowsWithDependencies($archiveData, $coreTables);
        $companyId = (int) $user->company_id;
        $warnings = [];
        $imported = [];

        if ($mode === 'merge') {
            $this->assertMergeIsSafe($activeData, $companyId);
        }

        DB::transaction(function () use (
            $archive, $modules, $mode, $coreTables, $activeData, $companyId, $user, &$warnings, &$imported
        ) {
            if (in_array('company', $modules, true)) {
                $companyData = $archive['manifest']['company'] ?? [];
                Company::query()->whereKey($companyId)->update([
                    'name' => Str::limit(trim((string) $companyData['name']), 255, ''),
                    'address' => trim((string) ($companyData['address'] ?? '')),
                ]);
            }

            if ($mode === 'replace') {
                $this->deleteCurrentModuleRows($coreTables, $companyId);
            }

            $idMap = ['companies' => []];
            $sourceCompanyId = $this->sourceCompanyId($activeData);
            if ($sourceCompanyId !== null) {
                $idMap['companies'][(string) $sourceCompanyId] = $companyId;
            }
            $deferred = [];

            $importTables = array_keys($activeData);
            foreach ($this->sortTables($importTables) as $table) {
                if (! Schema::hasTable($table)) {
                    throw ValidationException::withMessages(['file' => "This installation is missing the {$table} table required by the backup."]);
                }

                foreach ($activeData[$table] as $row) {
                    $sourceId = $row['id'] ?? null;

                    [$values, $rowDeferred] = $this->mapRow(
                        $table,
                        $row,
                        $companyId,
                        (int) $user->id,
                        $idMap,
                        $importTables,
                    );
                    $targetId = $this->upsertPortableRow($table, $values, $mode, in_array($table, $coreTables, true));

                    if ($sourceId !== null && $targetId !== null) {
                        $idMap[$table][(string) $sourceId] = $targetId;
                    }
                    foreach ($rowDeferred as $column => [$foreignTable, $oldForeignId]) {
                        $deferred[] = [$table, $targetId, $column, $foreignTable, $oldForeignId];
                    }
                    $imported[$table] = ($imported[$table] ?? 0) + 1;
                }
            }

            foreach ($deferred as [$table, $targetId, $column, $foreignTable, $oldForeignId]) {
                $mapped = $idMap[$foreignTable][(string) $oldForeignId] ?? null;
                if ($targetId && $mapped) {
                    DB::table($table)->where('id', $targetId)->update([$column => $mapped]);
                } elseif ($targetId) {
                    throw ValidationException::withMessages([
                        'file' => "The backup could not remap {$table}.{$column} to its {$foreignTable} record.",
                    ]);
                }
            }

            foreach (array_values($idMap['invoices'] ?? []) as $invoiceId) {
                $invoice = Invoice::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->find($invoiceId);
                if ($invoice) {
                    $this->invoices->recomputeIntegrityAfterPortableRestore($invoice);
                }
            }
        }, 3);

        return [
            'message' => 'AIMS backup restored successfully.',
            'mode' => $mode,
            'modules' => $modules,
            'imported' => $imported,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function normalizeModules(?array $modules): array
    {
        if ($modules === null || $modules === [] || in_array('full', $modules, true)) {
            return array_keys(self::MODULES);
        }

        $modules = array_values(array_unique(array_filter(array_map(
            fn ($module) => strtolower(trim((string) $module)),
            $modules
        ))));
        $unknown = array_diff($modules, array_keys(self::MODULES));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['modules' => 'Unknown backup module: '.implode(', ', $unknown).'.']);
        }

        return array_values(array_intersect(array_keys(self::MODULES), $modules));
    }

    private function tablesForModules(array $modules): array
    {
        $tables = [];
        foreach ($modules as $module) {
            foreach (self::MODULES[$module] as $table) {
                if (Schema::hasTable($table)) {
                    $tables[$table] = true;
                }
            }
        }

        return $this->sortTables(array_keys($tables));
    }

    private function sortTables(array $tables): array
    {
        $positions = array_flip(self::TABLE_ORDER);
        usort($tables, fn (string $a, string $b) => ($positions[$a] ?? PHP_INT_MAX) <=> ($positions[$b] ?? PHP_INT_MAX));

        return array_values(array_unique($tables));
    }

    private function ownedRows(string $table, int $companyId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $query = DB::table($table);
        if ($table === 'users' || Schema::hasColumn($table, 'company_id')) {
            $query->where($table.'.company_id', $companyId);
        } elseif (isset(self::OWNER_RELATIONS[$table])) {
            [$foreignKey, $parentTable] = self::OWNER_RELATIONS[$table];
            $parentIds = collect($this->ownedRows($parentTable, $companyId))->pluck('id')->filter()->all();
            if ($parentIds === []) {
                return [];
            }
            $query->whereIn($foreignKey, $parentIds);
        } else {
            // A table without a tenant boundary is system configuration, not a
            // company backup. Never risk exporting another company's data.
            return [];
        }

        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        } else {
            foreach (self::IDENTITY_COLUMNS[$table] ?? [] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $query->orderBy($column);
                }
            }
        }

        return $query->get()->map(fn ($row) => (array) $row)->all();
    }

    private function includeReferencedRows(array $data, int $companyId): array
    {
        $allowed = array_fill_keys(array_unique(array_merge(...array_values(self::MODULES))), true);
        $changed = true;

        while ($changed) {
            $changed = false;
            foreach ($data as $table => $rows) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    $column = $foreign['columns'][0] ?? null;
                    $foreignTable = $foreign['foreign_table'] ?? null;
                    if (! $column || ! $foreignTable || $foreignTable === 'companies' || ! isset($allowed[$foreignTable])) {
                        continue;
                    }

                    $ids = collect($rows)->pluck($column)->filter(fn ($id) => $id !== null)->unique()->values();
                    if ($ids->isEmpty()) {
                        continue;
                    }
                    $owned = collect($this->ownedRows($foreignTable, $companyId))->keyBy('id');
                    $existing = collect($data[$foreignTable] ?? [])->keyBy('id');
                    foreach ($ids as $id) {
                        if (! $existing->has($id) && $owned->has($id)) {
                            $data[$foreignTable][] = $owned->get($id);
                            $existing->put($id, $owned->get($id));
                            $changed = true;
                        }
                    }
                }
            }
        }

        return $data;
    }

    private function prepareForArchive(array $rawData): array
    {
        $data = [];
        $encodings = [];

        foreach ($rawData as $table => $rows) {
            $binaryColumns = $this->binaryColumns($table);
            foreach ($rows as $row) {
                foreach ($binaryColumns as $column) {
                    if (isset($row[$column])) {
                        $row[$column] = base64_encode((string) $row[$column]);
                        $encodings[$table][$column] = 'base64';
                    }
                }
                $data[$table][] = $row;
            }
            $data[$table] ??= [];
        }

        return [$data, $encodings];
    }

    private function binaryColumns(string $table): array
    {
        return collect(Schema::getColumns($table))->filter(function (array $column) {
            $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

            return str_contains($type, 'blob') || str_contains($type, 'binary');
        })->pluck('name')->all();
    }

    private function validateArchive(mixed $archive): void
    {
        if (! is_array($archive)
            || ($archive['format'] ?? null) !== self::FORMAT
            || (int) ($archive['version'] ?? 0) !== self::VERSION
            || ! is_array($archive['manifest'] ?? null)
            || ! is_array($archive['payload']['data'] ?? null)
            || ! is_string($archive['checksum'] ?? null)) {
            throw ValidationException::withMessages(['file' => 'This is not a supported AIMS portable backup.']);
        }

        $provided = $archive['checksum'];
        unset($archive['checksum']);
        $calculated = 'sha256:'.hash('sha256', $this->canonicalJson($archive));
        if (! hash_equals($calculated, $provided)) {
            throw ValidationException::withMessages(['file' => 'The backup checksum is invalid. The file may be damaged or changed.']);
        }

        $allowedTables = array_fill_keys(array_unique(array_merge(...array_values(self::MODULES))), true);
        $modules = $archive['manifest']['modules'] ?? null;
        if (! is_array($modules) || $modules === [] || array_diff($modules, array_keys(self::MODULES)) !== []) {
            throw ValidationException::withMessages(['file' => 'The backup module manifest is invalid.']);
        }
        $company = $archive['manifest']['company'] ?? null;
        if (! is_array($company) || trim((string) ($company['name'] ?? '')) === '') {
            throw ValidationException::withMessages(['file' => 'The backup company identity is missing.']);
        }

        $manifestTables = $archive['manifest']['tables'] ?? null;
        $counts = $archive['manifest']['counts'] ?? null;
        if (! is_array($manifestTables) || ! is_array($counts)) {
            throw ValidationException::withMessages(['file' => 'The backup table manifest is incomplete.']);
        }
        $dataTables = array_keys($archive['payload']['data']);
        $sortedManifestTables = $manifestTables;
        $sortedDataTables = $dataTables;
        sort($sortedManifestTables);
        sort($sortedDataTables);
        if ($sortedManifestTables !== $sortedDataTables) {
            throw ValidationException::withMessages(['file' => 'The backup table manifest does not match its payload.']);
        }

        $totalRows = 0;
        foreach ($archive['payload']['data'] as $table => $rows) {
            if (! is_string($table) || ! isset($allowedTables[$table]) || ! is_array($rows)) {
                throw ValidationException::withMessages(['file' => 'The backup contains an unsupported data table.']);
            }
            if (! Schema::hasTable($table)) {
                throw ValidationException::withMessages(['file' => "This installation is missing the {$table} table required by the backup."]);
            }
            if (! array_key_exists($table, $counts) || (int) $counts[$table] !== count($rows)) {
                throw ValidationException::withMessages(['file' => "The {$table} record count does not match the backup manifest."]);
            }
            $knownColumns = array_flip(Schema::getColumnListing($table));
            $totalRows += count($rows);
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw ValidationException::withMessages(['file' => "The {$table} data is malformed."]);
                }
                $unknownColumns = array_diff_key($row, $knownColumns);
                if ($unknownColumns !== []) {
                    throw ValidationException::withMessages([
                        'file' => "The {$table} data contains columns this installation does not support: ".implode(', ', array_keys($unknownColumns)).'.',
                    ]);
                }
            }
        }
        if ($totalRows > 500000) {
            throw ValidationException::withMessages(['file' => 'The backup contains too many records for one restore operation.']);
        }
    }

    private function decodeArchiveRows(array $data, array $encodings): array
    {
        foreach ($encodings as $table => $columns) {
            foreach ($data[$table] ?? [] as $index => $row) {
                foreach ($columns as $column => $encoding) {
                    if ($encoding === 'base64' && isset($row[$column])) {
                        $decoded = base64_decode((string) $row[$column], true);
                        if ($decoded === false) {
                            throw ValidationException::withMessages(['file' => "The encoded {$table}.{$column} data is invalid."]);
                        }
                        $data[$table][$index][$column] = $decoded;
                    }
                }
            }
        }

        return $data;
    }

    private function selectRowsWithDependencies(array $archiveData, array $coreTables): array
    {
        $selected = [];
        foreach ($coreTables as $table) {
            if (isset($archiveData[$table])) {
                $selected[$table] = $archiveData[$table];
            }
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($selected as $table => $rows) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    $column = $foreign['columns'][0] ?? null;
                    $foreignTable = $foreign['foreign_table'] ?? null;
                    if (! $column || ! $foreignTable || ! isset($archiveData[$foreignTable])) {
                        continue;
                    }
                    $wanted = collect($rows)->pluck($column)->filter(fn ($id) => $id !== null)->map(fn ($id) => (string) $id)->flip();
                    $existing = collect($selected[$foreignTable] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->flip();
                    foreach ($archiveData[$foreignTable] as $candidate) {
                        $id = isset($candidate['id']) ? (string) $candidate['id'] : null;
                        if ($id !== null && $wanted->has($id) && ! $existing->has($id)) {
                            $selected[$foreignTable][] = $candidate;
                            $existing->put($id, true);
                            $changed = true;
                        }
                    }
                }
            }
        }

        return $selected;
    }

    private function deleteCurrentModuleRows(array $coreTables, int $companyId): void
    {
        $ownedIds = [];
        $ownedRows = [];
        foreach ($coreTables as $table) {
            $ownedRows[$table] = $this->ownedRows($table, $companyId);
            $ownedIds[$table] = collect($ownedRows[$table])->pluck('id')->filter()->all();
        }

        foreach (array_reverse($this->sortTables($coreTables)) as $table) {
            if (($ownedRows[$table] ?? []) === []) {
                continue;
            }

            if (Schema::hasColumn($table, 'id')) {
                DB::table($table)->whereIn('id', $ownedIds[$table])->delete();
                continue;
            }

            // Composite-key bridge tables have no id column. Their tenant
            // boundary is the same owner relation used during export.
            if (isset(self::OWNER_RELATIONS[$table])) {
                [$foreignKey, $parentTable] = self::OWNER_RELATIONS[$table];
                $parentIds = $ownedIds[$parentTable] ?? [];
                if ($parentIds !== []) {
                    DB::table($table)->whereIn($foreignKey, $parentIds)->delete();
                }
            }
        }
    }

    private function sourceCompanyId(array $data): ?int
    {
        foreach ($data as $rows) {
            foreach ($rows as $row) {
                if (isset($row['company_id'])) {
                    return (int) $row['company_id'];
                }
            }
        }

        return null;
    }

    private function mapRow(
        string $table,
        array $row,
        int $companyId,
        int $currentUserId,
        array $idMap,
        array $importTables,
    ): array {
        $sourceId = $row['id'] ?? null;
        unset($row['id']);
        $columns = collect(Schema::getColumns($table))->pluck('name')->flip();
        $row = array_intersect_key($row, $columns->all());

        if ($columns->has('company_id')) {
            $row['company_id'] = $companyId;
        }

        $deferred = [];
        foreach (Schema::getForeignKeys($table) as $foreign) {
            $column = $foreign['columns'][0] ?? null;
            $foreignTable = $foreign['foreign_table'] ?? null;
            if (! $column || ! $foreignTable || ! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $oldForeignId = (string) $row[$column];
            if ($foreignTable === 'companies') {
                $row[$column] = $companyId;
            } elseif ($foreignTable === $table) {
                $deferred[$column] = [$foreignTable, $oldForeignId];
                $row[$column] = null;
            } elseif (isset($idMap[$foreignTable][$oldForeignId])) {
                $row[$column] = $idMap[$foreignTable][$oldForeignId];
            } elseif ($foreignTable === 'users') {
                $row[$column] = $currentUserId;
            } elseif (in_array($foreignTable, $importTables, true) && $this->columnNullable($table, $column)) {
                $deferred[$column] = [$foreignTable, $oldForeignId];
                $row[$column] = null;
            } elseif ($this->columnNullable($table, $column)) {
                $row[$column] = null;
            } else {
                throw ValidationException::withMessages([
                    'file' => "The backup is missing the {$foreignTable} record needed by {$table}.{$column}.",
                ]);
            }
        }

        if ($table === 'stock_movements' && array_key_exists('source_id', $row) && $row['source_id'] !== null) {
            $sourceTable = match ($row['source_type'] ?? null) {
                'daily_sale' => 'daily_sales',
                'invoice' => 'invoices',
                'product', 'product_edit' => 'products',
                'goods_receipt' => 'goods_receipts',
                'stock_transfer' => 'stock_transfers',
                'inventory_count' => 'inventory_count_sessions',
                'bin_transfer' => 'stock_movements',
                'inventory_return' => 'inventory_returns',
                default => null,
            };
            $oldSourceId = (string) $row['source_id'];
            if ($sourceTable && isset($idMap[$sourceTable][$oldSourceId])) {
                $row['source_id'] = $idMap[$sourceTable][$oldSourceId];
            } elseif ($sourceTable && in_array($sourceTable, $importTables, true)) {
                $deferred['source_id'] = [$sourceTable, $oldSourceId];
                $row['source_id'] = null;
            } else {
                $row['source_id'] = null;
            }
        }

        if ($table === 'financial_account_transactions'
            && array_key_exists('source_id', $row)
            && $row['source_id'] !== null) {
            $sourceTable = match ($row['source_type'] ?? null) {
                'invoice_payment' => 'payment_transactions',
                'expense_payment' => 'expense_payments',
                'purchase_order_payment' => 'purchase_order_payments',
                'customer_debt_payment' => 'customer_debt_transactions',
                'inventory_return' => 'inventory_returns',
                'financial_account_transfer' => 'financial_account_transfers',
                default => null,
            };
            $oldSourceId = (string) $row['source_id'];
            if ($sourceTable && isset($idMap[$sourceTable][$oldSourceId])) {
                $row['source_id'] = $idMap[$sourceTable][$oldSourceId];
            } elseif ($sourceTable && in_array($sourceTable, $importTables, true)) {
                $deferred['source_id'] = [$sourceTable, $oldSourceId];
                $row['source_id'] = null;
            } else {
                $row['source_id'] = null;
            }
        }

        foreach (['warehouse_stock', 'inventory_trace_balances', 'inventory_count_items'] as $locationScopedTable) {
            if ($table === $locationScopedTable && $columns->has('location_key')) {
                $row['location_key'] = (int) ($row['location_id'] ?? 0);
            }
        }
        if ($table === 'inventory_count_items' && $columns->has('lot_key')) {
            $row['lot_key'] = (int) ($row['inventory_lot_id'] ?? 0);
        }

        if ($sourceId !== null && $columns->has('id') && $row === []) {
            throw ValidationException::withMessages(['file' => "The {$table} backup row has no restorable values."]);
        }

        return [$row, $deferred];
    }

    private function columnNullable(string $table, string $column): bool
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        return (bool) ($definition['nullable'] ?? false);
    }

    private function upsertPortableRow(string $table, array $values, string $mode, bool $isCore): ?int
    {
        $identity = $this->identityFor($table, $values);
        $hasId = Schema::hasColumn($table, 'id');
        $existingId = null;
        if ($identity !== []) {
            $existing = DB::table($table)->where($identity);
            if (! $hasId && $existing->exists()) {
                $existing->update($values);

                return null;
            }
            $existingId = $hasId ? $existing->value('id') : null;
        }

        if ($existingId) {
            if ($table === 'invoice_sequences' && isset($values['next_number'])) {
                $values['next_number'] = max(
                    (int) $values['next_number'],
                    (int) DB::table($table)->where('id', $existingId)->value('next_number'),
                );
            }
            DB::table($table)->where('id', $existingId)->update($values);

            return (int) $existingId;
        }

        // Dependency rows are merged by identity. Core rows in merge mode with
        // no stable business key are appended rather than destructively guessed.
        if (! $hasId) {
            DB::table($table)->insert($values);

            return null;
        }

        return (int) DB::table($table)->insertGetId($values);
    }

    private function identityFor(string $table, array $values): array
    {
        if ($table === 'products') {
            $keys = filled($values['sku'] ?? null) ? ['company_id', 'sku'] : ['company_id', 'name'];
        } elseif ($table === 'warehouses') {
            $keys = filled($values['code'] ?? null) ? ['company_id', 'code'] : ['company_id', 'name'];
        } elseif ($table === 'customers') {
            $keys = filled($values['email'] ?? null) ? ['company_id', 'email'] : ['company_id', 'name', 'phone'];
        } elseif ($table === 'shipments') {
            $keys = filled($values['tracking_number'] ?? null) ? ['company_id', 'tracking_number'] : [];
        } elseif ($table === 'invoices') {
            $keys = filled($values['invoice_number'] ?? null) ? ['company_id', 'invoice_number'] : [];
        } elseif ($table === 'payment_transactions') {
            $keys = filled($values['idempotency_key'] ?? null)
                ? ['company_id', 'idempotency_key']
                : (filled($values['transaction_ref'] ?? null) ? ['transaction_ref'] : []);
        } elseif ($table === 'stock_movements') {
            $keys = filled($values['idempotency_key'] ?? null) ? ['company_id', 'idempotency_key'] : [];
        } elseif ($table === 'customer_debt_transactions') {
            $keys = filled($values['idempotency_key'] ?? null) ? ['company_id', 'idempotency_key'] : [];
        } elseif ($table === 'purchase_order_payments') {
            $keys = filled($values['idempotency_key'] ?? null) ? ['company_id', 'idempotency_key'] : [];
        } elseif ($table === 'landed_costs') {
            $keys = filled($values['idempotency_key'] ?? null) ? ['company_id', 'idempotency_key'] : [];
        } else {
            $keys = self::IDENTITY_COLUMNS[$table] ?? [];
        }

        if ($keys === [] || collect($keys)->contains(fn ($key) => ! array_key_exists($key, $values))) {
            return [];
        }

        return collect($keys)->mapWithKeys(fn ($key) => [$key => $values[$key]])->all();
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function encryptArchive(string $plaintext, string $passphrase): string
    {
        $metadata = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        $salt = random_bytes(16);
        $nonce = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 32, true);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::ENCRYPTION_AAD,
            16,
        );
        if ($ciphertext === false) {
            throw ValidationException::withMessages(['passphrase' => 'This installation could not encrypt the backup.']);
        }

        return json_encode([
            'format' => self::ENCRYPTED_FORMAT,
            'version' => self::VERSION,
            'archive_version' => (int) ($metadata['version'] ?? self::VERSION),
            'created_at' => $metadata['created_at'] ?? null,
            'modules' => array_values($metadata['manifest']['modules'] ?? []),
            'encryption' => [
                'cipher' => 'AES-256-GCM',
                'kdf' => 'PBKDF2-HMAC-SHA256',
                'iterations' => self::PBKDF2_ITERATIONS,
                'salt' => base64_encode($salt),
                'nonce' => base64_encode($nonce),
                'tag' => base64_encode($tag),
            ],
            'payload' => base64_encode($ciphertext),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decryptArchive(array $envelope, string $passphrase): string
    {
        $encryption = $envelope['encryption'] ?? [];
        $iterations = (int) ($encryption['iterations'] ?? 0);
        if (($envelope['version'] ?? null) !== self::VERSION
            || ($encryption['cipher'] ?? null) !== 'AES-256-GCM'
            || ($encryption['kdf'] ?? null) !== 'PBKDF2-HMAC-SHA256'
            || $iterations !== self::PBKDF2_ITERATIONS) {
            throw ValidationException::withMessages(['file' => 'This encrypted AIMS backup version is not supported.']);
        }

        $salt = base64_decode((string) ($encryption['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($encryption['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($encryption['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['payload'] ?? ''), true);
        if ($salt === false || strlen($salt) !== 16
            || $nonce === false || strlen($nonce) !== 12
            || $tag === false || strlen($tag) !== 16
            || $ciphertext === false) {
            throw ValidationException::withMessages(['file' => 'The encrypted backup envelope is damaged.']);
        }

        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::ENCRYPTION_AAD,
        );
        if ($plaintext === false) {
            throw ValidationException::withMessages([
                'passphrase' => 'The backup passphrase is incorrect, or the encrypted file was changed.',
            ]);
        }

        return $plaintext;
    }

    private function assertMergeIsSafe(array $data, int $companyId): void
    {
        $aggregateIdentities = [
            'daily_sales' => ['sale_number'],
            'expenses' => ['vendor_key', 'document_type', 'document_number_normalized'],
            'purchase_orders' => ['po_number'],
            'goods_receipts' => ['receipt_number'],
            'stock_transfers' => ['transfer_number'],
            'customer_debts' => ['customer_name'],
        ];

        foreach ($aggregateIdentities as $table => $keys) {
            foreach ($data[$table] ?? [] as $row) {
                if (collect($keys)->contains(fn (string $key) => ! filled($row[$key] ?? null))) {
                    continue;
                }
                $identity = collect($keys)->mapWithKeys(fn (string $key) => [$key => $row[$key]])->all();
                if (DB::table($table)->where('company_id', $companyId)->where($identity)->exists()) {
                    throw ValidationException::withMessages([
                        'mode' => "Merge would duplicate related {$table} records. Use a complete Replace restore instead.",
                    ]);
                }
            }
        }

        foreach ($data['invoices'] ?? [] as $invoice) {
            $query = DB::table('invoices')->where('company_id', $companyId);
            if (filled($invoice['invoice_number'] ?? null)) {
                $query->where('invoice_number', $invoice['invoice_number']);
            } else {
                // Drafts deliberately have no legal invoice number. Without a
                // portable UUID there is no safe way to match one draft among
                // several, so any existing draft makes merge ambiguous.
                $query->whereNull('invoice_number')->where('status', 'draft');
            }
            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'mode' => 'Merge would duplicate an invoice and its lines. Use a complete Replace restore instead.',
                ]);
            }
        }

        foreach ($data['shipments'] ?? [] as $shipment) {
            $trackingNumber = trim((string) ($shipment['tracking_number'] ?? ''));
            if ($trackingNumber !== '' && DB::table('shipments')
                ->where('company_id', $companyId)
                ->where('tracking_number', $trackingNumber)
                ->exists()) {
                throw ValidationException::withMessages([
                    'mode' => 'Merge would duplicate a shipment and its history. Use a complete Replace restore instead.',
                ]);
            }
        }

        $unsafeLedgers = [
            'stock_movements' => 'idempotency_key',
            'customer_debt_transactions' => 'idempotency_key',
            'payment_transactions' => 'idempotency_key',
            'purchase_order_payments' => 'idempotency_key',
            'expense_payments' => 'idempotency_key',
        ];
        foreach ($unsafeLedgers as $table => $key) {
            $containsUnkeyedRows = collect($data[$table] ?? [])->contains(fn (array $row) => ! filled($row[$key] ?? null));
            if ($containsUnkeyedRows && DB::table($table)->where('company_id', $companyId)->exists()) {
                throw ValidationException::withMessages([
                    'mode' => "Merge cannot safely identify existing {$table} ledger rows. Use a complete Replace restore instead.",
                ]);
            }
        }
    }
}
