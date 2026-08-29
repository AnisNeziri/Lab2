<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'default_warehouse_id')) {
                $table->foreignId('default_warehouse_id')->nullable()->after('supplier_id')->constrained('warehouses')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('product_units')) {
            Schema::create('product_units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('code', 30);
                $table->string('label', 100)->nullable();
                $table->boolean('allow_purchase')->default(true);
                $table->boolean('allow_sale')->default(false);
                $table->string('conversion_mode', 20)->default('fixed');
                $table->decimal('factor_to_base', 18, 6)->nullable();
                $table->boolean('is_default_purchase')->default(false);
                $table->boolean('is_default_sale')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['product_id', 'code'], 'product_unit_code_unique');
                $table->index(['company_id', 'is_active'], 'product_unit_company_active_idx');
            });
        }

        if (! Schema::hasTable('warehouse_locations')) {
            Schema::create('warehouse_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->string('type', 20);
                $table->string('code', 30);
                $table->string('name', 120);
                $table->string('path', 255);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['warehouse_id', 'path'], 'warehouse_location_path_unique');
                $table->index(['company_id', 'warehouse_id', 'type'], 'warehouse_location_lookup_idx');
            });
        }

        Schema::table('warehouse_stock', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_stock', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            }
            if (! Schema::hasColumn('warehouse_stock', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('product_id')->constrained('warehouse_locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('warehouse_stock', 'available_quantity')) {
                $table->decimal('available_quantity', 18, 3)->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('warehouse_stock', 'reserved_quantity')) {
                $table->decimal('reserved_quantity', 18, 3)->default(0)->after('available_quantity');
            }
            if (! Schema::hasColumn('warehouse_stock', 'damaged_quantity')) {
                $table->decimal('damaged_quantity', 18, 3)->default(0)->after('reserved_quantity');
            }
            if (! Schema::hasColumn('warehouse_stock', 'quarantine_quantity')) {
                $table->decimal('quarantine_quantity', 18, 3)->default(0)->after('damaged_quantity');
            }
            if (! Schema::hasColumn('warehouse_stock', 'blocked_quantity')) {
                $table->decimal('blocked_quantity', 18, 3)->default(0)->after('quarantine_quantity');
            }
        });

        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->decimal('quantity', 18, 3)->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('warehouse_id')->constrained('warehouse_locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'source_warehouse_id')) {
                $table->foreignId('source_warehouse_id')->nullable()->after('location_id')->constrained('warehouses')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'destination_warehouse_id')) {
                $table->foreignId('destination_warehouse_id')->nullable()->after('source_warehouse_id')->constrained('warehouses')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'stock_state')) {
                $table->string('stock_state', 20)->default('available')->after('destination_warehouse_id');
            }
            if (! Schema::hasColumn('stock_movements', 'warehouse_quantity_before')) {
                $table->decimal('warehouse_quantity_before', 18, 3)->nullable()->after('quantity_after');
            }
            if (! Schema::hasColumn('stock_movements', 'warehouse_quantity_after')) {
                $table->decimal('warehouse_quantity_after', 18, 3)->nullable()->after('warehouse_quantity_before');
            }
            if (! Schema::hasColumn('stock_movements', 'affects_company_quantity')) {
                $table->boolean('affects_company_quantity')->default(true)->after('warehouse_quantity_after');
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'inventory_unit')) {
                $table->string('inventory_unit', 30)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('purchase_order_items', 'conversion_mode')) {
                $table->string('conversion_mode', 20)->default('none')->after('inventory_unit');
            }
            if (! Schema::hasColumn('purchase_order_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 18, 6)->nullable()->after('conversion_mode');
            }
            if (! Schema::hasColumn('purchase_order_items', 'base_quantity')) {
                $table->decimal('base_quantity', 18, 3)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('purchase_order_items', 'received_base_quantity')) {
                $table->decimal('received_base_quantity', 18, 3)->default(0)->after('received_quantity');
            }
        });

        Schema::table('daily_sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_sale_items', 'conversion_mode')) {
                $table->string('conversion_mode', 20)->default('none')->after('unit');
            }
            if (! Schema::hasColumn('daily_sale_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 18, 6)->nullable()->after('conversion_mode');
            }
            if (! Schema::hasColumn('daily_sale_items', 'base_quantity')) {
                $table->decimal('base_quantity', 18, 3)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('daily_sale_items', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'conversion_mode')) {
                $table->string('conversion_mode', 20)->default('none')->after('unit');
            }
            if (! Schema::hasColumn('invoice_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 18, 6)->nullable()->after('conversion_mode');
            }
            if (! Schema::hasColumn('invoice_items', 'base_quantity')) {
                $table->decimal('base_quantity', 18, 3)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('invoice_items', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'exchange_rate')) {
                $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('purchase_orders', 'exchange_rate_date')) {
                $table->date('exchange_rate_date')->nullable()->after('exchange_rate');
            }
            if (! Schema::hasColumn('purchase_orders', 'exchange_rate_source')) {
                $table->string('exchange_rate_source', 255)->nullable()->after('exchange_rate_date');
            }
            if (! Schema::hasColumn('purchase_orders', 'total_amount_eur')) {
                $table->decimal('total_amount_eur', 18, 2)->nullable()->after('total_amount');
            }
        });

        Schema::table('purchase_order_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_payments', 'currency')) {
                $table->string('currency', 3)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('purchase_order_payments', 'exchange_rate')) {
                $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('purchase_order_payments', 'amount_eur')) {
                $table->decimal('amount_eur', 18, 2)->nullable()->after('exchange_rate');
            }
        });

        if (! Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->string('receipt_number');
                $table->string('supplier_document_number')->nullable();
                $table->dateTime('received_at');
                $table->string('status', 20)->default('posted');
                $table->text('notes')->nullable();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('idempotency_key');
                $table->timestamps();
                $table->unique(['company_id', 'receipt_number'], 'goods_receipt_number_unique');
                $table->unique(['company_id', 'idempotency_key'], 'goods_receipt_idempotency_unique');
            });
        }

        if (! Schema::hasTable('goods_receipt_items')) {
            Schema::create('goods_receipt_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->string('ordered_unit', 30);
                $table->decimal('accepted_quantity', 18, 3)->default(0);
                $table->decimal('damaged_quantity', 18, 3)->default(0);
                $table->decimal('rejected_quantity', 18, 3)->default(0);
                $table->decimal('accepted_base_quantity', 18, 3)->default(0);
                $table->decimal('damaged_base_quantity', 18, 3)->default(0);
                $table->string('inventory_unit', 30);
                $table->string('conversion_mode', 20)->default('none');
                $table->decimal('conversion_factor', 18, 6)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['goods_receipt_id', 'product_id'], 'goods_receipt_item_lookup_idx');
            });
        }

        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('transfer_number');
                $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('source_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->foreignId('destination_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->string('status', 20)->default('draft');
                $table->text('notes')->nullable();
                $table->dateTime('dispatched_at')->nullable();
                $table->dateTime('received_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancellation_reason')->nullable();
                $table->uuid('dispatch_idempotency_key')->nullable();
                $table->uuid('receive_idempotency_key')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'transfer_number'], 'stock_transfer_number_unique');
                $table->unique(['company_id', 'dispatch_idempotency_key'], 'stock_transfer_dispatch_unique');
                $table->unique(['company_id', 'receive_idempotency_key'], 'stock_transfer_receive_unique');
                $table->index(['company_id', 'status'], 'stock_transfer_status_idx');
            });
        }

        if (! Schema::hasTable('stock_transfer_items')) {
            Schema::create('stock_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->decimal('quantity', 18, 3);
                $table->decimal('received_quantity', 18, 3)->default(0);
                $table->decimal('damaged_quantity', 18, 3)->default(0);
                $table->string('unit_snapshot', 30);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['stock_transfer_id', 'product_id'], 'stock_transfer_product_unique');
            });
        }

        if (! Schema::hasTable('stock_transfer_receipts')) {
            Schema::create('stock_transfer_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
                $table->uuid('idempotency_key');
                $table->string('payload_hash', 64);
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->useCurrent();
                $table->unique(['company_id', 'idempotency_key'], 'transfer_receipt_idempotency_unique');
            });
        }

        if (! Schema::hasTable('shipment_containers')) {
            Schema::create('shipment_containers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
                $table->string('container_number', 30);
                $table->string('seal_number', 50)->nullable();
                $table->string('container_type', 50)->nullable();
                $table->decimal('gross_weight_kg', 18, 3)->nullable();
                $table->decimal('volume_m3', 18, 3)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'container_number'], 'shipment_container_number_unique');
            });
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'bill_of_lading')) {
                $table->string('bill_of_lading', 100)->nullable()->after('tracking_number');
            }
            if (! Schema::hasColumn('shipments', 'commercial_invoice_number')) {
                $table->string('commercial_invoice_number', 100)->nullable()->after('bill_of_lading');
            }
            if (! Schema::hasColumn('shipments', 'incoterm')) {
                $table->string('incoterm', 10)->nullable()->after('commercial_invoice_number');
            }
            if (! Schema::hasColumn('shipments', 'transshipment_port')) {
                $table->string('transshipment_port')->nullable()->after('origin_port');
            }
            if (! Schema::hasColumn('shipments', 'arrival_date')) {
                $table->dateTime('arrival_date')->nullable()->after('eta');
            }
            if (! Schema::hasColumn('shipments', 'notes')) {
                $table->text('notes')->nullable();
            }
        });

        if (! Schema::hasTable('shipment_items')) {
            Schema::create('shipment_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('shipment_container_id')->nullable()->constrained('shipment_containers')->nullOnDelete();
                $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('description');
                $table->string('unit', 30);
                $table->decimal('quantity', 18, 3);
                $table->decimal('base_quantity', 18, 3)->nullable();
                $table->timestamps();
                $table->index(['shipment_id', 'purchase_order_item_id'], 'shipment_po_item_idx');
            });
        }

        if (! Schema::hasTable('shipment_documents')) {
            Schema::create('shipment_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
                $table->string('document_type', 40);
                $table->string('filename');
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size');
                $table->string('sha256', 64);
                // Base64 in LONGTEXT is portable across MySQL and SQLite and
                // avoids MySQL's small VARBINARY/BLOB limit for PDF evidence.
                $table->longText('file_data');
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['company_id', 'shipment_id', 'document_type'], 'shipment_document_lookup_idx');
            });
        }

        $this->backfillWarehousesAndBalances();
        $this->backfillTransactionUnits();
    }

    private function backfillWarehousesAndBalances(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('warehouses')) {
            return;
        }

        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
            $warehouse = DB::table('warehouses')
                ->where('company_id', $companyId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if (! $warehouse) {
                $warehouseId = DB::table('warehouses')->insertGetId([
                    'company_id' => $companyId,
                    'name' => 'Main Warehouse',
                    'code' => 'WH-MAIN',
                    'is_active' => true,
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $warehouseId = $warehouse->id;
                DB::table('warehouses')->where('company_id', $companyId)->update(['is_default' => false]);
                DB::table('warehouses')->where('id', $warehouseId)->update(['is_default' => true]);
            }

            $products = DB::table('products')->where('company_id', $companyId)->get(['id', 'quantity']);
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update(['default_warehouse_id' => $warehouseId]);
                DB::table('warehouse_stock')->updateOrInsert(
                    ['warehouse_id' => $warehouseId, 'product_id' => $product->id],
                    [
                        'company_id' => $companyId,
                        'quantity' => $product->quantity,
                        'available_quantity' => $product->quantity,
                        'reserved_quantity' => 0,
                        'damaged_quantity' => 0,
                        'quarantine_quantity' => 0,
                        'blocked_quantity' => 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
                DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('product_id', $product->id)
                    ->whereNull('warehouse_id')
                    ->update(['warehouse_id' => $warehouseId]);
            }
        }

        DB::table('warehouse_stock')
            ->whereNull('company_id')
            ->update([
                'company_id' => DB::raw('(SELECT company_id FROM warehouses WHERE warehouses.id = warehouse_stock.warehouse_id)'),
            ]);
    }

    private function backfillTransactionUnits(): void
    {
        if (Schema::hasTable('purchase_order_items')) {
            DB::table('purchase_order_items')->whereNull('inventory_unit')->update([
                'inventory_unit' => DB::raw('unit'),
                'conversion_mode' => 'none',
                'base_quantity' => DB::raw('quantity'),
                'received_base_quantity' => DB::raw('received_quantity'),
            ]);
        }
        if (Schema::hasTable('daily_sale_items')) {
            DB::table('daily_sale_items')->whereNull('base_quantity')->update([
                'conversion_mode' => 'none',
                'base_quantity' => DB::raw('quantity'),
            ]);
        }
        if (Schema::hasTable('invoice_items')) {
            DB::table('invoice_items')->whereNull('base_quantity')->update([
                'conversion_mode' => 'none',
                'base_quantity' => DB::raw('quantity'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_documents');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipment_containers');
        Schema::table('shipments', function (Blueprint $table) {
            foreach (['bill_of_lading', 'commercial_invoice_number', 'incoterm', 'transshipment_port', 'arrival_date', 'notes'] as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('stock_transfer_receipts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');

        Schema::table('purchase_orders', function (Blueprint $table) {
            foreach (['warehouse_id', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'total_amount_eur'] as $column) {
                if (Schema::hasColumn('purchase_orders', $column)) {
                    $column === 'warehouse_id' ? $table->dropConstrainedForeignId($column) : $table->dropColumn($column);
                }
            }
        });

        Schema::table('purchase_order_payments', function (Blueprint $table) {
            foreach (['currency', 'exchange_rate', 'amount_eur'] as $column) {
                if (Schema::hasColumn('purchase_order_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        foreach (['invoice_items', 'daily_sale_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'warehouse_id')) {
                    $table->dropConstrainedForeignId('warehouse_id');
                }
                foreach (['conversion_mode', 'conversion_factor', 'base_quantity'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            foreach (['inventory_unit', 'conversion_mode', 'conversion_factor', 'base_quantity', 'received_base_quantity'] as $column) {
                if (Schema::hasColumn('purchase_order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            foreach (['warehouse_id', 'location_id', 'source_warehouse_id', 'destination_warehouse_id'] as $column) {
                if (Schema::hasColumn('stock_movements', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach (['stock_state', 'warehouse_quantity_before', 'warehouse_quantity_after', 'affects_company_quantity'] as $column) {
                if (Schema::hasColumn('stock_movements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('warehouse_stock', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_stock', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }
            if (Schema::hasColumn('warehouse_stock', 'company_id')) {
                $table->dropConstrainedForeignId('company_id');
            }
            foreach (['available_quantity', 'reserved_quantity', 'damaged_quantity', 'quarantine_quantity', 'blocked_quantity'] as $column) {
                if (Schema::hasColumn('warehouse_stock', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('product_units');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'default_warehouse_id')) {
                $table->dropConstrainedForeignId('default_warehouse_id');
            }
        });
        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'is_default')) {
                $table->dropColumn('is_default');
            }
        });
    }
};
