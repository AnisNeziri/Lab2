<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('group')->default('general');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_permission')) {
            Schema::create('role_permission', function (Blueprint $table) {
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->primary(['role_id', 'permission_id']);
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type');
                $table->string('title');
                $table->text('message');
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'read_at']);
            });
        }

        if (! Schema::hasTable('payment_transactions')) {
            Schema::create('payment_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('status')->default('pending');
                $table->string('payment_method')->default('card');
                $table->string('transaction_ref')->unique();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('cms_pages')) {
            Schema::create('cms_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('slug');
                $table->string('title');
                $table->longText('content');
                $table->boolean('is_published')->default(false);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['company_id', 'slug']);
            });
        }

        if (! Schema::hasTable('import_logs')) {
            Schema::create('import_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->string('filename');
                $table->string('status')->default('pending');
                $table->unsignedInteger('records_total')->default(0);
                $table->unsignedInteger('records_imported')->default(0);
                $table->json('errors')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'name']);
            });
        }

        if (! Schema::hasTable('warehouse_stock')) {
            Schema::create('warehouse_stock', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(0);
                $table->timestamps();
                $table->unique(['warehouse_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->string('po_number');
                $table->string('status')->default('draft');
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->timestamp('ordered_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'po_number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('warehouse_stock');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('import_logs');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
