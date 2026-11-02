<?php

namespace App\Console\Commands;

use App\Services\Platform\McpServer;
use Illuminate\Console\Command;

class McpStdio extends Command
{
    protected $signature = 'aims:mcp';

    protected $description = 'Read-only AIMS MCP stdio adapter (credential in AIMS_MCP_TOKEN environment variable)';

    public function handle(): int
    {
        $server = app(McpServer::class);
        $token = (string) getenv('AIMS_MCP_TOKEN');
        while (($line = fgets(STDIN, 65538)) !== false) {
            if (strlen($line) > 65536) {
                while (! str_ends_with($line, "\n") && ! feof(STDIN)) {
                    $line = fgets(STDIN, 65538) ?: '';
                }$response = ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Message too large']];
            } else {
                try {
                    $message = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    $response = $server->handle($message, $token);
                } catch (\JsonException $e) {
                    $response = ['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Invalid JSON']];
                }
            }if ($response !== null) {
                fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            }
        }

return self::SUCCESS;
    }
}
