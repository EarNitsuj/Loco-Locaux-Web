<?php

namespace App\Crawling\Data;

final readonly class CrawlOptions
{
    /**
     * @param  list<string>|null  $communeSlugs
     */
    public function __construct(
        public ?array $communeSlugs = null,
        public ?string $sourceAdapter = null,
        public ?int $eventLimit = null,
        public bool $all = false,
        public bool $force = false,
        public bool $withoutEnrichment = false,
        public bool $scheduled = false,
        public int $maxListingPages = 4,
        public int $maxEmptyPages = 1,
        public int $maxDetailRequests = 40,
        public int $maxDurationSeconds = 120,
        public int $requestDelayMs = 500,
    ) {}

    public static function fromConfig(
        ?array $communeSlugs = null,
        ?int $eventLimit = null,
        bool $all = false,
        bool $force = false,
        bool $withoutEnrichment = false,
        ?string $sourceAdapter = null,
        bool $scheduled = false,
    ): self {
        return new self(
            communeSlugs: $communeSlugs,

            sourceAdapter: (
                is_string($sourceAdapter)
                && trim($sourceAdapter) !== ''
            )
                ? trim($sourceAdapter)
                : null,

            eventLimit: $all
                ? null
                : (
                    $eventLimit
                    ?? (int) config(
                        'crawling.default_event_limit'
                    )
                ),

            all: $all,

            force: $force,

            withoutEnrichment: $withoutEnrichment,

            scheduled: $scheduled,

            maxListingPages: (int) config(
                'crawling.max_listing_pages'
            ),

            maxEmptyPages: (int) config(
                'crawling.max_empty_pages'
            ),

            maxDetailRequests: (int) config(
                'crawling.max_detail_requests'
            ),

            maxDurationSeconds: (int) config(
                'crawling.max_duration_seconds'
            ),

            requestDelayMs: (int) config(
                'crawling.request_delay_ms'
            ),
        );
    }

    /**
     * The caller intends this to be an exhaustive source crawl.
     *
     * This does NOT mean it actually completed exhaustively.
     * CrawlCommune must later prove snapshot_complete=true.
     */
    public function requestsCompleteSnapshot(): bool
    {
        return $this->all
            && $this->eventLimit === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commune_slugs' =>
            $this->communeSlugs,

            'source_adapter' =>
            $this->sourceAdapter,

            'event_limit' =>
            $this->eventLimit,

            'all' =>
            $this->all,

            'force' =>
            $this->force,

            'without_enrichment' =>
            $this->withoutEnrichment,

            'scheduled' =>
            $this->scheduled,

            'max_listing_pages' =>
            $this->maxListingPages,

            'max_empty_pages' =>
            $this->maxEmptyPages,

            'max_detail_requests' =>
            $this->maxDetailRequests,

            'max_duration_seconds' =>
            $this->maxDurationSeconds,

            'request_delay_ms' =>
            $this->requestDelayMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(
        array $payload
    ): self {
        $sourceAdapter =
            $payload['source_adapter']
            ?? null;

        return new self(
            communeSlugs: is_array(
                $payload['commune_slugs']
                    ?? null
            )
                ? array_values(
                    $payload['commune_slugs']
                )
                : null,

            sourceAdapter: is_string($sourceAdapter)
                && trim($sourceAdapter) !== ''
                ? trim($sourceAdapter)
                : null,

            eventLimit: isset(
                $payload['event_limit']
            )
                ? (int) $payload['event_limit']
                : null,

            all: (bool) (
                $payload['all']
                ?? false
            ),

            force: (bool) (
                $payload['force']
                ?? false
            ),

            withoutEnrichment: (bool) (
                $payload['without_enrichment']
                ?? false
            ),

            scheduled: (bool) (
                $payload['scheduled']
                ?? false
            ),

            maxListingPages: (int) (
                $payload['max_listing_pages']
                ?? config(
                    'crawling.max_listing_pages'
                )
            ),

            maxEmptyPages: (int) (
                $payload['max_empty_pages']
                ?? config(
                    'crawling.max_empty_pages'
                )
            ),

            maxDetailRequests: (int) (
                $payload['max_detail_requests']
                ?? config(
                    'crawling.max_detail_requests'
                )
            ),

            maxDurationSeconds: (int) (
                $payload['max_duration_seconds']
                ?? config(
                    'crawling.max_duration_seconds'
                )
            ),

            requestDelayMs: (int) (
                $payload['request_delay_ms']
                ?? config(
                    'crawling.request_delay_ms'
                )
            ),
        );
    }
}
