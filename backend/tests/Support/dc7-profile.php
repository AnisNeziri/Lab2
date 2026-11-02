<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dc7-preview.sqlite') {
    throw new RuntimeException('Isolated DC7 fixture required.');
}
$f = json_decode(file_get_contents(__DIR__.'/../../../output/dc7-fixture.json'), true);
$owner = User::withoutGlobalScopes()->where('company_id', $f['company'])->where('email', $f['email'])->firstOrFail();
$token = 'dc7-profile-only-'.$f['company'];
$owner->update(['api_token' => hash('sha256', $token)]);
$owner->company->update(['platform_settings' => ['api_enabled' => true, 'mcp_enabled' => true, 'observability_enabled' => true]]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$out = [];
foreach (['Dashboard' => ['GET', '/api/dashboard', []], 'Action Center' => ['GET', '/api/action-center', []], 'Global Search' => ['GET', '/api/search?q=fabric%20coming%20from%20china%20for%20ABC%20customer', []], 'Ask AIMS' => ['POST', '/api/intelligence-assistant/ask', ['question' => 'how much Milano 04 is available', 'language' => 'en']], 'Inventory Position' => ['GET', '/api/inventory/products/'.$f['product'], []], 'Customer 360' => ['GET', '/api/customers/'.$f['customer'], []], 'Accounting Control Center' => ['GET', '/api/accounting/control-center', []]] as $label => [$method,$url,$body]) {
    $request = Request::create($url, $method, $body, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
    $start = microtime(true);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    $parent = $response->headers->get('traceparent');
    $trace = $parent ? explode('-', $parent)[1] : null;
    $row = DB::table('platform_spans')->where('trace_id', $trace)->where('name', 'http.result')->first();
    $a = $row ? json_decode($row->attributes, true) : [];
    $out[$label] = ['status' => $response->getStatusCode(), 'elapsed_ms' => $elapsed, 'query_count' => $a['query_count'] ?? null, 'db_duration_ms' => $a['db_duration_ms'] ?? null, 'bytes' => strlen($response->getContent()), 'trace_id' => $trace];
}
echo json_encode(['fixture' => 'isolated synthetic company; one product/customer; not a scale benchmark', 'paths' => $out], JSON_PRETTY_PRINT)."\n";
