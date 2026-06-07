<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function store(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $invoice = Invoice::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'customer_name' => $data['customer_name'],
                'status' => $data['status'] ?? 'unpaid',
                'total_amount' => 0,
                'issued_at' => $data['issued_at'] ?? now()->toDateString(),
                'due_at' => $data['due_at'] ?? null,
            ]);

            $total = 0;

            foreach ($data['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $quantity = (int) $item['quantity'];
                $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) $product->price;
                $lineTotal = round($quantity * $unitPrice, 2);
                $total += $lineTotal;

                $invoice->items()->create([
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);
            }

            $invoice->update(['total_amount' => round($total, 2)]);

            return $invoice->fresh()->load('items.product');
        });
    }

    private function generateInvoiceNumber(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        $latest = Invoice::where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = 1;

        if ($latest && preg_match('/INV-\d{4}-(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
