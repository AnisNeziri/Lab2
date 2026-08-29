<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_debts') || ! Schema::hasTable('customer_debt_entries')) {
            return;
        }

        DB::transaction(function () {
            DB::table('customer_debts')->orderBy('id')->each(function ($legacy) {
                $customerId = DB::table('customers')->insertGetId([
                    'company_id' => $legacy->company_id, 'name' => $legacy->customer_name,
                    'current_debt' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $balance = 0.0;
                $entries = DB::table('customer_debt_entries')->where('customer_debt_id', $legacy->id)->orderBy('entry_date')->orderBy('id')->get();
                foreach ($entries as $entry) {
                    foreach ([['debt_added', (float) $entry->amount_owed, true], ['payment', (float) $entry->amount_paid, false]] as [$type, $amount, $increase]) {
                        if ($amount <= 0) {
                            continue;
                        }
                        $before = $balance;
                        $balance = round($balance + ($increase ? $amount : -$amount), 2);
                        DB::table('customer_debt_transactions')->insert([
                            'company_id' => $legacy->company_id, 'customer_id' => $customerId, 'type' => $type,
                            'source' => 'legacy_migration', 'amount' => $amount, 'balance_before' => $before,
                            'balance_after' => $balance, 'transaction_date' => $entry->entry_date,
                            'note' => trim(($entry->description ?? '').' '.($entry->notes ?? '')),
                            'created_at' => $entry->created_at, 'updated_at' => $entry->updated_at,
                        ]);
                    }
                }
                DB::table('customers')->where('id', $customerId)->update(['current_debt' => max(0, $balance)]);
            });
        });
    }

    public function down(): void {}
};
