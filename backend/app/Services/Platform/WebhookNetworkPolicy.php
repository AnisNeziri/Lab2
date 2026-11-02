<?php

namespace App\Services\Platform;

class WebhookNetworkPolicy
{
    public function addresses(string $url): array
    {
        $u = parse_url($url);
        if (($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || isset($u['query']) || ! isset($u['host'])) {
            throw new \RuntimeException('HTTPS endpoint required.');
        }
        $host = trim($u['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(array_column(dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (! $ips) {
            throw new \RuntimeException('Endpoint DNS unavailable.');
        }foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Endpoint must use public addresses.');
            }
        }

return $ips;
    }
}
