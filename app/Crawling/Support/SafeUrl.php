<?php

namespace App\Crawling\Support;

use App\Crawling\Exceptions\UnsafeUrlException;

class SafeUrl
{
    /**
     * @param  list<string>  $allowedHosts
     */
    public function assertPublicHttpUrl(string $url, array $allowedHosts = []): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new UnsafeUrlException("Only public HTTP(S) URLs are allowed: {$url}");
        }

        if ($allowedHosts !== [] && ! $this->hostAllowed($host, $allowedHosts)) {
            throw new UnsafeUrlException("URL host is outside the configured official domains: {$host}");
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new UnsafeUrlException("Local hostnames are not allowed: {$host}");
        }

        $this->assertIpIsPublic($host);

        foreach ($this->resolveHost($host) as $ip) {
            $this->assertIpIsPublic($ip);
        }
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower($allowedHost);

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function assertIpIsPublic(string $hostOrIp): void
    {
        if (! filter_var($hostOrIp, FILTER_VALIDATE_IP)) {
            return;
        }

        $isPublic = filter_var(
            $hostOrIp,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($isPublic === false) {
            throw new UnsafeUrlException("Private or reserved network address is not allowed: {$hostOrIp}");
        }
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = gethostbynamel($host);

        return $records === false ? [] : $records;
    }
}
