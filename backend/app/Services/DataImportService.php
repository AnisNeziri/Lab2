<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ImportLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DataImportService
{
    public const LISTS = ['products', 'categories', 'suppliers', 'stock_movements', 'invoices'];

    public function __construct(
        private ImportService $productImporter
    ) {}

    public function import(string $list, UploadedFile $file): ImportLog
    {
        if (! in_array($list, self::LISTS, true)) {
            abort(422, 'Unknown import list.');
        }

        if ($list === 'products') {
            return $this->productImporter->importProducts($file);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $rows = match ($extension) {
            'json' => $this->rowsFromJson($file),
            'csv', 'txt' => $this->rowsFromCsv($file),
            'xlsx' => $this->rowsFromSpreadsheet($file),
            default => abort(422, 'Use csv, json, or xlsx files.'),
        };

        return match ($list) {
            'categories' => $this->importCategories($file, $rows),
            'suppliers' => $this->importSuppliers($file, $rows),
            'stock_movements' => $this->importStockMovements($file, $rows),
            'invoices' => $this->importInvoices($file, $rows),
            default => abort(422, 'Unsupported import list.'),
        };
    }

    private function rowsFromCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map('strtolower', array_map('trim', fgetcsv($handle) ?: []));
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = $line[$index] ?? null;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function rowsFromJson(UploadedFile $file): array
    {
        $decoded = json_decode(file_get_contents($file->getRealPath()), true);

        if (! is_array($decoded)) {
            abort(422, 'JSON file must contain an array of records.');
        }

        return array_map(function ($row) {
            if (! is_array($row)) {
                return [];
            }

            return array_change_key_case($row, CASE_LOWER);
        }, $decoded);
    }

    private function rowsFromSpreadsheet(UploadedFile $file): array
    {
        $xml = simplexml_load_string(file_get_contents($file->getRealPath()));

        if (! $xml) {
            abort(422, 'Could not read Excel file.');
        }

        $rows = [];
        $header = [];

        foreach ($xml->Worksheet->Table->Row as $index => $row) {
            $cells = [];
            foreach ($row->Cell as $cell) {
                $cells[] = (string) ($cell->Data ?? '');
            }

            if ($index === 0) {
                $header = array_map(fn ($value) => strtolower(trim($value)), $cells);
                continue;
            }

            $record = [];
            foreach ($header as $colIndex => $column) {
                $record[$column] = $cells[$colIndex] ?? null;
            }
            $rows[] = $record;
        }

        return $rows;
    }

    private function importCategories(UploadedFile $file, array $rows): ImportLog
    {
        return $this->runImport($file, 'categories', $rows, function (array $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                throw new \InvalidArgumentException('Category name is required.');
            }

            Category::updateOrCreate(
                ['company_id' => Auth::user()->company_id, 'name' => $name],
                ['name' => $name]
            );
        });
    }

    private function importSuppliers(UploadedFile $file, array $rows): ImportLog
    {
        return $this->runImport($file, 'suppliers', $rows, function (array $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                throw new \InvalidArgumentException('Supplier name is required.');
            }

            Supplier::updateOrCreate(
                ['company_id' => Auth::user()->company_id, 'name' => $name],
                [
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'address' => $row['address'] ?? null,
                ]
            );
        });
    }

    private function importStockMovements(UploadedFile $file, array $rows): ImportLog
    {
        return $this->runImport($file, 'stock_movements', $rows, function (array $row) {
            $sku = trim((string) ($row['sku'] ?? $row['product_sku'] ?? ''));
            $type = strtolower(trim((string) ($row['type'] ?? 'in')));
            $quantity = (int) ($row['quantity'] ?? $row['qty'] ?? 0);

            if ($sku === '' || $quantity < 1 || ! in_array($type, ['in', 'out'], true)) {
                throw new \InvalidArgumentException('Stock row needs sku, type in/out, and quantity.');
            }

            $product = Product::where('sku', $sku)->first();

            if (! $product) {
                throw new \InvalidArgumentException("Product SKU {$sku} not found.");
            }

            $before = $product->quantity;
            $after = $type === 'in' ? $before + $quantity : $before - $quantity;

            if ($after < 0) {
                throw new \InvalidArgumentException("Not enough stock for SKU {$sku}.");
            }

            $product->update(['quantity' => $after]);

            StockMovement::create([
                'company_id' => Auth::user()->company_id,
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => $row['reason'] ?? 'Imported',
            ]);
        });
    }

    private function importInvoices(UploadedFile $file, array $rows): ImportLog
    {
        return $this->runImport($file, 'invoices', $rows, function (array $row) {
            $number = trim((string) ($row['number'] ?? $row['invoice_number'] ?? ''));
            $customer = trim((string) ($row['customer'] ?? $row['customer_name'] ?? ''));

            if ($number === '' || $customer === '') {
                throw new \InvalidArgumentException('Invoice number and customer are required.');
            }

            Invoice::updateOrCreate(
                [
                    'company_id' => Auth::user()->company_id,
                    'invoice_number' => $number,
                ],
                [
                    'customer_name' => $customer,
                    'status' => $row['status'] ?? 'unpaid',
                    'total_amount' => (float) ($row['total'] ?? $row['total_amount'] ?? 0),
                    'issued_at' => $row['issued_at'] ?? now()->toDateString(),
                ]
            );
        });
    }

    private function runImport(UploadedFile $file, string $type, array $rows, callable $handler): ImportLog
    {
        $user = Auth::user();
        $log = ImportLog::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'type' => $type,
            'filename' => $file->getClientOriginalName(),
            'status' => 'processing',
        ]);

        $imported = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                try {
                    $handler($row);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = 'Row '.($index + 1).': '.$e->getMessage();
                }
            }

            DB::commit();

            $log->update([
                'status' => empty($errors) ? 'completed' : 'completed_with_errors',
                'records_total' => count($rows),
                'records_imported' => $imported,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $log->update([
                'status' => 'failed',
                'records_total' => count($rows),
                'records_imported' => $imported,
                'errors' => array_merge($errors, [$e->getMessage()]),
            ]);
        }

        return $log->fresh();
    }
}
