<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExportService
{
    public const LISTS = ['products', 'categories', 'suppliers', 'stock_movements', 'invoices'];

    public function export(string $list, string $format): Response|StreamedResponse
    {
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
        $headers = ['Date', 'Product', 'Type', 'Qty', 'Reason'];
        $rows = StockMovement::with('product')->latest()->get()->map(fn (StockMovement $m) => [
            $m->created_at, $m->product?->name, $m->type, $m->quantity, $m->reason,
        ])->all();

        return [$headers, $rows];
    }

    private function invoiceRows(): array
    {
        $headers = ['Number', 'Customer', 'Status', 'Total'];
        $rows = Invoice::orderByDesc('id')->get()->map(fn (Invoice $i) => [
            $i->invoice_number, $i->customer_name, $i->status, $i->total_amount,
        ])->all();

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
                fputcsv($h, $row);
            }
            fclose($h);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$list.'.csv"',
        ]);
    }

    private function asExcel(string $list, array $headers, array $rows): Response
    {
        $xml = '<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml .= '<Worksheet ss:Name="data"><Table>';
        $xml .= '<Row>'.implode('', array_map(fn ($h) => '<Cell><Data ss:Type="String">'.htmlspecialchars((string) $h).'</Data></Cell>', $headers)).'</Row>';
        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($row as $cell) {
                $type = is_numeric($cell) ? 'Number' : 'String';
                $xml .= '<Cell><Data ss:Type="'.$type.'">'.htmlspecialchars((string) $cell).'</Data></Cell>';
            }
            $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet></Workbook>';

        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="'.$list.'.xlsx"',
        ]);
    }
}
