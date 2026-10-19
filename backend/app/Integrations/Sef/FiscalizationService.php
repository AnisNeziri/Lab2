<?php

namespace App\Integrations\Sef;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\FiscalAttempt;
use App\Models\FiscalConfiguration;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use Brick\Math\BigInteger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalizationService
{
    public function __construct(private readonly FiscalizationPolicy $policy, private readonly SefPayloadBuilder $builder, private readonly SefSigner $signer, private readonly SefConfigurationService $configuration, private readonly AtkTestClient $client) {}

    public function prepare(Invoice $invoice): FiscalReceipt
    {
        return DB::transaction(function () use ($invoice) {
            $this->lockCompany();
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $config = FiscalConfiguration::where('environment', 'test')->lockForUpdate()->first();
            $decision = $this->policy->evaluate($invoice, $config);
            if ($decision['decision'] !== 'required') {
                $this->fail($decision['reason']);
            }
            $existing = FiscalReceipt::where('invoice_id', $invoice->id)->where('environment', 'test')->first();
            if ($existing) {
                return $existing;
            }
            $this->configuration->assertSigning($config);
            $coupon = (string) $config->next_coupon_no;
            if (BigInteger::of($coupon)->isGreaterThan('18446744073709551615')) {
                $this->fail('The registered coupon-number range is exhausted.');
            }
            $verification = bin2hex(random_bytes(8));
            $data = $this->builder->build($invoice, $config, $coupon, $verification);
            $data['signature'] = $this->signer->sign($data['details'], $config);
            $data['citizen_signature'] = $this->signer->sign($data['citizen_details'], $config);
            $record = FiscalReceipt::create([
                'invoice_id' => $invoice->id, 'configuration_id' => $config->id, 'environment' => 'test', 'status' => 'ready', 'coupon_no' => $coupon, 'verification_no' => $verification,
                'source_fingerprint' => $data['source_fingerprint'], 'request_fingerprint' => hash('sha256', $data['details']), 'request_evidence' => $data,
                'policy_snapshot' => $decision, 'configuration_snapshot' => $config->only(['taxpayer_nui', 'branch_id', 'pos_id', 'application_id', 'location', 'signature_format']),
                'credential_fingerprint' => $config->credential_fingerprint, 'prepared_by' => Auth::id(),
            ]);
            $config->update(['next_coupon_no' => (string) BigInteger::of($coupon)->plus(1)]);
            $this->audit('fiscalization.prepared', $record, ['request_fingerprint' => $record->request_fingerprint, 'environment' => 'test']);

            return $record;
        });
    }

    public function submit(FiscalReceipt $receipt): FiscalReceipt
    {
        // Commit pending evidence BEFORE egress. A crash after this commit never becomes a blind retry.
        $pending = DB::transaction(function () use ($receipt) {
            $this->lockCompany();
            $receipt = FiscalReceipt::lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status === 'fiscalized') {
                return ['done' => $receipt];
            }
            if (! in_array($receipt->status, ['ready', 'failed'], true)) {
                $this->fail('This delivery is pending or has an unknown outcome. Do not resend; request ATK confirmation.');
            }
            $config = FiscalConfiguration::lockForUpdate()->findOrFail($receipt->configuration_id);
            if ($receipt->environment !== 'test' || $config->environment !== 'test' || ! $config->enabled) {
                $this->fail('Enable the company ATK TEST connection explicitly before submitting.');
            }
            $this->configuration->assertSigning($config);
            $invoice = Invoice::lockForUpdate()->findOrFail($receipt->invoice_id);
            if (! hash_equals($receipt->source_fingerprint, $this->builder->fingerprint($invoice))) {
                $this->fail('The source payment/document evidence changed after preparation. Review this prepared receipt; no request was sent.');
            }
            foreach (['taxpayer_nui', 'branch_id', 'pos_id', 'application_id', 'location'] as $field) {
                if ((string) $config->$field !== (string) $receipt->configuration_snapshot[$field]) {
                    $this->fail('The registered POS identity changed. No request was sent.');
                }
            }
            $data = $receipt->request_evidence;
            if (! hash_equals($receipt->request_fingerprint, hash('sha256', $data['details']))) {
                $this->fail('The prepared request fingerprint does not match.');
            }
            // A documented rejection may be signed again after credential/profile correction, retaining the same coupon and payload.
            $data['signature'] = $this->signer->sign($data['details'], $config);
            $data['citizen_signature'] = $this->signer->sign($data['citizen_details'], $config);
            $attempt = FiscalAttempt::create(['fiscal_receipt_id' => $receipt->id, 'number' => (int) $receipt->attempts()->max('number') + 1, 'state' => 'pending', 'request_evidence' => $data,
                'credential_fingerprint' => $config->credential_fingerprint, 'signature_format' => $config->signature_format, 'started_at' => now(), 'user_id' => Auth::id()]);
            $receipt->update(['status' => 'pending', 'submitted_at' => now(), 'error_code' => null, 'operator_message' => 'ATK TEST submission pending.']);
            $this->audit($attempt->number > 1 ? 'fiscalization.retried' : 'fiscalization.attempted', $receipt, ['attempt_id' => $attempt->id, 'attempt_number' => $attempt->number, 'environment' => 'test']);

            return compact('receipt', 'config', 'attempt', 'data');
        });
        if (isset($pending['done'])) {
            return $pending['done'];
        }
        $result = $this->client->submit($pending['config'], $pending['data']);
        try {
            return $this->finish($pending['receipt'], $pending['attempt'], $result);
        } catch (QueryException $error) {
            // A provider-ID uniqueness conflict cannot be reclassified as success or a retryable rejection.
            if (! in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw $error;
            }

            return $this->finish($pending['receipt'], $pending['attempt'], [
                ...$result, 'state' => 'unknown', 'provider_transaction_id' => null, 'error_code' => 'provider_identity_conflict',
                'message' => 'ATK returned an identifier already bound to another source. Preserve evidence and obtain ATK review.',
            ]);
        }
    }

    private function finish(FiscalReceipt $receipt, FiscalAttempt $attempt, array $result): FiscalReceipt
    {
        return DB::transaction(function () use ($receipt, $attempt, $result) {
            $this->lockCompany();
            $receipt = FiscalReceipt::lockForUpdate()->findOrFail($receipt->id);
            $attempt = FiscalAttempt::lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->finished_at) {
                return $receipt;
            }
            $attempt->update(['state' => $result['state'], 'response_evidence' => $result['evidence'], 'response_fingerprint' => hash('sha256', json_encode($result['evidence'], JSON_THROW_ON_ERROR)),
                'http_status' => $result['http_status'], 'finished_at' => now()]);
            $receipt->update(['status' => $result['state'], 'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                'fiscalized_at' => $result['state'] === 'fiscalized' ? now() : null, 'error_code' => $result['error_code'], 'operator_message' => $result['message']]);
            $this->audit('fiscalization.'.$result['state'], $receipt, ['attempt_id' => $attempt->id, 'http_status' => $result['http_status'], 'error_code' => $result['error_code'], 'environment' => 'test', 'evidence_origin' => app()->runningUnitTests() ? 'local_test_double' : 'official_test_response']);

            return $receipt->fresh();
        });
    }

    public function review(FiscalReceipt $receipt, string $reason): FiscalReceipt
    {
        return DB::transaction(function () use ($receipt, $reason) {
            $this->lockCompany();
            $receipt = FiscalReceipt::lockForUpdate()->findOrFail($receipt->id);
            if (mb_strlen(trim($reason)) < 10) {
                $this->fail('Record the ATK case reference or review reason.');
            }
            $this->audit('fiscalization.review_recorded', $receipt, ['reason' => trim($reason), 'status' => $receipt->status]);

            // Recording a case never converts unknown to failed/success or authorizes a resend.
            return $receipt;
        });
    }

    private function lockCompany(): void
    {
        Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
    }

    private function audit(string $action, FiscalReceipt $receipt, array $facts): void
    {
        ActivityLog::create(['company_id' => $receipt->company_id, 'user_id' => Auth::id(), 'action' => $action, 'entity' => 'FiscalReceipt', 'entity_id' => $receipt->id, 'description' => str_replace('.', ' ', $action), 'new_value' => $facts]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['fiscalization' => $message]);
    }
}
