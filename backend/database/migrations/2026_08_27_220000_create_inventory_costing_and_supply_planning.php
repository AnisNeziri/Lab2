<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weighted_average_cost', 20, 6)->nullable()->after('purchase_price');
            $table->decimal('inventory_value', 22, 6)->nullable()->after('weighted_average_cost');
            $table->decimal('weight_kg', 18, 6)->nullable()->after('inventory_value');
            $table->decimal('volume_m3', 18, 6)->nullable()->after('weight_kg');
            $table->decimal('safety_stock', 18, 3)->default(0)->after('min_quantity');
            $table->decimal('reorder_point', 18, 3)->nullable()->after('safety_stock');
            $table->unsignedSmallInteger('replenishment_history_days')->default(90)->after('reorder_point');
            $table->unsignedSmallInteger('replenishment_review_days')->default(14)->after('replenishment_history_days');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('base_purchase_unit_cost', 20, 6)->nullable()->after('metadata');
            $table->decimal('landed_cost_unit', 20, 6)->nullable()->after('base_purchase_unit_cost');
            $table->decimal('final_unit_cost', 20, 6)->nullable()->after('landed_cost_unit');
            $table->decimal('cost_total', 22, 6)->nullable()->after('final_unit_cost');
            $table->decimal('weighted_average_cost_before', 20, 6)->nullable()->after('cost_total');
            $table->decimal('weighted_average_cost_after', 20, 6)->nullable()->after('weighted_average_cost_before');
            $table->decimal('inventory_value_before', 22, 6)->nullable()->after('weighted_average_cost_after');
            $table->decimal('inventory_value_after', 22, 6)->nullable()->after('inventory_value_before');
            $table->index(['company_id', 'product_id', 'occurred_at'], 'stock_move_cost_history_idx');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('purchase_unit_price', 20, 6)->nullable()->after('conversion_factor');
            $table->char('purchase_currency', 3)->nullable()->after('purchase_unit_price');
            $table->decimal('purchase_exchange_rate', 18, 8)->nullable()->after('purchase_currency');
            $table->decimal('base_purchase_cost', 22, 6)->nullable()->after('purchase_exchange_rate');
            $table->decimal('base_purchase_unit_cost', 20, 6)->nullable()->after('base_purchase_cost');
            $table->decimal('landed_cost_allocated', 22, 6)->default(0)->after('base_purchase_unit_cost');
            $table->decimal('landed_cost_unit', 20, 6)->default(0)->after('landed_cost_allocated');
            $table->decimal('final_inventory_unit_cost', 20, 6)->nullable()->after('landed_cost_unit');
            $table->decimal('weighted_average_cost_before', 20, 6)->nullable()->after('final_inventory_unit_cost');
            $table->decimal('weighted_average_cost_after', 20, 6)->nullable()->after('weighted_average_cost_before');
        });

        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_sku', 100)->nullable();
            $table->decimal('purchase_price', 20, 6)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->decimal('exchange_rate_to_base', 18, 8)->default(1);
            $table->decimal('pack_size', 18, 3)->default(1);
            $table->decimal('minimum_order_quantity', 18, 3)->default(0);
            $table->unsignedSmallInteger('usual_lead_time_days')->default(0);
            $table->boolean('is_preferred')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('supplier_description')->nullable();
            $table->timestamp('last_price_changed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'supplier_id'], 'product_supplier_company_unique');
            $table->index(['company_id', 'product_id', 'is_active'], 'product_supplier_product_active_idx');
            $table->index(['company_id', 'supplier_id', 'is_active'], 'product_supplier_supplier_active_idx');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('product_supplier_id')->nullable()->after('product_id')->constrained('product_suppliers')->nullOnDelete();
            $table->index(['product_supplier_id', 'purchase_order_id'], 'po_item_supplier_catalogue_idx');
        });

        Schema::create('product_supplier_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_supplier_id')->constrained()->cascadeOnDelete();
            $table->decimal('purchase_price', 20, 6);
            $table->char('currency', 3);
            $table->decimal('exchange_rate_to_base', 18, 8)->default(1);
            $table->decimal('base_currency_price', 20, 6);
            $table->timestamp('effective_at');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_supplier_id', 'effective_at'], 'supplier_price_history_lookup_idx');
        });

        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->string('cost_type', 30);
            $table->string('description')->nullable();
            $table->decimal('amount', 22, 6);
            $table->char('currency', 3)->default('EUR');
            $table->decimal('exchange_rate_to_base', 18, 8)->default(1);
            $table->decimal('base_currency_amount', 22, 6);
            $table->string('allocation_method', 20);
            $table->string('status', 20)->default('draft');
            $table->uuid('idempotency_key')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'landed_cost_company_idempotency_unique');
            $table->index(['company_id', 'status', 'created_at'], 'landed_cost_company_status_idx');
        });

        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landed_cost_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('allocation_basis', 24, 8)->nullable();
            $table->decimal('allocated_amount', 22, 6);
            $table->decimal('allocated_unit_cost', 20, 6);
            $table->decimal('base_purchase_unit_cost_snapshot', 20, 6)->nullable();
            $table->decimal('final_unit_cost', 20, 6)->nullable();
            $table->decimal('weighted_average_cost_before', 20, 6)->nullable();
            $table->decimal('weighted_average_cost_after', 20, 6)->nullable();
            $table->decimal('inventory_value_before', 22, 6)->nullable();
            $table->decimal('inventory_value_after', 22, 6)->nullable();
            $table->timestamps();
            $table->unique(['landed_cost_id', 'goods_receipt_item_id'], 'landed_cost_receipt_item_unique');
            $table->index(['company_id', 'product_id'], 'landed_allocation_product_idx');
        });

        $this->backfillCurrentValuation();
        $this->backfillHistoricalReceipts();
        $this->backfillLegacySuppliers();
        $this->backfillPurchaseOrderSupplierLinks();
    }

    private function backfillCurrentValuation(): void
    {
        DB::table('products')
            ->select(['id', 'quantity', 'purchase_price'])
            ->orderBy('id')
            ->chunkById(500, function ($products): void {
                foreach ($products as $product) {
                    if ($product->purchase_price === null) {
                        continue;
                    }

                    $unitCost = round((float) $product->purchase_price, 6);
                    DB::table('products')->where('id', $product->id)->update([
                        'weighted_average_cost' => $unitCost,
                        'inventory_value' => round((float) $product->quantity * $unitCost, 6),
                    ]);
                }
            });
    }

    private function backfillLegacySuppliers(): void
    {
        $now = now();
        DB::table('products')
            ->whereNotNull('supplier_id')
            ->select(['id', 'company_id', 'supplier_id', 'purchase_price'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($now): void {
                foreach ($products as $product) {
                    $catalogueId = DB::table('product_suppliers')->insertGetId([
                        'company_id' => $product->company_id,
                        'product_id' => $product->id,
                        'supplier_id' => $product->supplier_id,
                        'purchase_price' => $product->purchase_price,
                        'currency' => 'EUR',
                        'exchange_rate_to_base' => 1,
                        'pack_size' => 1,
                        'minimum_order_quantity' => 0,
                        'usual_lead_time_days' => 0,
                        'is_preferred' => true,
                        'is_active' => true,
                        'last_price_changed_at' => $product->purchase_price === null ? null : $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($product->purchase_price !== null) {
                        DB::table('product_supplier_price_history')->insert([
                            'company_id' => $product->company_id,
                            'product_supplier_id' => $catalogueId,
                            'purchase_price' => $product->purchase_price,
                            'currency' => 'EUR',
                            'exchange_rate_to_base' => 1,
                            'base_currency_price' => $product->purchase_price,
                            'effective_at' => $now,
                            'change_reason' => 'Migrated from the legacy preferred supplier price.',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    private function backfillHistoricalReceipts(): void
    {
        DB::table('goods_receipt_items')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'goods_receipts.purchase_order_id')
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'goods_receipt_items.purchase_order_item_id')
            ->select([
                'goods_receipt_items.id', 'goods_receipt_items.accepted_quantity',
                'goods_receipt_items.damaged_quantity', 'goods_receipt_items.accepted_base_quantity',
                'goods_receipt_items.damaged_base_quantity', 'purchase_order_items.unit_price',
                'purchase_orders.currency', 'purchase_orders.exchange_rate',
            ])
            ->orderBy('goods_receipt_items.id')
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $orderedQuantity = (float) $item->accepted_quantity + (float) $item->damaged_quantity;
                    $baseQuantity = (float) $item->accepted_base_quantity + (float) $item->damaged_base_quantity;
                    if ($baseQuantity <= 0) {
                        continue;
                    }

                    $rate = (float) ($item->exchange_rate ?: 1);
                    $baseCost = round($orderedQuantity * (float) $item->unit_price * $rate, 6);
                    $baseUnitCost = round($baseCost / $baseQuantity, 6);
                    DB::table('goods_receipt_items')->where('id', $item->id)->update([
                        'purchase_unit_price' => $item->unit_price,
                        'purchase_currency' => $item->currency ?: 'EUR',
                        'purchase_exchange_rate' => $rate,
                        'base_purchase_cost' => $baseCost,
                        'base_purchase_unit_cost' => $baseUnitCost,
                        'final_inventory_unit_cost' => $baseUnitCost,
                    ]);
                }
            }, 'goods_receipt_items.id', 'id');
    }

    private function backfillPurchaseOrderSupplierLinks(): void
    {
        DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereNotNull('purchase_order_items.product_id')
            ->select(['purchase_order_items.id', 'purchase_order_items.product_id', 'purchase_orders.supplier_id'])
            ->orderBy('purchase_order_items.id')
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $catalogueId = DB::table('product_suppliers')
                        ->where('product_id', $item->product_id)
                        ->where('supplier_id', $item->supplier_id)
                        ->value('id');
                    if ($catalogueId) {
                        DB::table('purchase_order_items')->where('id', $item->id)->update(['product_supplier_id' => $catalogueId]);
                    }
                }
            }, 'purchase_order_items.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
        Schema::dropIfExists('landed_costs');
        Schema::dropIfExists('product_supplier_price_history');
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropIndex('po_item_supplier_catalogue_idx');
            $table->dropConstrainedForeignId('product_supplier_id');
        });
        Schema::dropIfExists('product_suppliers');

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_unit_price', 'purchase_currency', 'purchase_exchange_rate',
                'base_purchase_cost', 'base_purchase_unit_cost', 'landed_cost_allocated',
                'landed_cost_unit', 'final_inventory_unit_cost',
                'weighted_average_cost_before', 'weighted_average_cost_after',
            ]);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_move_cost_history_idx');
            $table->dropColumn([
                'base_purchase_unit_cost', 'landed_cost_unit', 'final_unit_cost',
                'cost_total', 'weighted_average_cost_before', 'weighted_average_cost_after',
                'inventory_value_before', 'inventory_value_after',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'weighted_average_cost', 'inventory_value', 'weight_kg', 'volume_m3',
                'safety_stock', 'reorder_point', 'replenishment_history_days',
                'replenishment_review_days',
            ]);
        });
    }
};
