<?php

namespace App\Services;

use App\Integrations\Sef\FiscalizationPolicy;
use App\Integrations\Sef\SefConfigurationService;
use App\Models\ActivityLog;
use App\Models\FiscalConfiguration;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SefMapping;
use App\Support\CompanyClock;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Validation\ValidationException;

class FiscalWorkspace
{
    private const RECEIPT_SUMMARY=['id','company_id','invoice_id','environment','status','coupon_no','provider_transaction_id','operator_message','error_code','created_at','submitted_at','fiscalized_at'];
    public function summary(): array
    {
        $config = FiscalConfiguration::where('environment', 'test')->first();
        $counts = FiscalReceipt::selectRaw('status,COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $today = FiscalReceipt::where('status', 'fiscalized')->where('fiscalized_at', '>=', CompanyClock::today())->count();
        $official = ActivityLog::query()->where('action', 'fiscalization.fiscalized')->where('new_value->evidence_origin', 'official_test_response')->exists();
        $gaps = ['Official certification decision has not been obtained.', 'Consumer retail source workflow, credit/partial settlement, offline chronology and certified receipt printing need ATK scenario validation.'];
        if (! $official) {
            $gaps[] = 'Official ATK TEST interoperability has not been recorded for this company.';
        }
        $signing = false;
        if ($config) {
            try {
                app(SefConfigurationService::class)->assertSigning($config);
                $signing = true;
            } catch (ValidationException $e) {
                $gaps[] = collect($e->errors())->flatten()->first();
            }
        } else {
            $gaps[] = 'Configure the taxpayer TEST branch/POS and reviewed activity policy.';
        }
        $missing = Product::query()->whereNull('default_tax_code_id')->count();
        $units = SefMapping::where('kind', 'unit')->count();
        $categories = SefMapping::where('kind', 'category')->count();
        if (! $units || ! $categories) {
            $gaps[] = 'Configure explicit measurement-unit and goods/service category mappings.';
        }
        if ($missing) {
            $gaps[] = $missing.' products have no explicit default tax classification.';
        }

        return ['environment' => 'test', 'today_fiscalized' => $today, 'counts' => $counts, 'configuration' => $config,
            'signing_ready' => $signing, 'mapping_counts' => ['units' => $units, 'categories' => $categories],
            'products_missing_tax' => $missing, 'recent_failures' => FiscalReceipt::whereIn('status', ['failed', 'unknown', 'pending'])->latest('id')->limit(10)->get(self::RECEIPT_SUMMARY),
            'readiness' => ['ready_for_certification' => false, 'official_api_result' => $official ? 'PASS (recorded TEST response)' : 'NOT TESTED', 'official_certification' => false, 'gaps' => $gaps],
            'production_enabled' => false, 'automatic_submission' => false];
    }

    public function candidates(array $filters): array
    {
        $config = FiscalConfiguration::where('environment', 'test')->first();
        $q = Invoice::query()->whereNotNull('issued_at')->whereNotIn('status', ['draft', 'void'])
            ->when($filters['invoice'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('invoice_number', 'like', '%'.$s.'%'));
        $page = $q->select(['id', 'company_id', 'invoice_number', 'invoice_date', 'status', 'grand_total', 'currency', 'seller_snapshot', 'buyer_snapshot', 'external_fiscal_code', 'fiscal_receipt_number', 'issued_at'])
            ->latest('id')->paginate(25);
        $receipts = FiscalReceipt::whereIn('invoice_id', $page->getCollection()->pluck('id'))->where('environment', 'test')->get(self::RECEIPT_SUMMARY)->keyBy('invoice_id');
        $page->getCollection()->transform(fn ($i) => ['id' => $i->id, 'invoice_number' => $i->invoice_number, 'invoice_date' => $i->invoice_date, 'grand_total' => $i->grand_total, 'currency' => $i->currency, 'policy' => app(FiscalizationPolicy::class)->evaluate($i, $config), 'receipt' => $receipts->get($i->id), 'url' => '/invoices?invoice='.$i->id]);

        return $page->toArray();
    }

    public function receipt(FiscalReceipt $receipt): array
    {
        $receipt->load(['invoice:id,invoice_number', 'attempts' => fn ($q) => $q->select(['id','company_id','fiscal_receipt_id','number','state','credential_fingerprint','signature_format','http_status','response_fingerprint','started_at','finished_at','user_id'])->orderBy('number')]);
        $qr = null;
        $evidenceWarning = null;
        if ($receipt->status === 'fiscalized') {
            $success = $receipt->attempts->last(fn ($a) => $a->state === 'fiscalized');
            if ($success) {
                try {
                    $data = $receipt->attempts()->whereKey($success->id)->firstOrFail(['id','request_evidence'])->request_evidence;
                    $qr = $data['citizen_details'].'|'.$data['citizen_signature'];
                } catch (DecryptException) {
                    $evidenceWarning = 'Historical signed evidence cannot be decrypted with this restored application key. Preserve the original record and recover its encryption key; do not prepare or resend another receipt.';
                }
            }
        }

        return ['receipt' => $receipt, 'qr_content' => $qr, 'evidence_warning' => $evidenceWarning, 'qr_environment' => 'ATK TEST',
            'evidence' => ['unsigned_request_sha256' => $receipt->request_fingerprint, 'source_sha256' => $receipt->source_fingerprint],
            'source_url' => '/invoices?invoice='.$receipt->invoice_id, 'documents_url' => '/documents?entity_type=invoice&entity_id='.$receipt->invoice_id,
            'certified' => false];
    }
}
