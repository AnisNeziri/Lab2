<?php
// Isolated browser-test router; never replaces the active backend entry point.
require __DIR__.'/bootstrap.php';
if(PHP_SAPI!=='cli-server' || (int)($_SERVER['SERVER_PORT']??0)!==8026)throw new RuntimeException('DC16 HTTP router requires loopback test port 8026');
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$request=Illuminate\Http\Request::capture();$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
