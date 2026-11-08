<?php
require_once __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\{Auth, Event};
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;

$capturedMail = '';
Event::listen(Illuminate\Mail\Events\MessageSending::class, function ($event) use (&$capturedMail) {
    $capturedMail = $event->message->getTextBody() ?? $event->message->getHtmlBody();
});

/** Real HTTP kernel, guards, validation and middleware; array mail only. */
function dc16Request(string $method, string $path, array $data = [], ?string $token = null, array $files = [], array $expected = [200, 201, 204]): array
{
    global $dc16Root;
    Auth::forgetGuards();
    $headers = ['HTTP_ACCEPT' => 'application/json'];
    if ($token) $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    if (!$files) $headers['CONTENT_TYPE'] = 'application/json';
    $request = Request::create('/api/'.$path, $method, $files ? $data : [], [], $files, $headers, $files ? null : json_encode($data));
    $start = microtime(true);
    $kernel = app(Kernel::class);
    $response = $kernel->handle($request);
    // Respect the real limiter; never disable it for batch account creation.
    for ($attempt = 0; $response->getStatusCode() === 429 && $attempt < 3; $attempt++) {
        $delay = min(60, max(1, (int) $response->headers->get('Retry-After', 60)));
        echo "Rate limit: waiting {$delay}s before {$method} {$path}.\n";
        $kernel->terminate($request, $response);
        sleep($delay);
        Auth::forgetGuards();
        $response = $kernel->handle($request);
    }
    $body = json_decode($response->getContent(), true) ?? [];
    $kernel->terminate($request, $response);
    $entry = ['at' => date(DATE_ATOM), 'method' => $method, 'path' => $path, 'status' => $response->getStatusCode(), 'ms' => round((microtime(true)-$start)*1000, 2), 'bytes' => strlen($response->getContent()), 'dc7_telemetry'=>app(App\Services\Platform\PlatformTrace::class)->stats(), 'expected_status' => $expected];
    file_put_contents($dc16Root.'/storage/evidence/requests.jsonl', json_encode($entry)."\n", FILE_APPEND | LOCK_EX);
    if (!in_array($entry['status'], $expected, true)) {
        dc16Evidence('last-failure', $entry + ['body' => $body]);
        throw new RuntimeException($method.' '.$path.' returned '.$entry['status'].'; see private last-failure.json');
    }
    return $body;
}

function dc16Login(string $role): string
{
    global $dc16Root, $dc16;
    $accounts = json_decode(file_get_contents($dc16Root.'/accounts.json'), true);
    $entry = $accounts[$role];
    $body = dc16Request('POST', 'login', ['email' => $entry['email'], 'password' => $entry['password'] ?? $dc16['owner_password']]);
    $accounts[$role]['token'] = $body['access_token'] ?? $body['token'];
    file_put_contents($dc16Root.'/accounts.json', json_encode($accounts, JSON_PRETTY_PRINT));
    return $accounts[$role]['token'];
}

function dc16Clock(string $date): void
{
    $clock = Carbon\CarbonImmutable::parse($date, 'Europe/Tirane');
    Illuminate\Support\Carbon::setTestNow($clock);
    Carbon\CarbonImmutable::setTestNow($clock);
    Illuminate\Support\Facades\Date::setTestNow($clock);
}
