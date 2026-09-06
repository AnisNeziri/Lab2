<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_inspection_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name'], 'quality_template_name_uq');
        });

        Schema::create('quality_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_inspection_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('check_type', 20);
            $table->string('unit', 30)->nullable();
            $table->decimal('minimum_value', 18, 6)->nullable();
            $table->decimal('maximum_value', 18, 6)->nullable();
            $table->decimal('tolerance', 18, 6)->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        foreach (['products', 'categories', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->string('quality_inspection_mode', 20)->default('not_required');
                $table->foreignId('quality_inspection_template_id')->nullable()
                    ->constrained('quality_inspection_templates')->nullOnDelete();
                $table->index(['quality_inspection_mode'], "{$tableName}_quality_mode_idx");
            });
        }

        Schema::create('quality_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('inspection_number');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('quality_inspection_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('inspection_date');
            $table->string('inspection_scope', 20)->default('whole_receipt');
            $table->string('source_stock_state', 20)->default('available');
            $table->decimal('received_quantity', 18, 3);
            $table->decimal('inspected_quantity', 18, 3);
            $table->decimal('accepted_quantity', 18, 3)->default(0);
            $table->decimal('rejected_quantity', 18, 3)->default(0);
            $table->decimal('quarantine_quantity', 18, 3)->default(0);
            $table->decimal('damaged_quantity', 18, 3)->default(0);
            $table->string('status', 20)->default('PENDING');
            $table->string('decision', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('trace_allocations')->nullable();
            $table->foreignId('revision_of_id')->nullable()->constrained('quality_inspections')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'inspection_number'], 'quality_inspection_number_uq');
            $table->index(['company_id', 'status', 'inspection_date'], 'quality_inspection_status_idx');
        });

        Schema::create('quality_inspection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_checklist_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('check_name');
            $table->string('check_type', 20);
            $table->boolean('passed')->nullable();
            $table->decimal('numeric_value', 18, 6)->nullable();
            $table->text('text_value')->nullable();
            $table->decimal('minimum_value', 18, 6)->nullable();
            $table->decimal('maximum_value', 18, 6)->nullable();
            $table->decimal('tolerance', 18, 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_defect_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name'], 'quality_defect_category_uq');
        });

        Schema::create('quality_defects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_defect_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity', 20);
            $table->decimal('affected_quantity', 18, 3);
            $table->text('description');
            $table->dateTime('discovered_at');
            $table->foreignId('discovered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'severity', 'discovered_at'], 'quality_defect_severity_idx');
        });

        Schema::create('supplier_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('claim_number');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quality_inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('OPEN');
            $table->string('requested_outcome', 30);
            $table->string('actual_resolution', 30)->nullable();
            $table->date('claim_date');
            $table->date('expected_resolution_date')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('affected_value', 18, 2)->default(0);
            $table->decimal('financial_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->text('communication_notes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('inventory_return_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'claim_number'], 'supplier_claim_number_uq');
            $table->index(['company_id', 'status', 'claim_date'], 'supplier_claim_status_idx');
        });

        Schema::create('supplier_claim_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('affected_quantity', 18, 3);
            $table->decimal('unit_value', 18, 6)->default(0);
            $table->decimal('line_value', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_claim_defects', function (Blueprint $table): void {
            $table->foreignId('supplier_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_defect_id')->constrained()->restrictOnDelete();
            $table->primary(['supplier_claim_id', 'quality_defect_id'], 'supplier_claim_defect_pk');
        });

        Schema::create('quality_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_inspection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_claim_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document_type', 40)->default('evidence');
            $table->string('filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->longText('file_data');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_score_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('quality_weight', 5, 2)->default(35);
            $table->decimal('delivery_weight', 5, 2)->default(30);
            $table->decimal('commercial_weight', 5, 2)->default(20);
            $table->decimal('reliability_weight', 5, 2)->default(15);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('company_id');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->string('quality_inspection_mode_snapshot', 20)->default('not_required');
            $table->foreignId('quality_inspection_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quality_inspection_id');
            $table->dropColumn('quality_inspection_mode_snapshot');
        });
        Schema::dropIfExists('supplier_score_settings');
        Schema::dropIfExists('quality_attachments');
        Schema::dropIfExists('supplier_claim_defects');
        Schema::dropIfExists('supplier_claim_items');
        Schema::dropIfExists('supplier_claims');
        Schema::dropIfExists('quality_defects');
        Schema::dropIfExists('quality_defect_categories');
        Schema::dropIfExists('quality_inspection_results');
        Schema::dropIfExists('quality_inspections');
        foreach (['products', 'categories', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('quality_inspection_template_id');
                $table->dropColumn('quality_inspection_mode');
            });
        }
        Schema::dropIfExists('quality_checklist_items');
        Schema::dropIfExists('quality_inspection_templates');
    }
};
