<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_configurations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('environment', 12);
            $t->string('taxpayer_nui', 20);
            $t->string('branch_id', 20);
            $t->string('pos_id', 20);
            $t->string('application_id', 20);
            $t->string('location', 160);
            $t->boolean('enabled')->default(false);
            $t->string('activity_policy', 30)->default('needs_review');
            $t->text('policy_basis')->nullable();
            $t->string('next_coupon_no', 20)->default('1');
            $t->string('signature_format', 20)->default('ieee_p1363');
            $t->longText('credentials')->nullable();
            $t->string('credential_fingerprint', 64)->nullable();
            $t->timestamp('certificate_valid_from')->nullable();
            $t->timestamp('certificate_valid_to')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'environment']);
            $t->unique(['environment', 'taxpayer_nui']);
        });
        Schema::create('sef_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('kind', 12);
            $t->string('local_key', 80);
            $t->unsignedSmallInteger('official_id');
            $t->timestamps();
            $t->unique(['company_id', 'kind', 'local_key']);
        });
        Schema::create('fiscal_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('configuration_id')->constrained('fiscal_configurations')->restrictOnDelete();
            $t->string('environment', 12);
            $t->string('status', 24);
            $t->string('coupon_no', 20);
            $t->string('verification_no', 16);
            $t->string('source_fingerprint', 64);
            $t->string('request_fingerprint', 64);
            $t->longText('request_evidence');
            $t->json('policy_snapshot');
            $t->json('configuration_snapshot');
            $t->string('credential_fingerprint', 64);
            $t->string('provider_transaction_id', 20)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('fiscalized_at')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->string('operator_message', 500)->nullable();
            $t->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'environment', 'invoice_id']);
            $t->unique(['company_id', 'environment', 'coupon_no']);
            $t->unique(['company_id', 'environment', 'verification_no']);
            $t->unique(['company_id', 'environment', 'request_fingerprint'], 'fiscal_request_fingerprint_unique');
            $t->unique(['company_id', 'environment', 'provider_transaction_id'], 'fiscal_provider_id_unique');
            $t->index(['company_id', 'status', 'created_at']);
        });
        Schema::create('fiscal_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('fiscal_receipt_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('state', 24);
            $t->string('credential_fingerprint', 64);
            $t->string('signature_format', 20);
            $t->longText('request_evidence');
            $t->longText('response_evidence')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('response_fingerprint', 64)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['fiscal_receipt_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_attempts');
        Schema::dropIfExists('fiscal_receipts');
        Schema::dropIfExists('sef_mappings');
        Schema::dropIfExists('fiscal_configurations');
    }
};
