<?php

namespace App\Services\Onboarding;

use App\Models\AccountingPostingMapping;
use App\Models\OnboardingSession;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

class OnboardingFingerprint
{
    public function source(OnboardingSession $s): string
    {
        $hash = hash_init('sha256');
        $this->feed($hash, [$s->public_id, $s->revision, $s->name, $s->source_period, $s->cutover_date->toDateString(), $s->timezone, $s->currency, $s->expected_totals, $s->pre_backup_id, $s->separate_reviewer_required]);
        foreach ($s->batches()->where('is_active', true)->orderBy('id')->cursor() as $b) {
            if (! hash_equals($b->checksum, hash('sha256', base64_decode($b->source_base64, true)))) {
                throw ValidationException::withMessages(['source' => 'The stored source checksum changed. Posting is blocked.']);
            }
            $this->feed($hash, [$b->id, $b->checksum, $b->mapping, $b->options, $b->row_count, $b->status]);
        }
        foreach ($s->rows()->active()->orderBy('id')->cursor() as $r) {
            $this->feed($hash, [$r->id, $r->domain, $r->line_number, $r->raw, $r->overrides, $r->normalized, $r->resolution, $r->match_id]);
        }

        return hash_final($hash);
    }

    public function operational(): string
    {
        $hash = hash_init('sha256');
        foreach (OnboardingValidation::MODELS as $domain => $class) {
            foreach ($class::orderBy('id')->cursor() as $model) {
                $this->feed($hash, [$domain, $model->id, app(OnboardingValidation::class)->snapshot($model)]);
            }
        }
        foreach ([ProductUnit::class, ProductBarcode::class, AccountingPostingMapping::class] as $class) {
            foreach ($class::orderBy('id')->cursor() as $record) {
                $this->feed($hash, [$record->getTable(), $record->getAttributes()]);
            }
        }
        $this->feed($hash, auth()->user()->company->only(['base_currency', 'timezone', 'accounting_start_date', 'operational_cutover_date', 'accounting_opening_finalized_at']));
        $this->feed($hash, app(OnboardingReconciliation::class)->actual());
        $this->feed($hash, app(OnboardingReconciliation::class)->operationallyEmpty());

        return hash_final($hash);
    }

    private function feed($hash, mixed $data): void
    {
        hash_update($hash, json_encode($this->canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->canonical($v), $value);
    }
}
