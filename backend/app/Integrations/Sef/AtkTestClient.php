<?php

namespace App\Integrations\Sef;

use App\Models\FiscalConfiguration;
use Illuminate\Support\Facades\Http;

/** Deliberately has no production transport. Tests cannot override a URL or follow redirects. */
class AtkTestClient
{
    public const ENDPOINT = 'https://fiskalizimi-test.atk-ks.org/pos/coupon';

    public function submit(FiscalConfiguration $configuration, array $signed): array
    {
        if ($configuration->environment !== 'test' || ! $configuration->enabled) {
            throw new \LogicException('Only explicitly enabled ATK TEST submissions are supported.');
        }
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false, 'verify' => true])->post(self::ENDPOINT, ['details' => $signed['details'], 'signature' => $signed['signature']]);
        } catch (\Throwable $error) {
            // An exception cannot prove non-delivery. Do not persist exception text or credentials.
            return ['state' => 'unknown', 'error_code' => 'transport_outcome_unknown', 'message' => 'Delivery outcome is unknown. Do not resend; obtain ATK confirmation.', 'evidence' => ['transport_error_class' => get_class($error)], 'http_status' => null];
        }
        $body = $response->body();
        $decoded = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        $evidence = ['body' => substr($body, 0, 65536), 'body_sha256' => hash('sha256', $body), 'truncated' => strlen($body) > 65536, 'headers' => array_intersect_key($response->headers(), array_flip(['Content-Type', 'Date', 'X-Request-ID']))];
        if ($response->status() === 200 && is_array($decoded) && isset($decoded['transaction_id']) && (is_int($decoded['transaction_id']) || is_string($decoded['transaction_id'])) && preg_match('/^[1-9][0-9]{0,19}$/', (string) $decoded['transaction_id']) && (strlen((string) $decoded['transaction_id']) < 20 || strcmp((string) $decoded['transaction_id'], '18446744073709551615') <= 0)) {
            return ['state' => 'fiscalized', 'provider_transaction_id' => (string) $decoded['transaction_id'], 'evidence' => $evidence, 'http_status' => 200, 'error_code' => null, 'message' => 'ATK TEST returned a transaction identifier.'];
        }
        // Only the documented explicit 400 rejection proves the coupon was rejected.
        if ($response->status() === 400 && is_array($decoded) && is_string($decoded['error'] ?? null)) {
            return ['state' => 'failed', 'evidence' => $evidence, 'http_status' => 400, 'error_code' => 'atk_rejected', 'message' => 'ATK TEST rejected the request. Review its protected evidence and correct configuration before an explicit retry.'];
        }

        return ['state' => 'unknown', 'evidence' => $evidence, 'http_status' => $response->status(), 'error_code' => 'unconfirmed_response', 'message' => 'ATK TEST did not return a confirmed receipt or documented rejection. Do not resend automatically.'];
    }
}
