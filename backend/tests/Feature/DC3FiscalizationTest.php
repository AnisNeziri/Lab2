<?php

namespace Tests\Feature;

use App\Integrations\Sef\AtkTestClient;
use App\Integrations\Sef\FiscalizationService;
use App\Integrations\Sef\Generated\PosCoupon;
use App\Integrations\Sef\SefConfigurationService;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\FiscalAttempt;
use App\Models\FiscalConfiguration;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\SefMapping;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesDC3TaxCompany;
use Tests\TestCase;

class DC3FiscalizationTest extends TestCase
{
    use CreatesDC3TaxCompany, RefreshDatabase;

    public function test_current_payload_signature_and_definite_rejection_retry_preserve_one_coupon_and_return_lineage(): void
    {
        Http::preventStrayRequests();
        Http::fake([AtkTestClient::ENDPOINT => Http::sequence()->push(['error' => 'signature profile rejected'], 400)->push(['transaction_id' => '18446744073709551000', 'message' => 'accepted'], 200)->push(['transaction_id' => '18446744073709551001', 'message' => 'return accepted'], 200)]);
        [$invoice,$config,$cert] = $this->fiscalFixture();
        $service = app(FiscalizationService::class);
        $receipt = $service->prepare($invoice);
        $again = $service->prepare($invoice);
        $this->assertSame($receipt->id, $again->id);
        $this->assertSame('2', $config->fresh()->next_coupon_no);
        $data = $receipt->request_evidence;
        $wire = new PosCoupon;
        $wire->mergeFromString(base64_decode($data['details']));
        $this->assertSame(1, $wire->getType());
        $this->assertSame(11800, $wire->getTotal());
        $this->assertSame(10000, $wire->getTotalNoTax());
        $this->assertSame(1800, $wire->getTotalTax());
        $this->assertSame(1180000, $wire->getItems()[0]->getPrice());
        $this->assertSame('EA', $wire->getItems()[0]->getUnit());
        $this->assertSame('TT', $wire->getItems()[0]->getType());
        $raw = base64_decode($data['signature']);
        $this->assertSame(64, strlen($raw));
        $integer = function ($x) {
            $x = ltrim($x, "\0");
            if (ord($x[0]) > 127) {
                $x = "\0".$x;
            }

return "\x02".chr(strlen($x)).$x;
        };
        $parts = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));
        $der = "\x30".chr(strlen($parts)).$parts;
        $this->assertSame(1, openssl_verify($data['details'], $der, $cert, OPENSSL_ALGO_SHA256));
        $failed = $service->submit($receipt);
        $this->assertSame('failed', $failed->status);
        $config->update(['signature_format' => 'der']);
        $success = $service->submit($failed);
        $this->assertSame('fiscalized', $success->status);
        $this->assertSame('18446744073709551000', $success->provider_transaction_id);
        $this->assertSame($receipt->request_fingerprint, $success->request_fingerprint);
        $this->assertSame('fiscalized', $service->submit($success)->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('fiscal_receipts', 1);
        $this->assertDatabaseCount('fiscal_attempts', 2);
        $first = FiscalAttempt::orderBy('id')->first();
        $last = FiscalAttempt::orderByDesc('id')->first();
        $this->assertSame($first->request_evidence['details'], $last->request_evidence['details']);
        $this->getJson('/api/fiscalization/receipts/'.$receipt->id)->assertOk()->assertJsonPath('qr_content',$last->request_evidence['citizen_details'].'|'.$last->request_evidence['citizen_signature'])->assertJsonMissingPath('receipt.request_evidence');
        $this->getJson('/api/fiscalization')->assertOk()->assertJsonMissingPath('configuration.credentials');
        $this->getJson('/api/fiscalization/invoices')->assertOk()->assertJsonPath('data.0.receipt.status','fiscalized');
        app(PaymentService::class)->reversePayment($invoice->payments()->firstOrFail(), 'Customer returned the full sale');
        $credit = app(InvoiceService::class)->fullCreditNote($invoice->fresh(), 'Full source-owned customer return');
        $return = $service->prepare($credit);
        $returnWire = new PosCoupon;
        $returnWire->mergeFromString(base64_decode($return->request_evidence['details']));
        $this->assertSame(3, $returnWire->getType());
        $this->assertSame(1, $returnWire->getReferenceNo());
        $this->assertSame('fiscalized', $service->submit($return)->status);
        Http::assertSentCount(3);
        $this->assertSame('fiscalized', $receipt->fresh()->status);
    }

    public function test_unknown_response_or_crash_pending_never_resends_even_after_recording_a_review(): void
    {
        Http::preventStrayRequests();
        Http::fake([AtkTestClient::ENDPOINT => Http::response(['error' => 'upstream outcome unavailable'], 500)]);
        [$invoice] = $this->fiscalFixture();
        $service = app(FiscalizationService::class);
        $receipt = $service->prepare($invoice);
        $unknown = $service->submit($receipt);
        $this->assertSame('unknown', $unknown->status);
        $service->review($unknown, 'ATK review case pending external confirmation');
        try {
            $service->submit($unknown);
            $this->fail('Unknown receipt was resent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fiscalization', $e->errors());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('fiscal_attempts', 1);
        $this->assertSame('unknown', $unknown->fresh()->status);
        $data = $this->invoiceData(TaxCode::where('code', 'KS18')->value('id'));
        $data['items'][0]['sef_category_id'] = 1;
        $draft = $this->postJson('/api/invoices', $data)->assertCreated();
        $issued = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $second = Invoice::findOrFail($issued->json('id'));
        app(PaymentService::class)->processPayment($second, ['amount' => '118', 'payment_method' => 'cash']);
        $ready = $service->prepare($second);
        $this->app->instance(AtkTestClient::class, new class extends AtkTestClient
        {
            public function submit(FiscalConfiguration $configuration, array $signed): array
            {
                throw new \RuntimeException('Simulated worker exit after the durable pending commit.');
            }
        });
        try {
            app(FiscalizationService::class)->submit($ready);
            $this->fail('Injected worker interruption did not occur.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated worker', $e->getMessage());
        }
        $this->assertSame('pending', $ready->fresh()->status);
        $this->assertNull($ready->attempts()->firstOrFail()->finished_at);
        $this->app->instance(AtkTestClient::class, new AtkTestClient);
        try {
            app(FiscalizationService::class)->submit($ready->fresh());
            $this->fail('A crashed pending request was resent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fiscalization', $e->errors());
        }
        Http::assertSentCount(1);

    }

    public function test_missing_unit_mapping_blocks_before_reserving_a_coupon_or_sending_and_secrets_are_encrypted(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$invoice,$config] = $this->fiscalFixture();
        SefMapping::query()->delete();
        $count = JournalEntry::count();
        try {
            app(FiscalizationService::class)->prepare($invoice);
            $this->fail('Missing unit mapping accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('measurement-unit', implode(' ', $e->errors()['fiscalization']));
        }
        $this->assertSame('1', $config->fresh()->next_coupon_no);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertSame($count, JournalEntry::count());
        Http::assertNothingSent();
        $this->assertArrayNotHasKey('credentials', $config->toArray());
        $raw = DB::table('fiscal_configurations')->where('id', $config->id)->value('credentials');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(ActivityLog::query()->get()));
    }

    public function test_tenant_source_and_production_transport_are_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$invoice,$config] = $this->fiscalFixture();
        $foreign = Company::factory()->create();
        $invoice->company_id = $foreign->id;
        // A fresh scoped lookup must ignore the caller's forged in-memory company, not follow it to a foreign source.
        $this->assertSame($this->apiCompany->id, app(FiscalizationService::class)->prepare($invoice)->company_id);
        $owner = Auth::user();
        $otherUser = User::factory()->create(['company_id' => $foreign->id, 'role' => 'admin']);
        Auth::setUser($otherUser);
        $foreignDraft = app(InvoiceService::class)->createDraft(['invoice_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            'buyer' => ['legal_name' => 'Foreign buyer', 'business_registration_number' => '812111111', 'address' => 'Other company address'],
            'items' => [['description' => 'Foreign draft', 'unit' => 'pcs', 'quantity' => 1, 'unit_price' => 1, 'tax_treatment' => 'non_vat', 'vat_rate' => 0]]]);
        Auth::setUser($owner);
        $this->postJson('/api/fiscalization/invoices/'.$foreignDraft->id.'/prepare')->assertNotFound();
        try {
            app(FiscalizationService::class)->prepare($foreignDraft);
            $this->fail('Foreign-company document was accessed.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(Invoice::class, $e->getModel());
        }

        $config->environment = 'production';
        try {
            app(AtkTestClient::class)->submit($config, ['details' => 'x', 'signature' => 'y']);
            $this->fail('Production transport was called.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('ATK TEST', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    private function fiscalFixture(): array
    {
        $this->setupTax();
        $data = $this->invoiceData(TaxCode::where('code', 'KS18')->value('id'));
        $data['items'][0]['sef_category_id'] = 1;
        $draft = $this->postJson('/api/invoices', $data)->assertCreated();
        $issued = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $invoice = Invoice::findOrFail($issued->json('id'));
        app(PaymentService::class)->processPayment($invoice, ['amount' => '118', 'payment_method' => 'cash']);
        SefMapping::create(['kind' => 'unit', 'local_key' => 'pcs', 'official_id' => 1]);
        $config = app(SefConfigurationService::class)->configure(['environment' => 'test', 'branch_id' => '1', 'pos_id' => '1', 'application_id' => '1', 'location' => 'Test only', 'activity_policy' => 'retail', 'policy_basis' => 'Synthetic retail activity for local interoperability tests', 'enabled' => true, 'signature_format' => 'ieee_p1363', 'starting_coupon_no' => '1']);
        $opensslConfig = tempnam(sys_get_temp_dir(), 'aims-sef-conf-');
        file_put_contents($opensslConfig, "[req]\ndefault_bits=2048\ndistinguished_name=dn\n[dn]\n");
        try {
            $options = ['config' => $opensslConfig, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new($options);
            openssl_pkey_export($key, $keyPem, null, $options);
            $csr = openssl_csr_new(['countryName' => 'XK', 'organizationName' => $config->taxpayer_nui, 'organizationalUnitName' => '1', 'localityName' => '1', 'commonName' => 'Synthetic local test certificate'], $key, $options);
            $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
            openssl_x509_export($certificate,$certPem);
        } finally {
            unlink($opensslConfig);
        }
        app(SefConfigurationService::class)->credentials($config,$keyPem,$certPem);

        return [$invoice->fresh(), $config->fresh(), $certPem];
    }
}
