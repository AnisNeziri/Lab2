<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BackupRunService;
use App\Services\DataExportService;
use App\Services\PortableBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private DataExportService $exports,
        private PortableBackupService $backups,
        private BackupRunService $backupRuns,
    ) {}

    public function show(Request $request, string $list): Response|StreamedResponse
    {
        return $this->exports->export($list, $request->query('format', 'csv'));
    }

    public function backup(Request $request): Response
    {
        $validated = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string'],
            'passphrase' => ['required', 'string', 'min:12', 'max:200'],
        ]);
        $modules = $validated['modules'] ?? null;
        $passphrase = $validated['passphrase'];

        $run = $this->backupRuns->start('export', $modules ? 'selected' : 'full', $modules);
        try {
            $response = $this->backups->export($modules, $passphrase);
            $this->backupRuns->complete($run, strlen((string) $response->getContent()), 'checksum_created');

            return $response;
        } catch (\Throwable $error) {
            $this->backupRuns->fail($run, $error);
            throw $error;
        }
    }

    public function backupModules(): JsonResponse
    {
        return response()->json([
            'format' => PortableBackupService::FORMAT,
            'version' => PortableBackupService::VERSION,
            'max_bytes' => 104857600,
            'encryption' => [
                'required_for_export' => true,
                'cipher' => 'AES-256-GCM',
                'minimum_passphrase_length' => 12,
            ],
            'modules' => $this->backups->moduleCatalog(),
        ]);
    }
}
