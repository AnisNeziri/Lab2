<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\OpenXmlWorkbook;
use App\Models\Supplier;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExportService
{
    public const LISTS = ['products', 'categories', 'suppliers', 'stock_movements', 'invoices'];

    public function export(string $list, string $format): Response|StreamedResponse
    {
        abort_unless(
            Auth::user()?->company_id,
            403,
            'Select an explicit company context before exporting company data.'
        );

        if (! in_array($list, self::LISTS, true)) {
            abort(422, 'Unknown export list.');
        }

        if (! in_array($format, ['csv', 'json', 'xlsx'], true)) {
            abort(422, 'Format must be csv, json, or xlsx.');
        }

        [$headers, $rows] = $this->rowsFor($list);

        return match ($format) {
            'json' => $this->asJson($list, $headers, $rows),
            'csv' => $this->asCsv($list, $headers, $rows),
            'xlsx' => $this->asExcel($list, $headers, $rows),
        };
    }

    private function rowsFor(string $list): array
    {
        return match ($list) {
            'products' => $this->productRows(),
            'categories' => $this->categoryRows(),
            'suppliers' => $this->supplierRows(),
            'stock_movements' => $this->stockRows(),
            'invoices' => $this->invoiceRows(),
        };
    }

    private function productRows(): array
    {
        $headers = ['Name', 'SKU', 'Category', 'Supplier', 'Qty', 'Price'];
        $rows = Product::with(['category', 'supplier'])->orderBy('name')->get()->map(fn (Product $p) => [
            $p->name, $p->sku, $p->category?->name, $p->supplier?->name, $p->quantity, $p->price,
        ])->all();

        return [$headers, $rows];
    }

    private function categoryRows(): array
    {
        $headers = ['Name', 'Products'];
        $rows = Category::withCount('products')->orderBy('name')->get()->map(fn (Category $c) => [
            $c->name, $c->products_count,
        ])->all();

        return [$headers, $rows];
    }

    private function supplierRows(): array
    {
        $headers = ['Name', 'Email', 'Phone'];
        $rows = Supplier::orderBy('name')->get()->map(fn (Supplier $s) => [
            $s->name, $s->email, $s->phone,
        ])->all();

        return [$headers, $rows];
    }

    private function stockRows(): array
    {
        $headers = ['Date', 'Product', 'SKU', 'Movement', 'Type', 'Qty', 'Unit', 'Before', 'After', 'Source', 'Source ID', 'User', 'Reason'];
        $rows = StockMovement::with(['product', 'actor:id,name'])
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->orderByDesc('id')->get()->map(fn (StockMovement $m) => [
            $m->occurred_at ?? $m->created_at,
            $m->product?->name,
            $m->product?->sku,
            $m->movement_code,
            $m->type,
            $m->quantity,
            $m->unit_snapshot ?? $m->product?->unit,
            $m->quantity_before,
            $m->quantity_after,
            $m->source_type,
            $m->source_id,
            $m->actor?->name ?? 'System',
            $m->reason,
        ])->all();

        return [$headers, $rows];
    }

    private function invoiceRows(): array
    {
        $headers = [
            'Document Type', 'Number', 'Status', 'Payment Status', 'Invoice Date', 'Supply Date',
            'Due Date', 'Customer', 'Buyer NUI', 'Buyer Fiscal Number', 'Buyer VAT Number',
            'Currency', 'Subtotal', 'Discount', 'Taxable', 'VAT', 'Grand Total', 'Paid',
            'Remaining', 'Original Invoice', 'Compliance', 'Integrity Hash',
        ];
        $rows = Invoice::with('originalInvoice:id,invoice_number')->orderByDesc('id')->get()->map(function (Invoice $i) {
            $sign = $i->document_type === 'credit_note' ? -1 : 1;

            return [
                $i->document_type,
                $i->invoice_number,
                $i->status,
                $i->payment_status,
                $i->invoice_date?->toDateString(),
                $i->supply_date?->toDateString(),
                $i->due_at?->toDateString(),
                $i->buyer_snapshot['legal_name'] ?? $i->customer_name,
                $i->buyer_snapshot['business_registration_number'] ?? null,
                $i->buyer_snapshot['fiscal_number'] ?? null,
                $i->buyer_snapshot['vat_number'] ?? null,
                $i->currency,
                $sign * (float) $i->subtotal,
                $sign * (float) $i->discount_total,
                $sign * (float) $i->taxable_total,
                $sign * (float) $i->vat_total,
                $sign * (float) $i->grand_total,
                $i->document_type === 'credit_note' ? 0 : (float) $i->total_paid,
                $i->remaining_balance,
                $i->originalInvoice?->invoice_number,
                $i->compliance_status,
                $i->integrity_hash,
            ];
        })->all();

        return [$headers, $rows];
    }

    private function asJson(string $list, array $headers, array $rows): Response
    {
        $data = collect($rows)->map(fn ($row) => array_combine($headers, $row))->values();

        return response($data->toJson(JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$list.'.json"',
        ]);
    }

    private function asCsv(string $list, array $headers, array $rows): StreamedResponse
    {
        return response()->stream(function () use ($headers, $rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, $headers);
            foreach ($rows as $row) {
                fputcsv($h, array_map($this->sanitizeCsvCell(...), $row));
            }
            fclose($h);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$list.'.csv"',
        ]);
    }

    private function sanitizeCsvCell(mixed $cell): mixed
    {
        if (! is_string($cell) || is_numeric($cell)) {
            return $cell;
        }

        // Prevent customer-entered text from becoming a spreadsheet formula.
        // An apostrophe is Excel/LibreOffice's standard literal-text marker.
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }

    private function asExcel(string $list, array $headers, array $rows): Response
    {
        $workbook = new OpenXmlWorkbook;
        $sheetRows = [array_map(fn ($header) => OpenXmlWorkbook::cell($header, 'header'), $headers)];
        foreach ($rows as $row) {
            $sheetRows[] = array_map(function ($cell, $index) use ($headers) {
                $header = strtolower((string) ($headers[$index] ?? ''));
                if (is_numeric($cell) && preg_match('/qty|products|price|subtotal|discount|taxable|vat|total|paid|remaining|amount/i', $header)) {
                    $style = preg_match('/qty|products/i', $header) ? 'integer' : 'currency';

                    return ['value' => $cell, 'style' => $style, 'type' => 'number'];
                }

                return $cell;
            }, array_values($row), array_keys(array_values($row)));
        }
        $workbook->addSheet(ucwords(str_replace('_', ' ', $list)), $sheetRows);
        $bytes = $workbook->bytes();

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$list.'.xlsx"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
