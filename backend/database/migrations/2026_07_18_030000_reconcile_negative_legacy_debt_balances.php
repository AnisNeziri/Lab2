<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_debt_transactions')) {
            return;
        }

        DB::transaction(function () {
            $customerIds = DB::table('customer_debt_transactions')
                ->where('source', 'legacy_migration')
                ->distinct()
                ->pluck('customer_id');

            foreach ($customerIds as $customerId) {
                if (DB::table('customer_debt_transactions')
                    ->where('customer_id', $customerId)
                    ->where('source', '!=', 'legacy_migration')
                    ->exists()) {
                    continue;
                }

                $last = DB::table('customer_debt_transactions')
                    ->where('customer_id', $customerId)
                    ->where('source', 'legacy_migration')
                    ->orderByDesc('transaction_date')
                    ->orderByDesc('id')
                    ->first();

                if (! $last || (float) $last->balance_after >= 0) {
                    continue;
                }

                $amount = abs((float) $last->balance_after);
                DB::table('customer_debt_transactions')->insert([
                    'company_id' => $last->company_id,
                    'customer_id' => $last->customer_id,
                    'type' => 'positive_adjustment',
                    'source' => 'legacy_reconciliation',
                    'amount' => $amount,
                    'balance_before' => $last->balance_after,
                    'balance_after' => 0,
                    'transaction_date' => $last->transaction_date,
                    'note' => 'Automatic reconciliation of a legacy overpayment; the original imported records are preserved.',
                    'metadata' => json_encode(['legacy_last_transaction_id' => $last->id, 'reason' => 'negative_legacy_closing_balance']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('customers')->where('id', $customerId)->update(['current_debt' => 0, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Financial reconciliation records are intentionally retained for auditability.
    }
};
