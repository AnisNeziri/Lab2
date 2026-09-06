<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 120);
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 191);
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'business_event_idempotency_unique');
            $table->index(['company_id', 'event_type', 'occurred_at'], 'business_event_type_idx');
            $table->index(['company_id', 'entity_type', 'entity_id'], 'business_event_entity_idx');
        });

        Schema::create('integration_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('category', 60);
            $table->string('provider_key', 80);
            $table->string('display_name');
            $table->boolean('enabled')->default(false);
            $table->text('configuration')->nullable();
            $table->text('credentials')->nullable();
            $table->string('credential_reference')->nullable();
            $table->string('health_state', 20)->default('unknown');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_data_received_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'category', 'provider_key'], 'integration_provider_unique');
            $table->index(['company_id', 'enabled', 'health_state'], 'integration_health_idx');
        });

        Schema::create('integration_operation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_provider_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 120);
            $table->string('status', 20)->default('started');
            $table->string('request_reference')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('next_retry_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'started_at'], 'integration_operation_status_idx');
        });

        Schema::create('fx_reference_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 20, 10);
            $table->string('source', 80);
            $table->timestamps();
            $table->unique(
                ['company_id', 'base_currency', 'quote_currency', 'rate_date', 'source'],
                'fx_reference_rate_unique'
            );
        });

        Schema::create('shipment_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['shipment_id', 'purchase_order_id'], 'shipment_po_unique');
        });

        Schema::create('shipment_container_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_container_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['shipment_container_id', 'purchase_order_id'], 'container_po_unique');
        });

        Schema::table('shipment_containers', function (Blueprint $table): void {
            $table->string('booking_reference')->nullable()->after('container_type');
            $table->string('bill_of_lading')->nullable()->after('booking_reference');
            $table->string('forwarder')->nullable()->after('bill_of_lading');
            $table->string('vessel_name')->nullable()->after('forwarder');
            $table->string('voyage')->nullable()->after('vessel_name');
            $table->string('origin_port')->nullable()->after('voyage');
            $table->string('destination_port')->nullable()->after('origin_port');
            $table->dateTime('etd')->nullable()->after('destination_port');
            $table->dateTime('eta')->nullable()->after('etd');
            $table->dateTime('actual_departure')->nullable()->after('eta');
            $table->dateTime('actual_arrival')->nullable()->after('actual_departure');
            $table->string('status', 30)->default('planned')->after('actual_arrival');
            $table->decimal('capacity_cbm', 18, 3)->nullable()->after('volume_m3');
            $table->decimal('capacity_weight_kg', 18, 3)->nullable()->after('capacity_cbm');
        });

        Schema::table('shipment_items', function (Blueprint $table): void {
            $table->decimal('planned_quantity', 18, 3)->nullable()->after('quantity');
            $table->decimal('loaded_quantity', 18, 3)->nullable()->after('planned_quantity');
            $table->decimal('unit_cbm', 18, 6)->nullable()->after('base_quantity');
            $table->decimal('unit_weight_kg', 18, 6)->nullable()->after('unit_cbm');
        });

        Schema::create('shipment_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_container_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key', 40)->default('shipment');
            $table->string('milestone_type', 80);
            $table->string('status', 20)->default('planned');
            $table->dateTime('planned_at')->nullable();
            $table->dateTime('estimated_at')->nullable();
            $table->dateTime('actual_at')->nullable();
            $table->string('source', 60)->default('manual');
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(
                ['company_id', 'shipment_id', 'scope_key', 'milestone_type'],
                'shipment_milestone_unique'
            );
            $table->index(['company_id', 'status', 'planned_at'], 'shipment_milestone_status_idx');
        });

        Schema::create('operational_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('exception_key', 191);
            $table->string('exception_type', 100);
            $table->string('severity', 20);
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('shipment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_container_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->json('relevant_values')->nullable();
            $table->json('next_action')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'exception_key'], 'operational_exception_unique');
            $table->index(['company_id', 'status', 'severity'], 'operational_exception_status_idx');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('endpoint_url', 2048);
            $table->text('secret');
            $table->boolean('enabled')->default(true);
            $table->json('subscribed_event_types');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'enabled'], 'webhook_endpoint_enabled_idx');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_event_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_endpoint_id', 'business_event_id'], 'webhook_event_unique');
        });

        $this->backfillShipmentPurchaseOrders();
    }

    private function backfillShipmentPurchaseOrders(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        foreach (\Illuminate\Support\Facades\DB::table('shipments')
            ->whereNotNull('purchase_order_id')
            ->select(['company_id', 'id', 'purchase_order_id'])
            ->cursor() as $shipment) {
            \Illuminate\Support\Facades\DB::table('shipment_purchase_orders')->insertOrIgnore([
                'company_id' => $shipment->company_id,
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $shipment->purchase_order_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('operational_exceptions');
        Schema::dropIfExists('shipment_milestones');

        Schema::table('shipment_items', function (Blueprint $table): void {
            $table->dropColumn(['planned_quantity', 'loaded_quantity', 'unit_cbm', 'unit_weight_kg']);
        });
        Schema::table('shipment_containers', function (Blueprint $table): void {
            $table->dropColumn([
                'booking_reference', 'bill_of_lading', 'forwarder', 'vessel_name', 'voyage',
                'origin_port', 'destination_port', 'etd', 'eta', 'actual_departure',
                'actual_arrival', 'status', 'capacity_cbm', 'capacity_weight_kg',
            ]);
        });

        Schema::dropIfExists('shipment_container_purchase_orders');
        Schema::dropIfExists('shipment_purchase_orders');
        Schema::dropIfExists('fx_reference_rates');
        Schema::dropIfExists('integration_operation_logs');
        Schema::dropIfExists('integration_providers');
        Schema::dropIfExists('business_events');
    }
};
