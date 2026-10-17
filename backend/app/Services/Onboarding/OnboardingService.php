<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\CustomerDebtTransaction;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEvent;
use App\Models\OnboardingRow;
use App\Models\OnboardingSession;
use App\Models\PurchaseOrderPayment;
use App\Services\PermissionService;
use App\Support\CompanyCurrency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public function permit(string $action = 'view'): void
    {
        $permissions = app(PermissionService::class);
        abort_unless(Auth::user()?->company_id && $permissions->roleHasPermission(Auth::user()->role, 'onboarding.view') && ($action === 'view' || $permissions->roleHasPermission(Auth::user()->role, 'onboarding.'.$action)), 403);
    }

    public function listing()
    {
        $this->permit();

        return OnboardingSession::latest('id')->paginate(20);
    }

    public function get(int $id): OnboardingSession
    {
        $this->permit();

        return OnboardingSession::findOrFail($id);
    }

    public function event(OnboardingSession $s, string $action, array $context = []): void
    {
        OnboardingEvent::create(['company_id' => $s->company_id, 'onboarding_session_id' => $s->id, 'user_id' => Auth::id(), 'action' => $action, 'context' => $context, 'created_at' => now()]);
    }

    public function lock(int $id): OnboardingSession
    {
        Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();

        return OnboardingSession::lockForUpdate()->findOrFail($id);
    }

    public function revision(OnboardingSession $s, int $revision): void
    {
        abort_if($s->revision !== $revision, 409, 'This onboarding changed. Reload before saving.');
    }

    public function editable(OnboardingSession $s): void
    {
        if ($s->posting_started_at || in_array($s->status, ['locked', 'cancelled', 'importing'], true)) {
            throw ValidationException::withMessages(['onboarding' => 'Source editing is closed. Posted openings require a normal correction or reversal.']);
        }
    }

    public function invalidate(OnboardingSession $s, string $action, array $context = []): void
    {
        $this->event($s, $action, $context + ['previous_review' => $s->review_fingerprint, 'revision' => $s->revision]);
        $s->update(['revision' => $s->revision + 1, 'status' => 'needs_review', 'review_fingerprint' => null, 'approved_fingerprint' => null, 'approved_by' => null, 'approved_at' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'verification' => null]);
    }

    public function create(array $input): OnboardingSession
    {
        $this->permit('manage');
        $data = $this->settings($input);

        return DB::transaction(function () use ($data) {
            $company = Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            if ($company->operational_cutover_date || $company->accounting_opening_finalized_at || OnboardingSession::whereNotIn('status', ['cancelled'])->exists()) {
                throw ValidationException::withMessages(['onboarding' => 'This company already has an opening or active onboarding. Continue it; do not create overlapping openings.']);
            }
            if (! app(OnboardingReconciliation::class)->operationallyEmpty()) {
                throw ValidationException::withMessages(['onboarding' => 'This AIMS company already contains operational history or balances. Use a new empty company for a controlled opening; existing data is preserved.']);
            }
            $s = OnboardingSession::create($data + ['company_id' => $company->id, 'public_id' => (string) Str::uuid(), 'created_by' => Auth::id(), 'status' => 'draft', 'stage' => 'company', 'revision' => 0]);
            $this->event($s, 'session.created', ['cutover_date' => $data['cutover_date'], 'timezone' => $data['timezone']]);

            return $s;
        });
    }

    public function settings(array $input): array
    {
        $v = validator($input, ['name' => 'required|string|max:160', 'source_period' => 'required|string|max:160', 'cutover_date' => 'required|date_format:Y-m-d', 'timezone' => 'required|timezone', 'currency' => 'required|string|size:3', 'expected_totals' => 'present|array'])->validate();
        $v['currency'] = strtoupper($v['currency']);
        $v['separate_reviewer_required'] = validator($input, ['separate_reviewer_required' => 'sometimes|boolean'])->validate()['separate_reviewer_required'] ?? false;
        if ($v['currency'] !== CompanyCurrency::current()) {
            throw ValidationException::withMessages(['currency' => 'Use the company base currency. Foreign opening amounts require reviewed conversion before upload.']);
        }
        foreach ($v['expected_totals'] as $k => $value) {
            if (! in_array($k, [...OnboardingSchema::TOTALS, 'inventory_units'], true)) {
                throw ValidationException::withMessages(['expected_totals' => 'Unknown source control total.']);
            }if ($k === 'inventory_units') {
                if (! is_array($value)) {
                    throw ValidationException::withMessages(['inventory_units' => 'Enter quantities by unit.']);
                }foreach ($value as $unit => $quantity) {
                    $v['expected_totals'][$k][$unit] = OnboardingSchema::number($quantity, '.', 3);
                }
            } elseif ($value !== '' && $value !== null) {
                $v['expected_totals'][$k] = OnboardingSchema::number($value, '.', 2, $k === 'equity');
            }
        }

        return $v;
    }

    public function update(int $id, array $data): OnboardingSession
    {
        $this->permit('manage');
        $values = $this->settings($data);

        return DB::transaction(function () use ($id, $data, $values) {
            $s = $this->lock($id);
            $this->revision($s, (int) ($data['revision'] ?? -1));
            $this->editable($s);
            $this->invalidate($s, 'session.updated', ['fields' => array_keys($values)]);
            $s->update($values);

            return $s->fresh();
        });
    }

    public function detail(int $id): array
    {
        $s = $this->get($id);

        return ['session' => $s, 'domains' => OnboardingSchema::DOMAINS, 'batches' => $s->batches()->orderBy('id')->get(),
            'progress' => OnboardingRow::active()->where('onboarding_session_id', $s->id)->selectRaw('domain,status,COUNT(*) as count')->groupBy('domain', 'status')->get(),
            'events' => $s->events()->latest('id')->limit(30)->get(),
            'reconciliation' => app(OnboardingReconciliation::class)->report($s),
            'permissions' => collect(['manage', 'review', 'approve', 'post', 'lock'])->mapWithKeys(fn ($p) => [$p => app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'onboarding.'.$p)])];
    }

    public function rows(int $id, array $filters)
    {
        $s = $this->get($id);
        $q = OnboardingRow::active()->where('onboarding_session_id', $s->id);
        if (! empty($filters['domain'])) {
            $q->where('domain', $filters['domain']);
        }
        if (! empty($filters['batch_id'])) {
            $q->where('onboarding_batch_id', (int) $filters['batch_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (($filters['view'] ?? '') === 'warnings') {
            $q->whereNotNull('warnings')->where('warnings', '!=', '[]');
        }
        if (! empty($filters['q'])) {
            $term = '%'.mb_substr($filters['q'], 0, 100).'%';
            $q->where(fn ($x) => $x->where('external_ref', 'like', $term)->orWhere('normalized', 'like', $term));
        }

        $page = $q->orderBy('id')->paginate(50);
        $payments = PurchaseOrderPayment::whereIn('id', $page->getCollection()->where('posted_entity_type', 'purchase_order_payments')->pluck('posted_entity_id'))->withCount('allocations')->get()->keyBy('id');
        $customers = CustomerDebtTransaction::whereIn('id', $page->getCollection()->where('posted_entity_type', 'customer_debt_transactions')->pluck('posted_entity_id'))->pluck('customer_id', 'id');
        $can = fn (string $permission) => app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission);
        foreach ($page as $row) {
            $target = match ($row->posted_entity_type) {
                'products' => $can('inventory.view') || $can('products.manage') ? '/products?product='.$row->posted_entity_id : null,
                'customer_debt_transactions' => $can('debts.view') && $customers->has($row->posted_entity_id) ? '/customer-debts?customer='.$customers->get($row->posted_entity_id) : null,
                'expenses' => $can('finance.view') ? '/finance?expense='.$row->posted_entity_id : null,
                default => null,
            };
            $row->setAttribute('source_url', $row->posted_entity_id ? $target : null);
            if ($row->posted_entity_type === 'purchase_order_payments' && $payment = $payments->get($row->posted_entity_id)) {
                $row->setAttribute('current_position_status', $payment->status);
                $row->setAttribute('can_reverse_advance', $s->status === 'locked' && $can('purchase_orders.payments') && $payment->status === 'completed' && $payment->allocations_count === 0);
            }
        }

        return $page;
    }

    public function upload(int $id, UploadedFile $file, array $input): OnboardingBatch
    {
        $this->permit('manage');
        $v = validator($input, ['domain' => 'required|in:'.implode(',', array_keys(OnboardingSchema::DOMAINS)), 'revision' => 'required|integer', 'reprocess' => 'sometimes|boolean', 'decimal_separator' => ['sometimes', Rule::in(['.', ','])], 'date_format' => 'sometimes|in:Y-m-d,d/m/Y,d.m.Y', 'delimiter' => 'sometimes|in:comma,semicolon,tab'])->validate();
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Source files are limited to 10 MB and 10,000 rows.']);
        }
        $format = strtolower($file->getClientOriginalExtension());
        if (! in_array($format, ['csv', 'json'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload CSV or an AIMS JSON list. Export XLSX as CSV first.']);
        }
        $contents = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw ValidationException::withMessages(['file' => 'Save the source as UTF-8 text.']);
        }
        $hash = hash('sha256', $contents);
        $separator = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"][$v['delimiter'] ?? 'comma'];
        $batch = DB::transaction(function () use ($id, $v, $file, $hash, $format, $contents, $separator) {
            $s = $this->lock($id);
            $this->revision($s, (int) $v['revision']);
            $this->editable($s);
            $existing = $s->batches()->where('domain', $v['domain'])->where('checksum', $hash)->latest('attempt')->first();
            if ($existing && ! ($v['reprocess'] ?? false)) {
                return $existing;
            }
            $this->invalidate($s, 'source.uploaded', ['domain' => $v['domain'], 'checksum' => $hash, 'reprocess' => (bool) ($v['reprocess'] ?? false)]);
            if ($existing) {
                $existing->update(['is_active' => false]);
            }
            $s->update(['status' => 'importing', 'stage' => $v['domain']]);

            return OnboardingBatch::create(['company_id' => $s->company_id, 'onboarding_session_id' => $s->id, 'domain' => $v['domain'], 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'checksum' => $hash, 'attempt' => ($existing?->attempt ?? 0) + 1, 'format' => $format, 'delimiter' => $separator, 'source_base64' => base64_encode($contents), 'headers' => [], 'mapping' => [], 'options' => ['decimal_separator' => $v['decimal_separator'] ?? '.', 'date_format' => $v['date_format'] ?? 'Y-m-d'], 'uploaded_by' => Auth::id(), 'status' => 'importing']);
        });

        return $this->stageBatch($batch);
    }

    public function resumeSource(int $id, int $batchId, int $revision): OnboardingBatch
    {
        $this->permit('manage');
        $batch = DB::transaction(function () use ($id, $batchId, $revision) {
            $s = $this->lock($id);
            $this->revision($s, $revision);
            if ($s->posting_started_at || in_array($s->status, ['locked', 'cancelled'], true)) {
                throw ValidationException::withMessages(['source' => 'Source parsing is closed after posting starts.']);
            }$batch = $s->batches()->where('is_active', true)->findOrFail($batchId);
            if ($batch->status !== 'staged') {
                $this->invalidate($s, 'source.resumed', ['batch_id' => $batch->id]);
            }

            return $batch;
        });

        return $this->stageBatch($batch);
    }

    public function excludeSource(int $id, int $batchId, int $revision, string $reason): void
    {
        $this->permit('manage');
        DB::transaction(function () use ($id, $batchId, $revision, $reason) {
            $s = $this->lock($id);
            $this->revision($s, $revision);
            if ($s->posting_started_at || in_array($s->status, ['locked', 'cancelled'], true)) {
                throw ValidationException::withMessages(['source' => 'Posted source evidence cannot be excluded.']);
            }if (mb_strlen(trim($reason)) < 5) {
                throw ValidationException::withMessages(['reason' => 'Record why this source is excluded.']);
            }$batch = $s->batches()->lockForUpdate()->findOrFail($batchId);
            $batch->update(['is_active' => false]);
            $this->invalidate($s, 'source.excluded', ['batch_id' => $batch->id, 'reason' => $reason]);
        });
    }

    private function stageBatch(OnboardingBatch $batch): OnboardingBatch
    {
        try {
            return DB::transaction(function () use ($batch) {
                $s = $this->lock($batch->onboarding_session_id);
                $batch = OnboardingBatch::lockForUpdate()->findOrFail($batch->id);
                if ($batch->status === 'staged') {
                    return $batch;
                }
                if (! $batch->is_active || $s->posting_started_at || in_array($s->status, ['locked', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['source' => 'This source is no longer editable.']);
                }
                if (! hash_equals($batch->checksum, hash('sha256', base64_decode($batch->source_base64, true)))) {
                    throw ValidationException::withMessages(['source' => 'Stored source integrity failed.']);
                }
                $s->update(['status' => 'importing']);
                $header = [];
                $buffer = [];
                $count = 0;
                foreach ($this->parse($batch) as [$number,$record]) {
                    if (++$count > 10000) {
                        throw ValidationException::withMessages(['file' => 'Source files are limited to 10 MB and 10,000 rows.']);
                    }
                    if (! $header) {
                        $header = array_keys($record);
                    }
                    $buffer[] = ['company_id' => $batch->company_id, 'onboarding_session_id' => $s->id, 'onboarding_batch_id' => $batch->id, 'line_number' => $number, 'domain' => $batch->domain, 'raw' => json_encode($record, JSON_THROW_ON_ERROR), 'status' => 'unvalidated', 'created_at' => now(), 'updated_at' => now()];
                    if (count($buffer) === 100) {
                        OnboardingRow::insertOrIgnore($buffer);
                        $buffer = [];
                    }
                }
                if ($buffer) {
                    OnboardingRow::insertOrIgnore($buffer);
                }
                if (! $count) {
                    throw ValidationException::withMessages(['file' => 'The source contains no data rows.']);
                }
                $batch->update(['headers' => $header, 'mapping' => OnboardingSchema::suggestions($header, $batch->domain), 'row_count' => $count, 'parsed_count' => $count, 'status' => 'staged']);
                $s->update(['status' => 'needs_review']);
                $this->event($s, 'source.staged', ['batch_id' => $batch->id, 'rows' => $count]);

                return $batch->fresh();
            });
        } catch (\Throwable $error) {
            DB::transaction(function () use ($batch) {
                $s = $this->lock($batch->onboarding_session_id);
                $batch->refresh();
                if ($batch->status !== 'staged') {
                    $batch->update(['status' => 'failed']);
                    if (! $s->posting_started_at) {
                        $s->update(['status' => 'needs_review']);
                    }
                }
            });
            throw $error;
        }
    }

    private function parse(OnboardingBatch $batch): \Generator
    {
        $source = base64_decode($batch->source_base64, true);
        if ($batch->format === 'json') {
            try {
                $rows = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['file' => 'The JSON source is invalid.']);
            }
            if (isset($rows['data'])) {
                $rows = $rows['data'];
            }
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw ValidationException::withMessages(['file' => 'Use an AIMS JSON list of row objects.']);
            }
            foreach ($rows as $i => $row) {
                if (! is_array($row) || array_is_list($row) || collect($row)->contains(fn ($v) => is_array($v))) {
                    throw ValidationException::withMessages(['file' => 'Source rows must contain plain column values.']);
                }
                yield [$i + 2, array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row)];
            }

            return;
        }
        $h = fopen('php://temp/maxmemory:1048576', 'r+');
        fwrite($h, $source);
        rewind($h);
        try {
            $headers = fgetcsv($h, 0, $batch->delimiter);
            if (! $headers) {
                throw ValidationException::withMessages(['file' => 'The source needs a header row.']);
            }$headers = array_map(fn ($x) => trim((string) $x), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
                throw ValidationException::withMessages(['file' => 'Source headers must be non-empty and unique.']);
            }$n = 1;
            while (($values = fgetcsv($h, 0, $batch->delimiter)) !== false) {
                $n++;
                if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => "Row {$n} has a different column count. Check the selected delimiter."]);
                }yield [$n, array_combine($headers, array_map(fn ($v) => trim((string) $v), $values))];
            }
        } finally {
            fclose($h);
        }
    }

    public function map(int $id, int $batchId, array $data): OnboardingBatch
    {
        $this->permit('manage');

        return DB::transaction(function () use ($id, $batchId, $data) {
            $s = $this->lock($id);
            $this->revision($s, (int) ($data['revision'] ?? -1));
            $this->editable($s);
            $batch = $s->batches()->where('is_active', true)->findOrFail($batchId);
            $mapping = validator($data, ['mapping' => 'present|array'])->validate()['mapping'];
            foreach ($mapping as $field => $column) {
                if (! in_array($field, OnboardingSchema::DOMAINS[$batch->domain], true) || ! in_array($column, $batch->headers, true)) {
                    throw ValidationException::withMessages(['mapping' => 'Select an existing source column and destination field.']);
                }
            }if (count(array_unique($mapping)) !== count($mapping)) {
                throw ValidationException::withMessages(['mapping' => 'One source column cannot silently map to several business fields.']);
            }$this->invalidate($s, 'mapping.saved', ['batch_id' => $batch->id, 'mapping' => $mapping]);
            $batch->update(['mapping' => $mapping]);
            $batch->rows()->update(['status' => 'unvalidated']);

            return $batch->fresh();
        });
    }

    public function editRows(int $id, array $data): OnboardingSession
    {
        $this->permit('manage');
        $v = validator($data, ['revision' => 'required|integer', 'rows' => 'required|array|min:1|max:100', 'rows.*.id' => 'required|integer', 'rows.*.overrides' => 'sometimes|array', 'rows.*.action' => 'sometimes|in:create,use_existing,skip', 'rows.*.match_id' => 'nullable|integer', 'rows.*.reason' => 'nullable|string|max:500'])->validate();

        return DB::transaction(function () use ($id, $v) {
            $s = $this->lock($id);
            $this->revision($s, (int) $v['revision']);
            $this->editable($s);
            foreach ($v['rows'] as $edit) {
                $row = $s->rows()->active()->lockForUpdate()->findOrFail($edit['id']);
                $before = ['overrides' => $row->overrides, 'resolution' => $row->resolution, 'match_id' => $row->match_id];
                $values = [];
                if (isset($edit['overrides'])) {
                    if (array_diff(array_keys($edit['overrides']), OnboardingSchema::DOMAINS[$row->domain])) {
                        throw ValidationException::withMessages(['row' => 'Unknown staged field.']);
                    }foreach ($edit['overrides'] as $x) {
                        if (! is_scalar($x) && $x !== null) {
                            throw ValidationException::withMessages(['row' => 'Use plain staged field values.']);
                        }
                    }$values['overrides'] = [...($row->overrides ?? []), ...array_map(fn ($value) => $value === null ? '' : $value, $edit['overrides'])];
                }
                if (isset($edit['action'])) {
                    if ($edit['action'] === 'skip' && mb_strlen(trim($edit['reason'] ?? '')) < 5) {
                        throw ValidationException::withMessages(['reason' => 'Record why this source row is excluded.']);
                    }$values['resolution'] = ['action' => $edit['action'], 'reason' => $edit['reason'] ?? null, 'reviewed_by' => Auth::id()];
                    $values['match_id'] = $edit['action'] === 'use_existing' ? ($edit['match_id'] ?? null) : null;
                }
                $row->update($values + ['status' => 'unvalidated']);
                $this->event($s, 'row.reviewed', ['row_id' => $row->id, 'before' => $before, 'after' => $values]);
            }$this->invalidate($s, 'rows.changed', ['row_ids' => array_column($v['rows'], 'id')]);

            return $s->fresh();
        });
    }

    public function cancel(int $id, int $revision, string $reason): OnboardingSession
    {
        $this->permit('manage');

        return DB::transaction(function () use ($id, $revision, $reason) {
            $s = $this->lock($id);
            $this->revision($s, $revision);
            $this->editable($s);
            if (mb_strlen(trim($reason)) < 5) {
                throw ValidationException::withMessages(['reason' => 'Record why onboarding is cancelled.']);
            }$this->event($s, 'session.cancelled', ['reason' => $reason]);
            $s->update(['status' => 'cancelled', 'cancelled_at' => now(), 'revision' => $s->revision + 1, 'approved_fingerprint' => null]);

            return $s;
        });
    }
}
