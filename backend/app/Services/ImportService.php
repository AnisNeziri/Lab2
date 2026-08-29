<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Models\Product;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImportService
{
    public function __construct(
        private ProductService $productService,
        private CategoryRepositoryInterface $categories
    ) {}

    public function importProducts(UploadedFile $file): ImportLog
    {
        $user = Auth::user();

        $log = ImportLog::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'type' => 'products',
            'filename' => $file->getClientOriginalName(),
            'status' => 'processing',
        ]);

        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $imported = 0;
        $total = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $total++;

                if (count($row) < 4) {
                    $errors[] = "Row {$total}: insufficient columns";

                    continue;
                }

                [$name, $sku, $categoryName, $quantity] = array_pad($row, 8, null);

                $category = $this->categories->findByName(trim($categoryName));

                if (! $category) {
                    $category = $this->categories->create([
                        'company_id' => $user->company_id,
                        'name' => trim($categoryName),
                    ]);
                }

                $existing = Product::where('sku', trim($sku))->first();

                $data = [
                    'company_id' => $user->company_id,
                    'category_id' => $category->id,
                    'name' => trim($name),
                    'sku' => trim($sku),
                    'quantity' => (float) $quantity,
                    'min_quantity' => (float) ($row[5] ?? 5),
                    'price' => (float) ($row[6] ?? 0),
                    'unit' => trim($row[7] ?? 'pcs') ?: 'pcs',
                ];

                if ($existing) {
                    $this->productService->update($existing, $data, [
                        'reason' => "Product import {$log->filename} row {$total}",
                        'source_type' => 'import_log',
                        'source_id' => $log->id,
                        'idempotency_key' => "product-import-{$log->id}-row-{$total}",
                    ]);
                } else {
                    $this->productService->create($data, [
                        'reason' => "Opening balance from product import {$log->filename} row {$total}",
                        'source_type' => 'import_log',
                        'source_id' => $log->id,
                        'idempotency_key' => "product-import-{$log->id}-row-{$total}",
                    ]);
                }

                $imported++;
            }

            DB::commit();
            $log->update([
                'status' => empty($errors) ? 'completed' : 'completed_with_errors',
                'records_total' => $total,
                'records_imported' => $imported,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $log->update([
                'status' => 'failed',
                'records_total' => $total,
                'records_imported' => $imported,
                'errors' => array_merge($errors, [$e->getMessage()]),
            ]);
        } finally {
            fclose($handle);
        }

        return $log->fresh();
    }
}
