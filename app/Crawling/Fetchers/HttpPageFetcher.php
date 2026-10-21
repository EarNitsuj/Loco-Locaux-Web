<?php

namespace App\Crawling\Fetchers;

use Exception;
use App\Crawling\Data\FetchedDocument;
use App\Crawling\Data\FetchedPage;
use App\Crawling\Exceptions\CrawlerRequestException;
use App\Crawling\Support\SafeUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class HttpPageFetcher
{
    public function __construct(
        private readonly SafeUrl $safeUrl
    ) {}

    /**
     * @param  list<string>  $allowedHosts
     */
    public function get(
        string $url,
        array $allowedHosts = []
    ): FetchedPage {
        $this->safeUrl->assertPublicHttpUrl(
            $url,
            $allowedHosts
        );

        $response = Http::withHeaders([
            'User-Agent' =>
            (string) config('crawling.user_agent'),

            'Accept' =>
            'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',

            'Accept-Language' =>
            (string) config('crawling.accept_language'),
        ])
            ->connectTimeout(
                (int) config(
                    'crawling.connect_timeout_seconds'
                )
            )
            ->timeout(
                (int) config(
                    'crawling.timeout_seconds'
                )
            )
            ->retry(
                (int) config('crawling.retries'),
                fn(int $attempt, Exception $exception): int =>
                $this->retryDelay(
                    $attempt,
                    $exception
                )
            )
            ->get($url);

        return $this->validated(
            $url,
            $response,
            $allowedHosts
        );
    }

    /**
     * @param  list<string>  $allowedHosts
     * @return array<string, mixed>|list<mixed>
     */
    public function getJson(
        string $url,
        array $allowedHosts = []
    ): array {
        $this->safeUrl->assertPublicHttpUrl(
            $url,
            $allowedHosts
        );

        $response = Http::withHeaders([
            'User-Agent' =>
            (string) config('crawling.user_agent'),

            'Accept' =>
            'application/json',

            'Accept-Language' =>
            (string) config('crawling.accept_language'),
        ])
            ->connectTimeout(
                (int) config(
                    'crawling.connect_timeout_seconds'
                )
            )
            ->timeout(
                (int) config(
                    'crawling.timeout_seconds'
                )
            )
            ->retry(
                (int) config('crawling.retries'),
                fn(int $attempt, Exception $exception): int =>
                $this->retryDelay(
                    $attempt,
                    $exception
                )
            )
            ->get($url);

        if (! $response->successful()) {
            throw new CrawlerRequestException(
                "Crawler JSON request failed with HTTP {$response->status()} for {$url}"
            );
        }

        $body = $response->body();

        $maxBytes = (int) config(
            'crawling.max_response_bytes'
        );

        if (strlen($body) > $maxBytes) {
            throw new CrawlerRequestException(
                "Crawler JSON response exceeded {$maxBytes} bytes for {$url}"
            );
        }

        $contentType = strtolower(
            (string) $response->header(
                'Content-Type'
            )
        );

        if (
            ! str_contains(
                $contentType,
                'json'
            )
        ) {
            throw new CrawlerRequestException(
                "Crawler JSON response is not JSON for {$url}"
            );
        }

        $finalUrl =
            $response->handlerStats()['url']
            ?? $url;

        $this->safeUrl->assertPublicHttpUrl(
            $finalUrl,
            $allowedHosts
        );

        try {
            $decoded = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new CrawlerRequestException(
                "Crawler JSON response is invalid for {$url}"
            );
        }

        if (! is_array($decoded)) {
            throw new CrawlerRequestException(
                "Crawler JSON response has an invalid top-level value for {$url}"
            );
        }

        return $decoded;
    }

    /**
     * Fetch a PDF/document without weakening the HTML-only get() method.
     *
     * @param  list<string>  $allowedHosts
     */
    public function getDocument(
        string $url,
        array $allowedHosts = []
    ): FetchedDocument {
        $this->safeUrl->assertPublicHttpUrl(
            $url,
            $allowedHosts
        );

        $response = Http::withHeaders([
            'User-Agent' =>
            (string) config('crawling.user_agent'),

            'Accept' =>
            'application/pdf,application/octet-stream;q=0.9,*/*;q=0.8',

            'Accept-Language' =>
            (string) config('crawling.accept_language'),
        ])
            ->connectTimeout(
                (int) config(
                    'crawling.connect_timeout_seconds'
                )
            )
            ->timeout(
                (int) config(
                    'crawling.timeout_seconds'
                )
            )
            ->retry(
                (int) config('crawling.retries'),
                fn(int $attempt, Exception $exception): int =>
                $this->retryDelay(
                    $attempt,
                    $exception
                )
            )
            ->get($url);

        if (! $response->successful()) {
            throw new CrawlerRequestException(
                "Crawler document request failed with HTTP {$response->status()} for {$url}"
            );
        }

        $body = $response->body();

        /*
        |--------------------------------------------------------------------------
        | Documents may legitimately be larger than normal HTML responses.
        |--------------------------------------------------------------------------
        */

        $maxBytes = (int) config(
            'crawling.max_document_response_bytes',
            config('crawling.max_response_bytes')
        );

        if (strlen($body) > $maxBytes) {
            throw new CrawlerRequestException(
                "Crawler document response exceeded {$maxBytes} bytes for {$url}"
            );
        }

        $finalUrl =
            $response->handlerStats()['url']
            ?? $url;

        /*
        | Validate the redirect destination as well.
        */

        $this->safeUrl->assertPublicHttpUrl(
            $finalUrl,
            $allowedHosts
        );

        $contentType = $response->header(
            'Content-Type'
        );

        $normalizedContentType = strtolower(
            (string) $contentType
        );

        /*
        |--------------------------------------------------------------------------
        | Validate PDF
        |--------------------------------------------------------------------------
        |
        | Municipal websites do not always return application/pdf.
        | Some return application/octet-stream or no useful Content-Type.
        |
        | Therefore also inspect the actual file signature.
        |
        */

        $header = substr(
            $body,
            0,
            1024
        );

        $hasPdfContentType = str_contains(
            $normalizedContentType,
            'application/pdf'
        );

        $hasPdfSignature = str_contains(
            $header,
            '%PDF-'
        );

        if (
            ! $hasPdfContentType
            && ! $hasPdfSignature
        ) {
            throw new CrawlerRequestException(
                "Crawler document response is not a PDF for {$url}"
            );
        }

        return new FetchedDocument(
            requestedUrl: $url,
            finalUrl: $finalUrl,
            body: $body,
            status: $response->status(),
            contentType: $contentType,
        );
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function validated(
        string $url,
        Response $response,
        array $allowedHosts
    ): FetchedPage {
        if (! $response->successful()) {
            throw new CrawlerRequestException(
                "Crawler request failed with HTTP {$response->status()} for {$url}"
            );
        }

        $body = $response->body();

        $maxBytes = (int) config(
            'crawling.max_response_bytes'
        );

        if (strlen($body) > $maxBytes) {
            throw new CrawlerRequestException(
                "Crawler response exceeded {$maxBytes} bytes for {$url}"
            );
        }

        $contentType = $response->header(
            'Content-Type'
        );

        if (
            $contentType !== null
            && ! str_contains(
                strtolower($contentType),
                'html'
            )
        ) {
            throw new CrawlerRequestException(
                "Crawler response is not HTML for {$url}"
            );
        }

        $finalUrl =
            $response->handlerStats()['url']
            ?? $url;

        $this->safeUrl->assertPublicHttpUrl(
            $finalUrl,
            $allowedHosts
        );

        return new FetchedPage(
            $url,
            $finalUrl,
            $body,
            $response->status(),
            $contentType
        );
    }

    private function retryDelay(
        int $attempt,
        Exception $exception
    ): int {
        if (
            $exception instanceof RequestException
            && $exception->response->status() === 429
        ) {
            /*
         * Respect Retry-After when the server provides it.
         */
            $retryAfter =
                $exception->response->header(
                    'Retry-After'
                );

            if (
                is_string($retryAfter)
                && ctype_digit(trim($retryAfter))
            ) {
                return max(
                    1000,
                    (int) trim($retryAfter) * 1000
                );
            }

            /*
         * Otherwise use progressively longer waits for rate limiting.
         *
         * Attempt 1 → 5 seconds
         * Attempt 2 → 10 seconds
         * Attempt 3 → 20 seconds
         * capped at 30 seconds.
         */
            return min(
                30000,
                5000 * (2 ** max(
                    0,
                    $attempt - 1
                ))
            );
        }

        /*
     * Normal failures retain the configured retry delay.
     */
        return (int) config(
            'crawling.retry_delay_ms'
        );
    }
}
