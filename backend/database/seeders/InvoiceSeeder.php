<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = Company::firstOrFail()->id;
        $products = Product::orderBy('name')->get()->keyBy('sku');

        if ($products->isEmpty()) {
            return;
        }

        $invoices = [
            [
                'invoice_number' => 'INV-2026-001',
                'customer_name' => 'Alpha Market',
                'status' => 'paid',
                'issued_at' => Carbon::now()->subDays(8),
                'due_at' => Carbon::now()->addDays(6),
                'paid_at' => Carbon::now()->subDays(3),
                'items' => [
                    ['sku' => 'ELEC-001', 'quantity' => 4],
                    ['sku' => 'OFF-001', 'quantity' => 10],
                ],
            ],
            [
                'invoice_number' => 'INV-2026-002',
                'customer_name' => 'North Office',
                'status' => 'sent',
                'issued_at' => Carbon::now()->subDays(4),
                'due_at' => Carbon::now()->addDays(10),
                'paid_at' => null,
                'items' => [
                    ['sku' => 'FUR-001', 'quantity' => 1],
                    ['sku' => 'CLT-001', 'quantity' => 3],
                ],
            ],
            [
                'invoice_number' => 'INV-2026-003',
                'customer_name' => 'Tech Point',
                'status' => 'overdue',
                'issued_at' => Carbon::now()->subDays(20),
                'due_at' => Carbon::now()->subDays(5),
                'paid_at' => null,
                'items' => [
                    ['sku' => 'ELEC-002', 'quantity' => 2],
                ],
            ],
        ];

        foreach ($invoices as $invoiceData) {
            $items = $invoiceData['items'];
            unset($invoiceData['items']);

            $invoice = Invoice::firstOrCreate(
                ['company_id' => $companyId, 'invoice_number' => $invoiceData['invoice_number']],
                [
                    ...$invoiceData,
                    'company_id' => $companyId,
                    'total_amount' => 0,
                ]
            );

            if ($invoice->items()->exists()) {
                continue;
            }

            $total = 0;

            foreach ($items as $item) {
                $product = $products->get($item['sku']);

                if (! $product) {
                    continue;
                }

                $lineTotal = $item['quantity'] * (float) $product->price;
                $total += $lineTotal;

                $invoice->items()->create([
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'line_total' => $lineTotal,
                ]);
            }

            $invoice->update(['total_amount' => $total]);
        }
    }
}
