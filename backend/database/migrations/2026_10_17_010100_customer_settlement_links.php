<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attribution of existing AR settlements only. These rows never hold
        // a customer balance and never create a second payment or journal.
        Schema::create('customer_settlement_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('settlement_transaction_id')->constrained('customer_debt_transactions', 'id', 'dc2_ar_settlement_payment_fk')->restrictOnDelete();
            $t->foreignId('debt_transaction_id')->constrained('customer_debt_transactions', 'id', 'dc2_ar_settlement_debt_fk')->restrictOnDelete();
            $t->decimal('amount', 18, 2);
            $t->date('allocated_on');
            $t->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('reason')->nullable();
            $t->date('released_on')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('release_reason')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'customer_id', 'allocated_on'], 'dc2_customer_allocation_date_idx');
            $t->index(['settlement_transaction_id', 'released_on'], 'dc2_customer_payment_active_idx');
            $t->index(['debt_transaction_id', 'released_on'], 'dc2_customer_debt_active_idx');
        });
        Schema::create('customer_allocation_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('idempotency_key', 100);
            $t->char('fingerprint', 64);
            $t->string('action', 20);
            $t->json('result');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at');
            $t->unique(['company_id', 'idempotency_key'], 'dc2_customer_allocation_request_unique');
        });
        app(RolePermissionSeeder::class)->run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_allocation_requests');
        Schema::dropIfExists('customer_settlement_allocations');
    }
};
