<?php

namespace App\Crawling\Services;

use App\Crawling\Data\ImageResolutionResult;
use App\Crawling\Exceptions\UnsafeUrlException;
use App\Crawling\Support\SafeUrl;
use App\Crawling\Support\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImageResolver
{
    public function __construct(
        private readonly UrlNormalizer $urls,
        private readonly SafeUrl $safeUrl,
    ) {}

    /**
     * @param  list<string>  $allowedHosts
     */
    public function resolve(
        ?string $rawImageUrl,
        ?string $eventDetailUrl,
        ?string $sourceBaseUrl,
        array $allowedHosts = [],
    ): ImageResolutionResult {
        if ($rawImageUrl === null || trim($rawImageUrl) === '') {
            return new ImageResolutionResult(null, false, 'missing_url');
        }

        $baseUrl = $this->usableBase($eventDetailUrl) ?? $this->usableBase($sourceBaseUrl);

        try {
            $resolved = $this->resolveUrl(trim($rawImageUrl), $baseUrl);
        } catch (Throwable) {
            return new ImageResolutionResult(null, false, 'invalid_url');
        }

        $hosts = $this->allowedHosts($allowedHosts, $eventDetailUrl, $sourceBaseUrl, $resolved);
        $key = 'image-validation:'.hash('sha256', $resolved.'|'.implode(',', $hosts));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return ImageResolutionResult::fromArray($cached);
        }

        $result = $this->validate($resolved, $hosts);
        Cache::put($key, $result->toArray(), now()->addHours((int) config('crawling.image_validation_cache_hours', 12)));

        Log::info($result->valid ? 'Image URL resolved.' : 'Image rejected.', [
            'operation' => 'image_validation',
            'reason' => $result->reason,
            'host' => parse_url($resolved, PHP_URL_HOST),
        ]);

        return $result;
    }

    /** @param list<string> $allowedHosts */
    private function validate(string $url, array $allowedHosts): ImageResolutionResult
    {
        try {
            $this->safeUrl->assertPublicHttpUrl($url, $allowedHosts);
        } catch (UnsafeUrlException) {
            return new ImageResolutionResult($url, false, 'unsafe_url');
        }

        $filename = mb_strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));

        if (preg_match('/(?:^|[-_.])(logo|favicon|placeholder|default|fallback|spacer|pixel|tracking)(?:$|[-_.])/u', $filename) === 1) {
            return new ImageResolutionResult($url, false, 'generic_or_tracking_asset');
        }

        try {
            [$head, $headUrl] = $this->requestFollowingRedirects('HEAD', $url, $allowedHosts);
            $contentType = strtolower(trim(explode(';', (string) $head->header('Content-Type'))[0]));
            $inconclusive = in_array($head->status(), [400, 403, 405, 501], true)
                || ! $head->successful()
                || ! str_starts_with($contentType, 'image/');

            if (! $inconclusive) {
                return new ImageResolutionResult($url, true, 'validated_head', $headUrl, $contentType);
            }

            [$get, $getUrl] = $this->requestFollowingRedirects('GET', $url, $allowedHosts);

            if (! $get->successful()) {
                return new ImageResolutionResult($url, false, 'http_'.$get->status(), $getUrl);
            }

            $contentType = strtolower(trim(explode(';', (string) $get->header('Content-Type'))[0]));
            $body = $get->body();

            if (! str_starts_with($contentType, 'image/')) {
                return new ImageResolutionResult($url, false, 'not_image_content_type', $getUrl, $contentType ?: null);
            }

            if (strlen($body) > (int) config('crawling.image_max_response_bytes', 1048576)) {
                return new ImageResolutionResult($url, false, 'response_too_large', $getUrl, $contentType);
            }

            if (preg_match('/^\s*(?:<!doctype\s+html|<html|<body|<script)/i', $body) === 1) {
                return new ImageResolutionResult($url, false, 'html_response', $getUrl, $contentType);
            }

            return new ImageResolutionResult($url, true, 'validated_get', $getUrl, $contentType);
        } catch (UnsafeUrlException) {
            return new ImageResolutionResult($url, false, 'redirect_host_not_allowed');
        } catch (ConnectionException) {
            return new ImageResolutionResult($url, false, 'connection_failed');
        } catch (Throwable) {
            return new ImageResolutionResult($url, false, 'validation_failed');
        }
    }

    /**
     * @param  list<string>  $allowedHosts
     * @return array{Response, string}
     */
    private function requestFollowingRedirects(string $method, string $url, array $allowedHosts): array
    {
        $current = $url;

        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $this->safeUrl->assertPublicHttpUrl($current, $allowedHosts);
            $request = Http::withHeaders([
                'User-Agent' => (string) config('crawling.user_agent'),
                'Accept' => 'image/*,*/*;q=0.5',
                ...$method === 'GET' ? ['Range' => 'bytes=0-65535'] : [],
            ])->withOptions(['allow_redirects' => false])
                ->connectTimeout((int) config('crawling.image_connect_timeout_seconds', 4))
                ->timeout((int) config('crawling.image_timeout_seconds'));
            $response = $method === 'HEAD' ? $request->head($current) : $request->get($current);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return [$response, $current];
            }

            $location = trim((string) $response->header('Location'));

            if ($location === '') {
                return [$response, $current];
            }

            $current = $this->urls->absolute($location, $current);
        }

        throw new UnsafeUrlException('Too many image redirects.');
    }

    private function resolveUrl(string $raw, ?string $baseUrl): string
    {
        $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));

        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Unsupported image URL scheme.');
        }

        if ($scheme !== '') {
            return $this->urls->withoutFragment($raw);
        }

        if ($baseUrl === null) {
            throw new UnsafeUrlException('Relative image URL has no usable base URL.');
        }

        return $this->urls->absolute($raw, $baseUrl);
    }

    private function usableBase(?string $url): ?string
    {
        return is_string($url) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : null;
    }

    /**
     * @param  list<string>  $configured
     * @return list<string>
     */
    private function allowedHosts(array $configured, ?string ...$urls): array
    {
        $baseHosts = array_map(fn (?string $url): ?string => $url === null ? null : parse_url($url, PHP_URL_HOST), array_slice($urls, 0, 2));
        $resolvedHost = isset($urls[2]) && $urls[2] !== null ? parse_url($urls[2], PHP_URL_HOST) : null;
        $candidates = $configured === [] ? [...$baseHosts, $resolvedHost] : [...$configured, ...$baseHosts];

        return collect($candidates)
            ->filter(fn (mixed $host): bool => is_string($host) && $host !== '')
            ->map(fn (string $host): string => strtolower($host))
            ->unique()
            ->values()
            ->all();
    }
}
