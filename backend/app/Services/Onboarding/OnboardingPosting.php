<?php

namespace App\Services\Onboarding;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OnboardingRow;
use App\Models\OnboardingSession;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\ExpenseService;
use App\Services\FinancialAccountService;
use App\Services\InventoryIntegrityService;
use App\Services\ProductService;
use App\Services\PurchaseOrderService;
use App\Services\StockMovementService;
use App\Services\WarehouseOperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnboardingPosting
{
    private const ORDER = ['warehouses', 'locations', 'products', 'customers', 'suppliers', 'inventory', 'receivables', 'customer_advances', 'payables', 'supplier_advances', 'cash_bank'];

    public function review(int $id, array $data): OnboardingSession
    {
        $service = app(OnboardingService::class);
        $service->permit('review');
        validator($data, ['revision' => 'required|integer', 'source_totals_confirmed' => 'required|accepted', 'warnings_acknowledged' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($service, $id, $data) {
            $s = $service->lock($id);
            $service->revision($s, (int) $data['revision']);
            $recovery = ($s->review['restored_read_only'] ?? false) && $s->posting_started_at && $s->status !== 'locked';
            if (! $recovery) {
                $service->editable($s);
            }
            $report = app(OnboardingReconciliation::class)->report($s);
            if (! $report['ready_for_review']) {
                $this->error('Resolve all blocking rows and source differences before review.');
            }
            if (! $recovery && ! app(OnboardingReconciliation::class)->operationallyEmpty()) {
                $this->error('This company already has operational history. Opening onboarding requires an empty operational baseline.');
            }
            $backup = $s->pre_backup_id ? $s->backups()->find($s->pre_backup_id) : null;
            if (! $recovery && (! $backup || ($backup->verification['revision'] ?? -1) !== $s->revision)) {
                $this->error('Create and verify a fresh encrypted pre-cutover backup for this revision.');
            }
            if (! $recovery && ! hash_equals($backup->operational_fingerprint, app(OnboardingFingerprint::class)->operational())) {
                $this->error('The pre-cutover backup no longer represents the operational baseline. Create a fresh backup.');
            }
            $fingerprint = app(OnboardingFingerprint::class)->source($s);
            $s->update(['review_fingerprint' => $fingerprint, 'reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'status' => 'reviewed',
                'approved_fingerprint' => null, 'approved_by' => null, 'approved_at' => null,
                'review' => ['fingerprint' => $fingerprint, 'restored_read_only' => (bool) $recovery, 'operational_fingerprint' => app(OnboardingFingerprint::class)->operational(), 'source_totals_confirmed' => true, 'warnings_acknowledged' => true, 'report' => $report]]);
            $service->event($s, 'review.completed', ['fingerprint' => $fingerprint, 'report' => $report]);

            return $s->fresh();
        });
    }

    public function approve(int $id, array $data): OnboardingSession
    {
        $service = app(OnboardingService::class);
        $service->permit('approve');
        validator($data, ['revision' => 'required|integer', 'fingerprint' => 'required|string|size:64', 'confirmed' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($service, $id, $data) {
            $s = $service->lock($id);
            $service->revision($s, (int) $data['revision']);
            $recovery = ($s->review['restored_read_only'] ?? false) && $s->posted_at && $s->status !== 'locked';
            if (! $recovery) {
                $service->editable($s);
            }
            $fingerprint = app(OnboardingFingerprint::class)->source($s);
            if (! $s->reviewed_at || ! hash_equals($s->review_fingerprint ?? '', $fingerprint) || ! hash_equals($fingerprint, $data['fingerprint'])) {
                $this->error('The reviewed dataset changed. Validate and review again.');
            }
            if ($s->separate_reviewer_required && (int) $s->reviewed_by === (int) Auth::id()) {
                $this->error('This company requested a different reviewer and approver.');
            }
            $s->update(['approved_fingerprint' => $fingerprint, 'approved_by' => Auth::id(), 'approved_at' => now(), 'status' => $recovery ? 'posted' : 'approved']);
            $service->event($s, 'approval.granted', ['fingerprint' => $fingerprint, 'reviewed_by' => $s->reviewed_by, 'same_reviewer' => (int) $s->reviewed_by === (int) Auth::id()]);

            return $s->fresh();
        });
    }

    /** Each row and its authoritative side effects commit together. A request is bounded. */
    public function post(int $id, array $data): array
    {
        $service = app(OnboardingService::class);
        $service->permit('post');
        validator($data, ['revision' => 'required|integer', 'fingerprint' => 'required|string|size:64', 'limit' => 'sometimes|integer|min:1|max:50', 'confirmed' => 'required|accepted'])->validate();
        DB::transaction(function () use ($service, $id, $data) {
            $s = $service->lock($id);
            $service->revision($s, (int) $data['revision']);
            if (! in_array($s->status, ['approved', 'posting', 'posting_failed', 'posted', 'verified', 'locked'], true)) {
                $this->error('Explicit dataset approval is required before posting.');
            }
            $actual = app(OnboardingFingerprint::class)->source($s);
            if (! hash_equals($s->approved_fingerprint ?? '', $actual) || ! hash_equals($actual, $data['fingerprint'])) {
                $this->error('The approved dataset changed. Posting is blocked.');
            }
            if ($s->posted_at) {
                return;
            }
            if (! $s->posting_started_at) {
                if ($s->cutover_date->toDateString() > CarbonImmutable::now($s->timezone)->toDateString()) {
                    $this->error('The cutover date has not arrived in the company timezone.');
                }
                if (! app(OnboardingReconciliation::class)->operationallyEmpty() || ! hash_equals($s->review['operational_fingerprint'] ?? '', app(OnboardingFingerprint::class)->operational())) {
                    $this->error('The operational baseline changed after review. Revalidate and create a fresh backup.');
                }
                $backup = $s->backups()->findOrFail($s->pre_backup_id);
                if (! hash_equals($backup->checksum, hash('sha256', $backup->encrypted_contents))) {
                    $this->error('Pre-cutover backup integrity failed.');
                }
                Company::whereKey($s->company_id)->update(['timezone' => $s->timezone]);
                Auth::user()->unsetRelation('company');
                $s->update(['posting_started_at' => now(), 'status' => 'posting', 'stage' => 'posting']);
                $service->event($s, 'posting.started', ['fingerprint' => $actual, 'backup_checksum' => $backup->checksum]);
            } else {
                $s->update(['status' => 'posting', 'last_error' => null]);
            }
        });
        $processed = 0;
        $failed = null;
        while ($processed < ($data['limit'] ?? 25)) {
            $rowId = null;
            try {
                $done = DB::transaction(function () use ($service, $id, &$rowId) {
                    $s = $service->lock($id);
                    if ($s->posted_at) {
                        return false;
                    }
                    $row = null;
                    foreach (self::ORDER as $domain) {
                        $query = $s->rows()->active()->where('domain', $domain)->whereNull('posted_at')->where('status', '!=', 'skipped')->orderBy('id');
                        if ($domain === 'locations') {
                            foreach (['zone', 'rack', 'shelf', 'bin'] as $type) {
                                $row = (clone $query)->where('normalized->type', $type)->lockForUpdate()->first();
                                if ($row) {
                                    break;
                                }
                            }
                        } else {
                            $row = $query->lockForUpdate()->first();
                        }
                        if ($row) {
                            break;
                        }
                    }
                    if (! $row) {
                        $s->update(['status' => 'posted', 'posted_at' => now(), 'stage' => 'verification', 'last_error' => null]);
                        $service->event($s, 'posting.completed');

                        return false;
                    }
                    $rowId = $row->id;
                    if (! in_array($row->status, ['valid', 'failed'], true)) {
                        $this->error('A source row is unresolved. Posting is blocked.');
                    }
                    $entity = app(OnboardingGuard::class)->run($s, fn () => $this->postRow($s, $row));
                    $row->update(['status' => 'posted', 'posted_entity_type' => $entity->getTable(), 'posted_entity_id' => $entity->id, 'posted_at' => now(),
                        'posting_result' => ['entity_id' => $entity->id, 'entity_type' => $entity->getTable(), 'idempotency_key' => 'onboarding:'.$s->id.':'.$row->id]]);
                    $service->event($s, 'row.posted', ['row_id' => $row->id, 'domain' => $row->domain, 'entity_type' => $entity->getTable(), 'entity_id' => $entity->id]);

                    return true;
                });
                if (! $done) {
                    break;
                } $processed++;
            } catch (\Throwable $exception) {
                // Do not log SQL bindings or commercial source rows. The committed boundary is explicit.
                $reference = (string) Str::uuid();
                DB::transaction(function () use ($service, $id, $rowId, $reference) {
                    $s = $service->lock($id);
                    if ($rowId) {
                        $s->rows()->whereKey($rowId)->whereNull('posted_at')->update(['status' => 'failed']);
                    }
                    $s->update(['status' => 'posting_failed', 'last_error' => 'Posting stopped at source row '.$rowId.'. Support reference '.$reference]);
                    $service->event($s, 'posting.failed', ['row_id' => $rowId, 'reference' => $reference]);
                });
                $failed = ['row_id' => $rowId, 'reference' => $reference];
                break;
            }
        }

        return ['processed' => $processed, 'failure' => $failed, ...$service->detail($id)];
    }

    private function ref(OnboardingSession $s, string $domain, string $ref): int
    {
        $record = app(OnboardingValidation::class)->reference($s, $domain, $ref);
        if (! $record || ! isset($record['_id'])) {
            $this->error('A source reference has not been posted or selected.');
        }

        return (int) $record['_id'];
    }

    private function postRow(OnboardingSession $s, OnboardingRow $r): Model
    {
        $n = $r->normalized;
        $key = 'onboarding:'.$s->id.':'.$r->id;
        if ($r->match_id && in_array($r->domain, OnboardingSchema::MASTERS, true)) {
            $class = OnboardingValidation::MODELS[$r->domain];

            return $class::findOrFail($r->match_id);
        }
        if ($r->domain === 'warehouses') {
            return app(WarehouseOperationsService::class)->createWarehouse(['name' => $n['name'], 'code' => $n['code'], 'address' => $n['address'] ?: null]);
        }
        if ($r->domain === 'locations') {
            return app(WarehouseOperationsService::class)->createLocation(['name' => $n['name'], 'code' => $n['code'], 'type' => $n['type'], 'parent_id' => ! empty($n['parent_ref']) ? $this->ref($s, 'locations', $n['parent_ref']) : null, 'warehouse_id' => $this->ref($s, 'warehouses', $n['warehouse_ref'])]);
        }
        if ($r->domain === 'products') {
            $category = $n['category'] !== '' ? Category::firstOrCreate(['company_id' => $s->company_id, 'name' => $n['category']]) : null;
            $values = ['company_id' => $s->company_id, 'name' => $n['name'], 'sku' => $n['sku'] ?: null, 'barcode' => $n['barcode'] ?: null, 'unit' => $n['unit'], 'quantity' => 0, 'price' => $n['selling_price'] ?: 0, 'purchase_price' => $n['purchase_price'] ?: 0, 'selling_price' => $n['selling_price'] ?: 0, 'category_id' => $category?->id, 'tracking_mode' => 'none'];
            if ($n['conversion_unit'] !== '') {
                $values['unit_conversions'] = [['code' => $n['conversion_unit'], 'factor_to_base' => $n['conversion_factor']]];
            }

            return app(ProductService::class)->create($values);
        }
        if ($r->domain === 'customers') {
            return Customer::create(['company_id' => $s->company_id, ...array_intersect_key($n, array_flip(['name', 'business_name', 'email', 'phone', 'tax_number', 'address']))]);
        }
        if ($r->domain === 'suppliers') {
            return Supplier::create(['company_id' => $s->company_id, ...array_intersect_key($n, array_flip(['name', 'email', 'phone', 'address']))]);
        }
        if ($r->domain === 'inventory') {
            return app(StockMovementService::class)->store([
                'product_id' => $this->ref($s, 'products', $n['product_ref']), 'warehouse_id' => $this->ref($s, 'warehouses', $n['warehouse_ref']),
                'location_id' => $n['location_ref'] !== '' ? $this->ref($s, 'locations', $n['location_ref']) : null,
                'type' => 'in', 'quantity' => $n['base_quantity'], 'stock_state' => $n['stock_state'], 'movement_code' => 'opening_balance',
                'reason' => 'Approved opening '.$s->public_id.' / '.$r->external_ref, 'source_type' => 'onboarding_opening', 'source_id' => $s->id, 'idempotency_key' => $key,
                'base_purchase_unit_cost' => $n['unit_cost'], 'final_unit_cost' => $n['unit_cost'],
                'occurred_at' => CarbonImmutable::parse($s->cutover_date->toDateString().' 23:59:59', $s->timezone)->utc()->format('Y-m-d H:i:s'),
                'metadata' => ['onboarding_session_id' => $s->id, 'onboarding_row_id' => $r->id, 'opening_date' => $s->cutover_date->toDateString(), 'source_checksum' => $r->batch->checksum],
            ], $s);
        }
        if (in_array($r->domain, ['receivables', 'customer_advances'], true)) {
            return app(CustomerDebtService::class)->recordOpening($s, Customer::findOrFail($this->ref($s, 'customers', $n['customer_ref'])), [
                'amount' => $n['amount'], 'transaction_date' => $s->cutover_date->toDateString(), 'due_date' => ($n['due_date'] ?? '') ?: null, 'source' => 'onboarding_opening',
                'reference_number' => $n['reference'], 'note' => 'Opening '.$s->public_id.' source '.$r->external_ref, 'idempotency_key' => $key,
                'metadata' => ['onboarding_session_id' => $s->id, 'onboarding_row_id' => $r->id],
            ], $r->domain === 'customer_advances');
        }
        if ($r->domain === 'payables') {
            return app(ExpenseService::class)->recordOpeningPayable($s, Supplier::findOrFail($this->ref($s, 'suppliers', $n['supplier_ref'])), ['row_id' => $r->id, ...$n]);
        }
        if ($r->domain === 'supplier_advances') {
            return app(PurchaseOrderService::class)->recordOpeningAdvance($s, Supplier::findOrFail($this->ref($s, 'suppliers', $n['supplier_ref'])), ['row_id' => $r->id, ...$n]);
        }
        if ($r->domain === 'cash_bank') {
            return app(FinancialAccountService::class)->recordOpening($s, $n, $r->match_id);
        }
        throw new \LogicException('Unknown opening domain.');
    }

    public function verify(int $id): array
    {
        $service = app(OnboardingService::class);
        $service->permit('review');

        return DB::transaction(function () use ($service, $id) {
            $s = $service->lock($id);
            if (! $s->posted_at || ! in_array($s->status, ['posted', 'verified'], true)) {
                $this->error('Complete every posting batch before verification.');
            }
            $report = app(OnboardingReconciliation::class)->report($s);
            $accounting = app(AccountingService::class)->reconciliation(false);
            $inventoryIssues = [];
            $checked = 0;
            foreach (Product::cursor() as $product) {
                $snapshot = app(InventoryIntegrityService::class)->productSnapshot($product);
                $checked++;
                if ($snapshot['issues'] && count($inventoryIssues) < 25) {
                    $inventoryIssues[] = ['product_id' => $product->id, 'issues' => $snapshot['issues']];
                }
            }
            $passed = $report['ready_for_review'] && ($accounting['all_reconciled'] ?? false) && ! $inventoryIssues;
            $verification = ['passed' => $passed, 'at' => now()->toIso8601String(), 'fingerprint' => $s->approved_fingerprint, 'report' => $report, 'accounting' => $accounting, 'inventory' => ['products_checked' => $checked, 'issues' => $inventoryIssues]];
            $s->update(['verification' => $verification, 'status' => $passed ? 'verified' : 'posted', 'verified_at' => $passed ? now() : null]);
            $service->event($s, 'verification.completed', $verification);

            return $service->detail($id);
        });
    }

    public function lock(int $id, array $data): OnboardingSession
    {
        $service = app(OnboardingService::class);
        $service->permit('lock');
        validator($data, ['confirmed' => 'required|accepted', 'fingerprint' => 'required|string|size:64'])->validate();

        return DB::transaction(function () use ($service, $id, $data) {
            $s = $service->lock($id);
            if ($s->status === 'locked') {
                return $s;
            }
            if ($s->status !== 'verified' || ! ($s->verification['passed'] ?? false) || ! ($s->restore_verification['passed'] ?? false) || ! $s->post_backup_id) {
                $this->error('Verified totals, a post-cutover backup, and a successful independent restore are required before locking.');
            }
            if (! hash_equals($s->approved_fingerprint ?? '', $data['fingerprint']) || ! hash_equals($s->approved_fingerprint ?? '', app(OnboardingFingerprint::class)->source($s))) {
                $this->error('Approved source integrity failed.');
            }
            if (! app(OnboardingReconciliation::class)->report($s)['ready_for_review'] || ! app(AccountingService::class)->reconciliation(false)['all_reconciled']) {
                $this->error('Reconciliation changed. Verify again.');
            }
            $backup = $s->backups()->findOrFail($s->post_backup_id);
            if (! hash_equals($backup->checksum, $s->restore_verification['backup_checksum'] ?? '')) {
                $this->error('Restore verification belongs to a different backup.');
            }
            Company::whereKey($s->company_id)->update(['timezone' => $s->timezone, 'operational_cutover_date' => $s->cutover_date->toDateString(), 'accounting_start_date' => $s->cutover_date->toDateString(), 'accounting_opening_finalized_at' => now()]);
            $s->update(['status' => 'locked', 'stage' => 'complete', 'locked_at' => now(), 'locked_by' => Auth::id()]);
            $service->event($s, 'opening.locked', ['fingerprint' => $s->approved_fingerprint, 'verification' => $s->verification, 'restore' => $s->restore_verification]);
            Auth::user()->unsetRelation('company');

            return $s->fresh();
        });
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['onboarding' => $message]);
    }
}
