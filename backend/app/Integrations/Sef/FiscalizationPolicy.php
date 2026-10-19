<?php

namespace App\Integrations\Sef;

use App\Models\FiscalConfiguration;
use App\Models\Invoice;

class FiscalizationPolicy
{
    public function evaluate(Invoice $invoice, ?FiscalConfiguration $config): array
    {
        if (! $invoice->issued_at || in_array($invoice->status, ['draft', 'void'], true)) {
            return $this->result('not_required', 'Draft/void document; no issued fiscal supply.');
        }
        if (! $config || $config->activity_policy === 'needs_review') {
            return $this->result('needs_review', 'Confirm this company activity under MF01/2026 Article31 before preparing a receipt.');
        }
        if ($config->taxpayer_nui !== ($invoice->seller_snapshot['business_registration_number'] ?? null)) {
            return $this->result('needs_review', 'The invoice taxpayer differs from the configured ATK TEST identity.');
        }
        if ($invoice->external_fiscal_code || $invoice->fiscal_receipt_number) {
            return $this->result('needs_review', 'An external fiscal reference already exists. Verify it before any submission to prevent duplication.');
        }
        if ($config->activity_policy === 'business_only') {
            if (! empty($invoice->buyer_snapshot['business_registration_number']) && ($invoice->buyer_snapshot['customer_type'] ?? 'business') === 'business') {
                return $this->result('not_required', 'Configured exclusively B2B activity; MF01/2026 Article31.5.2 requires a tax invoice.');
            }

            return $this->result('needs_review', 'A consumer document conflicts with the exclusively B2B policy.');
        }
        if ($config->activity_policy === 'retail' && filled($config->policy_basis)) {
            return $this->result('required', 'Configured retail/mixed activity under MF01/2026 Article31.1/31.6.');
        }

        return $this->result('needs_review', 'Record the confirmed activity and policy basis.');
    }

    /** The same policy's review projection stays inside this boundary. */
    public function needsReviewCount(?FiscalConfiguration $config): int
    {
        $q = Invoice::query()->whereNotNull('issued_at')->whereNotIn('status', ['draft', 'void'])
            ->whereNotExists(fn ($v) => $v->selectRaw('1')->from('fiscal_receipts as r')->whereColumn('r.invoice_id', 'invoices.id')->whereColumn('r.company_id', 'invoices.company_id')->where('r.environment', 'test')->where('r.status', 'fiscalized'));
        if (! $config || $config->activity_policy === 'needs_review') {
            return $q->count();
        }
        $q->where(function ($v) use ($config) {
            $v->whereNull('seller_snapshot->business_registration_number')->orWhere('seller_snapshot->business_registration_number', '!=', $config->taxpayer_nui)
                ->orWhereNotNull('external_fiscal_code')->orWhereNotNull('fiscal_receipt_number');
            if ($config->activity_policy === 'business_only') {
                $v->orWhereNull('buyer_snapshot->business_registration_number')->orWhere('buyer_snapshot->business_registration_number', '')->orWhere(fn ($c) => $c->whereNotNull('buyer_snapshot->customer_type')->where('buyer_snapshot->customer_type', '!=', 'business'));
            }
        });

        return $q->count();
    }

    private function result(string $decision, string $reason): array
    {
        return compact('decision', 'reason') + ['legal_source' => 'MF01/2026 Article31', 'automatic_submission' => false];
    }
}
