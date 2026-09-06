<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('rule_type', 80);
            $table->decimal('threshold_amount', 18, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('required_role', 40)->nullable();
            $table->foreignId('required_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('separation_of_duties')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'rule_type'], 'approval_rule_company_type_unique');
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('entity_id');
            $table->string('rule_type', 80);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('required_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('required_role', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->decimal('requested_amount', 18, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'required_role'], 'approval_pending_role_idx');
            $table->index(['company_id', 'entity_type', 'entity_id'], 'approval_entity_idx');
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->index(['approval_request_id', 'decided_at'], 'approval_decision_request_idx');
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('request_number', 40);
            $table->string('status', 24)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('requested_at');
            $table->date('required_by')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->string('currency', 3);
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'request_number'], 'purchase_request_company_number_unique');
            $table->index(['company_id', 'status', 'required_by'], 'purchase_request_status_due_idx');
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('unit', 50);
            $table->decimal('quantity', 18, 3);
            $table->decimal('estimated_unit_price', 18, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->string('rfq_number', 40);
            $table->string('status', 24)->default('draft');
            $table->date('issued_at')->nullable();
            $table->date('response_due_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'rfq_number'], 'rfq_company_number_unique');
        });

        Schema::create('rfq_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 24)->default('invited');
            $table->timestamps();
            $table->unique(['rfq_id', 'supplier_id'], 'rfq_supplier_unique');
        });

        Schema::create('supplier_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 24)->default('received');
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('shipping_terms')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['rfq_id', 'supplier_id', 'revision'], 'supplier_quote_revision_unique');
            $table->index(['company_id', 'status', 'valid_until'], 'supplier_quote_status_expiry_idx');
        });

        Schema::create('supplier_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->constrained()->restrictOnDelete();
            $table->decimal('offered_quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('minimum_order_quantity', 18, 3)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['supplier_quote_id', 'purchase_request_item_id'], 'supplier_quote_item_unique');
        });

        Schema::create('procurement_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_request_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_quote_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('selected');
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at');
            $table->uuid('conversion_key')->nullable();
            $table->timestamps();
            $table->unique(['rfq_id', 'purchase_request_item_id'], 'procurement_award_item_unique');
            $table->index(['company_id', 'conversion_key'], 'procurement_award_conversion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_awards');
        Schema::dropIfExists('supplier_quote_items');
        Schema::dropIfExists('supplier_quotes');
        Schema::dropIfExists('rfq_suppliers');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_rules');
    }
};
