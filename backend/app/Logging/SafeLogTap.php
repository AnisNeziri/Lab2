<?php

namespace App\Logging;

use Monolog\LogRecord;

final class SafeLogTap
{
    public function __invoke($logger): void
    {
        $secrets = array_filter(array_map(fn ($key) => config($key), ['app.key', 'jwt.secret', 'database.connections.mysql.password', 'database.redis.default.password', 'mail.mailers.smtp.password', 'services.stripe.secret', 'tracking.aisstream.api_key', 'tracking.vessel_lookup.api_key']), fn ($value) => is_string($value) && strlen($value) >= 8);
        $text = fn (string $value) => preg_replace('/(password|passphrase|authorization|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', str_replace($secrets, '[redacted]', $value));
        $sanitize = function ($value) use (&$sanitize, $text) {
            if ($value instanceof \Throwable) return ['type' => class_basename($value), 'code' => $value->getCode()];
            if (is_object($value)) return class_basename($value);
            if (is_array($value)) {
                $safe = [];
                foreach ($value as $key => $entry) $safe[$key] = preg_match('/password|passphrase|token|secret|authorization|credential|api[_-]?key|payload|binding|sql|document_content/i', (string) $key) ? '[redacted]' : $sanitize($entry);
                return $safe;
            }
            return is_string($value) ? $text($value) : $value;
        };
        $logger->pushProcessor(fn (LogRecord $record) => $record->with(message: $text($record->message), context: $sanitize($record->context), extra: $sanitize($record->extra)));
    }
}
