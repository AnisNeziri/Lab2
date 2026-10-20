<?php

function performancePort(): string
{
    $value = getenv('AIMS_PERF_MYSQL_PORT') ?: '3306';
    $port = filter_var($value, FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException('A bounded loopback MariaDB port is required.');
    }

    return (string) $port;
}
function performanceDirectory(string $path): string
{
    $root = realpath(dirname(__DIR__, 2));
    $lexical = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $expected = $root.DIRECTORY_SEPARATOR.'output';
    if (! str_starts_with(strtolower($lexical.DIRECTORY_SEPARATOR), strtolower($expected.DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Performance files must be inside this checkout output.');
    }
    if (is_link($path)) {
        throw new RuntimeException('Linked performance directory refused.');
    }
    if (! is_dir($path) && ! mkdir($path, 0700, true)) {
        throw new RuntimeException('Cannot create disposable performance directory.');
    }
    $resolved = realpath($path);
    if (! $resolved || strtolower($resolved) !== strtolower(rtrim($lexical, DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Redirected performance directory refused.');
    }

    return $resolved;
}
function performanceErrors(): void
{
    set_exception_handler(function (Throwable $error): void {
        $detail=$error instanceof Illuminate\Contracts\Container\BindingResolutionException ? ' '.substr($error->getMessage(),0,300) : '';
        fwrite(STDERR, 'Performance check failed: '.get_class($error).$detail.' (code '.(int) $error->getCode().'). Review the bounded application diagnostics.'.PHP_EOL);
        exit(1);
    });
}
