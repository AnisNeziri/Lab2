<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->foreignId('account_owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['company_id','account_owner_id']);
        });
        foreach (['customer_contacts','customer_locations','customer_interactions','sales_opportunities','customer_quotations'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->foreignId('customer_id')->nullable($name==='sales_opportunities')->constrained()->restrictOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamps();
                if ($name==='customer_contacts') {
                    $t->string('name',150); $t->string('relationship_role',100)->nullable(); $t->string('email')->nullable(); $t->string('phone',50)->nullable(); $t->boolean('is_active')->default(true);
                } elseif ($name==='customer_locations') {
                    $t->string('label',100); $t->string('kind',20); $t->text('address'); $t->string('contact_name',150)->nullable(); $t->string('phone',50)->nullable(); $t->boolean('is_active')->default(true);
                } elseif ($name==='customer_interactions') {
                    $t->string('kind',30); $t->text('note'); $t->dateTime('occurred_at'); $t->string('request_key',100); $t->char('fingerprint',64);
                    $t->unique(['company_id','request_key']);
                } elseif ($name==='sales_opportunities') {
                    $t->string('title',200); $t->string('prospect_name',200)->nullable(); $t->string('stage',20)->default('lead');
                    $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
                    $t->decimal('expected_value',18,2)->nullable(); $t->char('currency',3); $t->date('expected_close_on')->nullable();
                    $t->string('next_action',500)->nullable(); $t->date('next_action_on')->nullable(); $t->text('notes')->nullable();
                    $t->index(['company_id','owner_id','stage']);
                } else {
                    $t->string('number',50); $t->string('status',20)->default('draft'); $t->char('currency',3); $t->date('quote_date'); $t->date('expires_on');
                    $t->foreignId('order_channel_id')->constrained()->restrictOnDelete(); $t->foreignId('opportunity_id')->nullable()->constrained('sales_opportunities')->restrictOnDelete();
                    $t->foreignId('converted_order_id')->nullable()->unique()->constrained('sales_orders')->restrictOnDelete();
                    $t->string('payment_type',20); $t->decimal('total_amount',18,2); $t->text('notes')->nullable();
                    $t->string('billing_address',1000)->nullable(); $t->string('delivery_address',1000)->nullable();
                    $t->string('request_key',100); $t->char('fingerprint',64); $t->dateTime('sent_at')->nullable(); $t->dateTime('accepted_at')->nullable();
                    $t->unique(['company_id','number']); $t->unique(['company_id','request_key']); $t->index(['company_id','status','expires_on']);
                }
                $t->index(['company_id','customer_id','created_at']);
            });
        }
        Schema::create('customer_quotation_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete(); $t->foreignId('quotation_id')->constrained('customer_quotations')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete(); $t->string('description'); $t->string('unit',30); $t->decimal('quantity',18,3); $t->decimal('unit_price',18,2); $t->decimal('line_total',18,2); $t->json('pricing_snapshot');
            $t->index(['company_id','quotation_id']);
        });
    }
    public function down(): void
    {
        // Ordinary rollback is only safe before commercial records exist.
        foreach (['customer_quotations','sales_opportunities','customer_interactions','customer_locations','customer_contacts'] as $name) {
            if (\Illuminate\Support\Facades\DB::table($name)->exists()) throw new RuntimeException('CRM records exist. Preserve them and use a reviewed forward migration.');
        }
        foreach (['customer_quotation_items','customer_quotations','sales_opportunities','customer_interactions','customer_locations','customer_contacts'] as $name) Schema::dropIfExists($name);
        Schema::table('customers', function (Blueprint $t) { $t->dropIndex(['company_id','account_owner_id']); $t->dropConstrainedForeignId('account_owner_id'); });
    }
};
