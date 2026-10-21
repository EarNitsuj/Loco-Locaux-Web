<?php

namespace App\Crawling\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class UrlNormalizer
{
    public function absolute(string $url, string $baseUrl): string
    {
        $reference = new Uri(trim($url));
        $base = new Uri(trim($baseUrl));

        return $this->withoutFragment((string) UriResolver::resolve($base, $reference));
    }

    public function withoutFragment(string $url): string
    {
        return preg_replace('/#.*$/', '', trim($url)) ?: trim($url);
    }

    public function canonical(string $url): string
    {
        $url = $this->withoutFragment($url);
        $parts = parse_url($url);

        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return "{$scheme}://{$host}{$path}{$query}";
    }
}
