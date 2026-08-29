<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataImportService;
use App\Services\ImportService;
use App\Services\PortableBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function __construct(
        private ImportService $importService,
        private DataImportService $dataImportService,
        private PortableBackupService $backups,
    ) {}

    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $log = $this->importService->importProducts($request->file('file'));

        return response()->json($log, 201);
    }

    public function import(Request $request, string $list): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,json,xlsx', 'max:5120'],
        ]);

        $log = $this->dataImportService->import($list, $request->file('file'));

        return response()->json($log, 201);
    }

    public function backup(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'superadmin'], true), 403, 'Only an administrator can restore company backups.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'mode' => ['nullable', 'in:merge,replace'],
            'modules' => ['nullable'],
            'passphrase' => ['nullable', 'string', 'min:12', 'max:200'],
            'confirm_replace' => ['nullable', 'boolean'],
        ]);
        $modules = $validated['modules'] ?? null;
        if (is_string($modules)) {
            $modules = array_filter(explode(',', $modules));
        } elseif ($modules !== null && ! is_array($modules)) {
            $modules = null;
        }

        $mode = $validated['mode'] ?? 'merge';
        if ($mode === 'replace' && ! $request->boolean('confirm_replace')) {
            throw ValidationException::withMessages([
                'confirm_replace' => 'Confirm replacement before existing module data is removed.',
            ]);
        }

        $result = $this->backups->restore(
            $request->file('file'),
            $modules,
            $mode,
            $validated['passphrase'] ?? null,
        );

        return response()->json($result);
    }
}
