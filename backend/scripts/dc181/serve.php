<?php
require __DIR__.'/bootstrap.php';
if(PHP_SAPI!=='cli-server'||(int)($_SERVER['SERVER_PORT']??0)!==8028)throw new RuntimeException('DC18.1 test router requires isolated loopback port 8028');
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$request=Illuminate\Http\Request::capture();$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
