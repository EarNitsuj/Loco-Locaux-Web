<?php

namespace App\Crawling\Adapters;

use App\Crawling\Assets\AssetRole;
use App\Crawling\Assets\ClassifiedAsset;
use App\Crawling\Assets\EventAssetExtractor;
use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\ParsedOccurrence;
use App\Crawling\Data\RawEvent;
use App\Crawling\Documents\PdfEvidenceExtractor;
use App\Crawling\Fetchers\HttpPageFetcher;
use App\Crawling\Fetchers\ImageValidator;
use App\Crawling\Support\SafeUrl;
use App\Crawling\Support\UrlNormalizer;
use App\Models\EventSource;
use Carbon\CarbonImmutable;
use DOMElement;
use DOMNode;
use Symfony\Component\DomCrawler\Crawler;

class WordPressTribeEventsCrawler implements CommuneCrawler
{
    public function __construct(
        private readonly HttpPageFetcher $fetcher,
        private readonly ImageValidator $images,
        private readonly SafeUrl $safeUrl,
        private readonly UrlNormalizer $urls,
        private readonly EventAssetExtractor $assets,
        private readonly PdfEvidenceExtractor $pdfs,
    ) {}

    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $configuration = is_array($source->configuration)
            ? $source->configuration
            : [];

        $url = (string) (
            $configuration['api_url']
            ?? $source->listing_url
        );

        $allowedHosts = $this->allowedHosts($source);

        $seenPages = [];
        $seenIds = [];
        $references = [];

        for (
            $page = 0;
            $page < $options->maxListingPages && $url !== '';
            $page++
        ) {
            $url = $this->urls->canonical($url);

            if (isset($seenPages[$url])) {
                break;
            }

            $seenPages[$url] = true;

            $payload = $this->fetcher->getJson(
                $url,
                $allowedHosts
            );

            $events = is_array($payload['events'] ?? null)
                ? $payload['events']
                : [];

            foreach ($events as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $id = $record['id'] ?? null;
                $eventUrl = $record['url'] ?? null;

                if (
                    (! is_int($id) && ! ctype_digit((string) $id))
                    || ! is_string($eventUrl)
                    || trim($eventUrl) === ''
                ) {
                    continue;
                }

                $this->safeUrl->assertPublicHttpUrl(
                    $eventUrl,
                    $allowedHosts
                );

                if (isset($seenIds[(string) $id])) {
                    continue;
                }

                $seenIds[(string) $id] = true;

                $references[] = new EventReference(
                    url: $this->urls->canonical($eventUrl),
                    externalId: (string) $id,
                    listingUrl: $url,
                    titleHint: $this->text(
                        $record['title'] ?? null
                    ),
                    metadata: [
                        'record' => $record,
                    ],
                );

                if (
                    ! $options->all
                    && $options->eventLimit !== null
                    && count($references) >= $options->eventLimit
                ) {
                    return $references;
                }
            }

            $next = $payload['next_rest_url'] ?? null;

            $url = is_string($next)
                ? $next
                : '';
        }

        return $references;
    }

    public function fetch(
        EventSource $source,
        EventReference $reference
    ): RawEvent {
        $configuration = is_array($source->configuration)
            ? $source->configuration
            : [];

        $allowedHosts = $this->allowedHosts($source);

        /*
        |--------------------------------------------------------------------------
        | Tribe API record
        |--------------------------------------------------------------------------
        */

        $record = $reference->metadata['record'] ?? null;

        if (! is_array($record)) {
            $record = $this->fetcher->getJson(
                rtrim(
                    (string) (
                        $configuration['api_url']
                        ?? $source->listing_url
                    ),
                    '/'
                )
                    . '/'
                    . urlencode((string) $reference->externalId),
                $allowedHosts,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Fetch rendered public event page
        |--------------------------------------------------------------------------
        */

        $detailPage = $this->fetcher->get(
            $reference->url,
            $allowedHosts
        );

        $pageCrawler = new Crawler(
            $detailPage->body,
            $detailPage->finalUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Find event-specific container
        |--------------------------------------------------------------------------
        */

        $eventCrawler = $this->eventCrawler(
            $pageCrawler,
            $configuration
        );

        /*
        |--------------------------------------------------------------------------
        | Clean source UI from event evidence
        |--------------------------------------------------------------------------
        |
        | Tribe pages can contain previous/next event navigation,
        | calendar subscription controls and other source UI inside the
        | otherwise useful event container.
        |--------------------------------------------------------------------------
        */

        $cleanEventCrawler = $eventCrawler !== null
            ? $this->cleanEventCrawler($eventCrawler)
            : null;

        $detailHtml = $cleanEventCrawler !== null
            ? $this->outerHtml($cleanEventCrawler)
            : '';

        /*
        |--------------------------------------------------------------------------
        | Structured fields
        |--------------------------------------------------------------------------
        */

        $timezone = is_string($record['timezone'] ?? null)
            && in_array(
                $record['timezone'],
                timezone_identifiers_list(),
                true
            )
            ? $record['timezone']
            : 'Europe/Zurich';

        $startsAt = $this->date(
            $record['start_date'] ?? null,
            $timezone
        );

        $endsAt = $this->date(
            $record['end_date'] ?? null,
            $timezone
        );

        if (
            $startsAt !== null
            && $endsAt?->lessThan($startsAt)
        ) {
            $endsAt = null;
        }

        $title = $this->text(
            $record['title'] ?? null
        ) ?? $reference->titleHint;

        /*
        |--------------------------------------------------------------------------
        | Description
        |--------------------------------------------------------------------------
        |
        | Standard Tribe:
        | .tribe-events-single-event-description
        |
        | Chêne-Bougeries:
        | .et_pb_post_content
        |--------------------------------------------------------------------------
        */

        $descriptionSelectors = [
            '.tribe-events-single-event-description',
            '.et_pb_post_content',
            '[class*="et_pb_post_content"]',
        ];

        $renderedDescriptionHtml = $cleanEventCrawler !== null
            ? $this->firstInnerHtml(
                $cleanEventCrawler,
                $descriptionSelectors
            )
            : null;

        $renderedDescription = $cleanEventCrawler !== null
            ? $this->firstCrawlerText(
                $cleanEventCrawler,
                $descriptionSelectors
            )
            : null;

        $apiDescriptionHtml = is_string(
            $record['description'] ?? null
        )
            ? trim($record['description'])
            : '';

        $description = $renderedDescription
            ?? $this->plainText(
                $record['description']
                    ?? $record['excerpt']
                    ?? null
            );

        /*
        |--------------------------------------------------------------------------
        | Shared classified image evidence
        |--------------------------------------------------------------------------
        */

        $assetEvidence = [];

        /*
        |--------------------------------------------------------------------------
        | Deterministic Tribe featured image
        |--------------------------------------------------------------------------
        |
        | When Tribe explicitly provides record.image.url, retain that as
        | deterministic event media.
        |
        | We intentionally do not override this classification because of a
        | filename such as "Logo...". The source explicitly selected it as the
        | featured image, so that fact remains useful evidence.
        |--------------------------------------------------------------------------
        */

        if (is_array($record['image'] ?? null)) {
            $apiImage = $record['image']['url'] ?? null;

            if (
                is_string($apiImage)
                && trim($apiImage) !== ''
            ) {
                $apiImage = $this->absoluteUrl(
                    trim($apiImage),
                    $detailPage->finalUrl
                );

                if ($apiImage !== null) {
                    $assetEvidence[] = [
                        'asset' => new ClassifiedAsset(
                            url: $apiImage,
                            role: AssetRole::EventMedia,
                            semanticKey: 'featured_image',
                            label: $title,
                            reason: 'tribe_featured_image',
                        ),
                        'origin' => 'tribe-featured-image',
                    ];
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Rendered event assets outside the description
        |--------------------------------------------------------------------------
        |
        | This intentionally removes the event-description subtree before
        | classification.
        |
        | Otherwise an image inside a short/image-only description can inherit
        | unrelated context from nearby organizer or venue metadata in the
        | wider Tribe event container.
        |--------------------------------------------------------------------------
        */

        if ($cleanEventCrawler !== null) {
            $assetEvidence = array_merge(
                $assetEvidence,
                $this->eventAssetsOutsideDescription(
                    $cleanEventCrawler,
                    $detailPage->finalUrl,
                    $descriptionSelectors
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Rendered event-description assets
        |--------------------------------------------------------------------------
        |
        | Classify the description independently so organizer, venue or
        | practical-info text outside the description cannot influence it.
        |--------------------------------------------------------------------------
        */

        $assetEvidence = array_merge(
            $assetEvidence,
            $this->descriptionAssetEvidence(
                $renderedDescriptionHtml,
                $detailPage->finalUrl,
                'event-description'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Supplementary API-description assets
        |--------------------------------------------------------------------------
        |
        | The API description is also isolated by definition, making it safe
        | supplementary image evidence when the rendered page differs.
        |--------------------------------------------------------------------------
        */

        $assetEvidence = array_merge(
            $assetEvidence,
            $this->descriptionAssetEvidence(
                $apiDescriptionHtml !== ''
                    ? $apiDescriptionHtml
                    : null,
                $detailPage->finalUrl,
                'tribe-description'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Collapse responsive WordPress renditions
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | salle-cm-300x225.jpg
        | salle-cm-1024x768.jpg
        | salle-cm-2048x1536.jpg
        |
        | The complete srcset remains in the original event evidence while
        | explicit image evidence keeps one logical representative.
        |--------------------------------------------------------------------------
        */

        $assetEvidence = $this->collapseAssetEvidence(
            $assetEvidence
        );

        /*
        |--------------------------------------------------------------------------
        | Hero-eligible candidates
        |--------------------------------------------------------------------------
        |
        | Only event_media and unknown compete for RawEvent::imageUrl.
        |--------------------------------------------------------------------------
        */

        $rawImageCandidates = [];

        foreach ($assetEvidence as $entry) {
            $asset = $entry['asset'] ?? null;

            if (! $asset instanceof ClassifiedAsset) {
                continue;
            }

            if (
                ! in_array(
                    $asset->role,
                    [
                        AssetRole::EventMedia,
                        AssetRole::Unknown,
                    ],
                    true
                )
            ) {
                continue;
            }

            $rawImageCandidates[] = [
                'url' => $asset->url,
                'origin' => (string) (
                    $entry['origin']
                    ?? 'unknown'
                ),
                'role' => $asset->role->value,
                'reason' => $asset->reason,
                'semantic_key' => $asset->semanticKey,
                'label' => $asset->label,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Convenience image URL
        |--------------------------------------------------------------------------
        |
        | ImageValidator never determines whether image evidence survives.
        |--------------------------------------------------------------------------
        */

        $image = null;

        foreach ($rawImageCandidates as $candidate) {
            try {
                $this->safeUrl->assertPublicHttpUrl(
                    $candidate['url'],
                    $allowedHosts
                );

                $validated = $this->images->validate(
                    $candidate['url']
                );

                if ($validated === null) {
                    continue;
                }

                $image = $validated;

                break;
            } catch (\Throwable) {
                continue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Documents
        |--------------------------------------------------------------------------
        */

        $documents = [];

        if ($cleanEventCrawler !== null) {
            $documents = array_merge(
                $documents,
                $this->documentsFromCrawler(
                    $cleanEventCrawler,
                    $detailPage->finalUrl,
                    'event-detail-page'
                )
            );
        }

        /*
        | API description may contain a document missing from the rendered page.
        */

        if ($apiDescriptionHtml !== '') {
            try {
                $descriptionCrawler = new Crawler(
                    $apiDescriptionHtml,
                    $detailPage->finalUrl
                );

                $documents = array_merge(
                    $documents,
                    $this->documentsFromCrawler(
                        $descriptionCrawler,
                        $detailPage->finalUrl,
                        'tribe-description'
                    )
                );
            } catch (\Throwable) {
                //
            }
        }

        $documents = $this->deduplicateDocuments(
            $documents
        );

        /*
        |--------------------------------------------------------------------------
        | PDF / linked-document extraction
        |--------------------------------------------------------------------------
        |
        | Extraction is best effort.
        |
        | An image-only/scanned PDF may legitimately produce no extracted text.
        | Its original link remains preserved as document evidence.
        |--------------------------------------------------------------------------
        */

        $documentEvidence = [];
        $documentExtractionErrors = [];

        foreach ($documents as $document) {
            try {
                $documentHost = parse_url(
                    $document['url'],
                    PHP_URL_HOST
                );

                $documentAllowedHosts = $allowedHosts;

                if (
                    is_string($documentHost)
                    && $documentHost !== ''
                ) {
                    $documentAllowedHosts = array_values(
                        array_unique([
                            ...$documentAllowedHosts,
                            $documentHost,
                        ])
                    );
                }

                $evidence = $this->pdfs->extract(
                    $document['url'],
                    $documentAllowedHosts
                );

                $documentEvidence[] = [
                    'url' =>
                    $evidence['url'],

                    'requested_url' =>
                    $evidence['requested_url'],

                    'label' =>
                    $document['label'],

                    'text' =>
                    $evidence['text'],

                    'sha256' =>
                    $evidence['sha256'],

                    'content_type' =>
                    $evidence['content_type'],

                    'origin' =>
                    $document['origin']
                        ?? null,
                ];
            } catch (\Throwable $e) {
                $documentExtractionErrors[] = [
                    'url' =>
                    $document['url'],

                    'message' =>
                    $e->getMessage(),
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Occurrences / recurrence
        |--------------------------------------------------------------------------
        */

        $occurrences = $startsAt === null
            ? []
            : [
                new ParsedOccurrence(
                    $startsAt,
                    $endsAt
                ),
            ];

        $isRecurring = is_array(
            $record['global_id_lineage'] ?? null
        )
            && count(
                $record['global_id_lineage']
            ) > 1;

        /*
        |--------------------------------------------------------------------------
        | Structured API evidence
        |--------------------------------------------------------------------------
        */

        $recordJson = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
        );

        /*
        |--------------------------------------------------------------------------
        | Build raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml = '<article'
            . ' data-crawl-adapter="wordpress-tribe-events"'
            . ' data-start="' . e(
                $startsAt?->toIso8601String() ?? ''
            ) . '"'
            . ' data-end="' . e(
                $endsAt?->toIso8601String() ?? ''
            ) . '"'
            . '>';

        /*
        | Primary evidence:
        | cleaned rendered event page.
        */

        if ($detailHtml !== '') {
            $rawHtml .=
                '<section data-crawl-source="event-detail-page">'
                . $detailHtml
                . '</section>';
        } else {
            /*
            | API fallback only when the event-specific rendered container
            | cannot be found.
            */

            $rawHtml .=
                '<section data-crawl-source="api-fallback">'
                . '<h1>'
                . e($title ?? 'Official event')
                . '</h1>';

            if ($apiDescriptionHtml !== '') {
                $rawHtml .= $apiDescriptionHtml;
            }

            $rawHtml .= '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Explicit classified image evidence
        |--------------------------------------------------------------------------
        */

        if ($assetEvidence !== []) {
            $rawHtml .=
                '<section data-crawl-source="image-evidence">';

            foreach ($assetEvidence as $entry) {
                $asset = $entry['asset'] ?? null;

                if (! $asset instanceof ClassifiedAsset) {
                    continue;
                }

                $rawHtml .= '<img'
                    . ' src="' . e($asset->url) . '"'
                    . ' alt="' . e(
                        $asset->label
                            ?? $title
                            ?? ''
                    ) . '"'
                    . ' data-crawl-role="' . e(
                        $this->crawlAssetRole($asset->role)
                    ) . '"'
                    . ' data-crawl-asset-role="' . e(
                        $asset->role->value
                    ) . '"'
                    . ' data-crawl-origin="' . e(
                        (string) (
                            $entry['origin']
                            ?? 'unknown'
                        )
                    ) . '"';

                if (
                    is_string($asset->reason)
                    && $asset->reason !== ''
                ) {
                    $rawHtml .=
                        ' data-crawl-classification-reason="'
                        . e($asset->reason)
                        . '"';
                }

                if (
                    is_string($asset->semanticKey)
                    && $asset->semanticKey !== ''
                ) {
                    $rawHtml .=
                        ' data-crawl-semantic-key="'
                        . e($asset->semanticKey)
                        . '"';
                }

                if (
                    is_string($asset->label)
                    && $asset->label !== ''
                ) {
                    $rawHtml .=
                        ' data-crawl-label="'
                        . e($asset->label)
                        . '"';
                }

                $rawHtml .= '>';
            }

            $rawHtml .= '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Document candidates
        |--------------------------------------------------------------------------
        */

        if ($documents !== []) {
            $rawHtml .=
                '<section data-crawl-source="document-candidates">';

            foreach ($documents as $document) {
                $rawHtml .= '<a'
                    . ' href="' . e($document['url']) . '"'
                    . ' data-crawl-role="event-document"'
                    . ' data-crawl-document-type="' . e(
                        $document['type']
                            ?? 'document'
                    ) . '"'
                    . ' data-crawl-origin="' . e(
                        $document['origin']
                            ?? 'unknown'
                    ) . '"'
                    . '>'
                    . e(
                        $document['label']
                            ?: 'Document'
                    )
                    . '</a>';
            }

            $rawHtml .= '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Extracted linked-document evidence
        |--------------------------------------------------------------------------
        */

        foreach ($documentEvidence as $evidence) {
            $rawHtml .=
                '<section'
                . ' data-crawl-source="linked-document"'
                . ' data-document-type="pdf"'
                . ' data-document-url="' . e(
                    $evidence['url']
                ) . '"'
                . ' data-document-sha256="' . e(
                    $evidence['sha256']
                ) . '"'
                . '>';

            if (
                is_string($evidence['label'])
                && trim($evidence['label']) !== ''
            ) {
                $rawHtml .=
                    '<h3 data-crawl-role="document-label">'
                    . e($evidence['label'])
                    . '</h3>';
            }

            $rawHtml .=
                '<pre data-crawl-role="document-text">'
                . e($evidence['text'])
                . '</pre>'
                . '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Complete Tribe structured record
        |--------------------------------------------------------------------------
        */

        if (is_string($recordJson)) {
            $rawHtml .=
                '<section data-crawl-source="structured-data">'
                . '<script type="application/json">'
                . $recordJson
                . '</script>'
                . '</section>';
        }

        $rawHtml .= '</article>';

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        */

        return new RawEvent(
            canonicalUrl: $this->urls->canonical(
                $detailPage->finalUrl
            ),

            rawHtml: $rawHtml,

            externalId: $reference->externalId,

            title: $title,

            startsAt: $startsAt,

            endsAt: $endsAt,

            isRecurring: $isRecurring,

            recurrenceSummary: $isRecurring
                ? 'Occurrence publiée par la source'
                : null,

            description: $description,

            imageUrl: is_string($image)
                ? $image
                : null,

            sourceLanguage: (string) (
                $configuration['source_language']
                ?? 'fr'
            ),

            sourcePublishedAt: $this->date(
                $record['date'] ?? null,
                $timezone
            ),

            occurrences: $occurrences,

            metadata: [
                'all_day' => (bool) (
                    $record['all_day']
                    ?? false
                ),

                'venue' => is_array(
                    $record['venue'] ?? null
                )
                    ? $record['venue']
                    : null,

                'organizer' => is_array(
                    $record['organizer'] ?? null
                )
                    ? $record['organizer']
                    : null,

                'categories' => is_array(
                    $record['categories'] ?? null
                )
                    ? $record['categories']
                    : [],

                'tags' => is_array(
                    $record['tags'] ?? null
                )
                    ? $record['tags']
                    : [],

                'cost' => $this->text(
                    $record['cost'] ?? null
                ),

                'website' => $this->text(
                    $record['website'] ?? null
                ),

                'image_candidates' => $rawImageCandidates,

                'assets' => $this->assetMetadata(
                    $assetEvidence
                ),

                'documents' => $documents,

                'document_evidence' => array_map(
                    static fn(array $evidence): array => [
                        'url' =>
                        $evidence['url'],

                        'requested_url' =>
                        $evidence['requested_url'],

                        'label' =>
                        $evidence['label'],

                        'sha256' =>
                        $evidence['sha256'],

                        'content_type' =>
                        $evidence['content_type'],

                        'origin' =>
                        $evidence['origin']
                            ?? null,
                    ],
                    $documentEvidence
                ),

                'errors' => $documentExtractionErrors,
            ],

            canonicalDescriptionHtml: $renderedDescriptionHtml
                ?? (
                    $apiDescriptionHtml !== ''
                    ? $apiDescriptionHtml
                    : null
                ),

            imageValidationReason: $this->images->lastResult()?->reason,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Event container
    |--------------------------------------------------------------------------
    */

    private function eventCrawler(
        Crawler $crawler,
        array $configuration = []
    ): ?Crawler {
        $selectors = [];

        $configuredSelector = $configuration['detail_selector'] ?? null;

        if (
            is_string($configuredSelector)
            && trim($configuredSelector) !== ''
        ) {
            $selectors[] = trim($configuredSelector);
        }

        /*
        | Chêne-Bougeries custom Divi event layout.
        */

        $selectors[] = '.section-event-single';

        /*
        | Standard Tribe layouts.
        */

        $selectors[] =
            '#tribe-events-content.tribe-events-single';

        $selectors[] = '.tribe-events-single';

        $selectors[] = '#tribe-events-content';

        $selectors[] = 'article.tribe_events';

        $selectors[] = 'main article';

        foreach (
            array_values(array_unique($selectors))
            as $selector
        ) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (\Throwable) {
                continue;
            }

            if ($nodes->count() === 0) {
                continue;
            }

            $node = $nodes->first();

            if (
                trim(
                    html_entity_decode(
                        strip_tags($node->html('')),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )
                ) !== ''
            ) {
                return $node;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Clean rendered event evidence
    |--------------------------------------------------------------------------
    */

    private function cleanEventCrawler(
        Crawler $crawler
    ): Crawler {
        $html = $this->outerHtml($crawler);

        if ($html === '') {
            return $crawler;
        }

        try {
            $clean = new Crawler($html);
        } catch (\Throwable) {
            return $crawler;
        }

        /*
        | Source UI / cross-event navigation only.
        |
        | Do not remove actual event description, venue, organizer,
        | practical information, images or event links.
        */

        $selectors = [
            '.tribe-events-back',

            '#tribe-events-header',

            '#tribe-events-footer',

            '.tribe-events-nav-pagination',

            '.tribe-events-sub-nav',

            '.tribe-events-c-subscribe-dropdown__container',

            '.tribe-events-c-subscribe-dropdown',

            '.tribe-events-cal-links',

            '.tribe-events-gcal',

            '.tribe-events-ical',

            'script',

            'style',
        ];

        foreach ($selectors as $selector) {
            $this->removeNodes(
                $clean,
                $selector
            );
        }

        /*
        | Symfony wraps reparsed HTML fragments inside html/head/body.
        | Return the actual event root.
        */

        return $this->fragmentRoot($clean);
    }

    /*
    |--------------------------------------------------------------------------
    | Rendered asset scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Extract assets from the event container excluding the actual event
     * description.
     *
     * @param list<string> $descriptionSelectors
     *
     * @return list<array{
     *     asset:ClassifiedAsset,
     *     origin:string
     * }>
     */
    private function eventAssetsOutsideDescription(
        Crawler $eventCrawler,
        string $baseUrl,
        array $descriptionSelectors
    ): array {
        $html = $this->outerHtml($eventCrawler);

        if ($html === '') {
            return [];
        }

        try {
            $crawler = new Crawler(
                $html,
                $baseUrl
            );
        } catch (\Throwable) {
            return [];
        }

        /*
        | Remove description content from this classification scope.
        |
        | The description is classified separately below so unrelated
        | organizer/venue metadata cannot leak into its image context.
        */

        foreach ($descriptionSelectors as $selector) {
            $this->removeNodes(
                $crawler,
                $selector
            );
        }

        $root = $this->fragmentRoot($crawler);

        $result = [];

        foreach (
            $this->assets->extract(
                $root,
                $baseUrl
            )
            as $asset
        ) {
            $result[] = [
                'asset' => $asset,
                'origin' => 'event-detail-page',
            ];
        }

        return $result;
    }

    /**
     * Classify images within description HTML in an isolated context.
     *
     * This is important for image-only descriptions: organizer, venue and
     * practical-information text elsewhere in the Tribe event container must
     * not influence the role assigned to the description image.
     *
     * @return list<array{
     *     asset:ClassifiedAsset,
     *     origin:string
     * }>
     */
    private function descriptionAssetEvidence(
        ?string $descriptionHtml,
        string $baseUrl,
        string $origin
    ): array {
        if (
            ! is_string($descriptionHtml)
            || trim($descriptionHtml) === ''
        ) {
            return [];
        }

        try {
            $crawler = new Crawler(
                '<div data-crawl-tribe-description-root>'
                    . $descriptionHtml
                    . '</div>',
                $baseUrl
            );
        } catch (\Throwable) {
            return [];
        }

        $root = $this->fragmentRoot($crawler);

        $result = [];

        foreach (
            $this->assets->extract(
                $root,
                $baseUrl
            )
            as $asset
        ) {
            $result[] = [
                'asset' => $asset,
                'origin' => $origin,
            ];
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | DOM helpers
    |--------------------------------------------------------------------------
    */

    private function removeNodes(
        Crawler $crawler,
        string $selector
    ): void {
        try {
            $nodes = iterator_to_array(
                $crawler->filter($selector)
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }

            $parent = $node->parentNode;

            if ($parent === null) {
                continue;
            }

            $parent->removeChild($node);
        }
    }

    private function fragmentRoot(
        Crawler $crawler
    ): Crawler {
        try {
            $bodyChildren = $crawler->filter(
                'body > *'
            );

            if ($bodyChildren->count() > 0) {
                return $bodyChildren->first();
            }
        } catch (\Throwable) {
            //
        }

        return $crawler;
    }

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array{
     *     url:string,
     *     label:string,
     *     type:string,
     *     origin:string
     * }>
     */
    private function documentsFromCrawler(
        Crawler $crawler,
        string $baseUrl,
        string $origin
    ): array {
        $documents = [];

        try {
            $links = $crawler->filter('a[href]');
        } catch (\Throwable) {
            return [];
        }

        foreach ($links as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = html_entity_decode(
                trim($node->getAttribute('href')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($href === '') {
                continue;
            }

            $type = $this->documentTypeFromHref(
                $href
            );

            if ($type === null) {
                continue;
            }

            $url = $this->absoluteUrl(
                $href,
                $baseUrl
            );

            if ($url === null) {
                continue;
            }

            $label = trim(
                html_entity_decode(
                    $node->textContent ?? '',
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

            $label = preg_replace(
                '/\s+/u',
                ' ',
                $label
            ) ?? $label;

            if ($label === '') {
                $label = trim(
                    $node->getAttribute('title')
                );
            }

            $documents[] = [
                'url' => $url,
                'label' => $label,
                'type' => $type,
                'origin' => $origin,
            ];
        }

        return $documents;
    }

    private function documentTypeFromHref(
        string $href
    ): ?string {
        $decoded = html_entity_decode(
            trim($href),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        if ($decoded === '') {
            return null;
        }

        /*
        | Direct PDF URL.
        */

        if (
            preg_match(
                '/\.pdf(?:$|[?#])/iu',
                $decoded
            ) === 1
        ) {
            return 'pdf';
        }

        /*
        | Extensionless document/download endpoints.
        */

        $lower = mb_strtolower($decoded);

        $path = mb_strtolower(
            (string) parse_url(
                $decoded,
                PHP_URL_PATH
            )
        );

        if (
            str_contains($path, '/media/document/')
            || str_contains($path, '/download/')
            || str_contains($path, '/downloads/')
            || str_contains($path, '/attachment/')
            || str_contains($path, '/attachments/')
            || str_contains($path, 'attach_cmsupload_')
            || str_contains($lower, 'download=')
        ) {
            return 'document';
        }

        return null;
    }

    /**
     * @param list<array{
     *     url:string,
     *     label:string,
     *     type:string,
     *     origin:string
     * }> $documents
     *
     * @return list<array{
     *     url:string,
     *     label:string,
     *     type:string,
     *     origin:string
     * }>
     */
    private function deduplicateDocuments(
        array $documents
    ): array {
        $result = [];
        $indexes = [];

        foreach ($documents as $document) {
            $url = $document['url'] ?? null;

            if (
                ! is_string($url)
                || trim($url) === ''
            ) {
                continue;
            }

            $key = $this->urls->canonical($url);

            if (! isset($indexes[$key])) {
                $indexes[$key] = count($result);

                $result[] = $document;

                continue;
            }

            $index = $indexes[$key];

            /*
            | Upgrade an initially empty label.
            */

            if (
                trim(
                    (string) (
                        $result[$index]['label']
                        ?? ''
                    )
                ) === ''
                && trim(
                    (string) (
                        $document['label']
                        ?? ''
                    )
                ) !== ''
            ) {
                $result[$index]['label'] =
                    $document['label'];
            }

            /*
            | Prefer explicit PDF classification.
            */

            if (
                ($result[$index]['type'] ?? null)
                !== 'pdf'
                && ($document['type'] ?? null)
                === 'pdf'
            ) {
                $result[$index]['type'] = 'pdf';
            }
        }

        return array_values($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Asset evidence
    |--------------------------------------------------------------------------
    */

    private function crawlAssetRole(
        AssetRole $role
    ): string {
        return match ($role) {
            AssetRole::EventMedia =>
            'event-image-candidate',

            AssetRole::Accessibility =>
            'accessibility-icon',

            AssetRole::PracticalInfo =>
            'practical-info-image',

            AssetRole::OrganizerBranding =>
            'organizer-branding-image',

            AssetRole::UiChrome =>
            'ui-chrome-image',

            AssetRole::Unknown =>
            'event-image-candidate',
        };
    }

    /**
     * @param list<array{
     *     asset:ClassifiedAsset,
     *     origin:string
     * }> $assets
     *
     * @return list<array{
     *     asset:ClassifiedAsset,
     *     origin:string
     * }>
     */
    private function collapseAssetEvidence(
        array $assets
    ): array {
        $result = [];
        $indexes = [];

        foreach ($assets as $entry) {
            $asset = $entry['asset'] ?? null;

            if (! $asset instanceof ClassifiedAsset) {
                continue;
            }

            $identity = $this->imageIdentity(
                $asset->url
            );

            if (! isset($indexes[$identity])) {
                $indexes[$identity] = count($result);

                $result[] = $entry;

                continue;
            }

            $index = $indexes[$identity];

            $existing = $result[$index]['asset']
                ?? null;

            if (! $existing instanceof ClassifiedAsset) {
                $result[$index] = $entry;

                continue;
            }

            /*
            | Deterministic EventMedia beats other classifications for the
            | same logical image.
            */

            if (
                $existing->role !== AssetRole::EventMedia
                && $asset->role === AssetRole::EventMedia
            ) {
                $result[$index] = $entry;

                continue;
            }

            /*
            | Do not replace one semantic classification with another merely
            | because another rendition has more pixels.
            */

            if ($existing->role !== $asset->role) {
                continue;
            }

            if (
                $this->imageQualityScore($asset->url)
                > $this->imageQualityScore(
                    $existing->url
                )
            ) {
                $result[$index] = $entry;
            }
        }

        return array_values($result);
    }

    private function imageIdentity(
        string $url
    ): string {
        $host = strtolower(
            (string) parse_url(
                $url,
                PHP_URL_HOST
            )
        );

        $path = urldecode(
            (string) parse_url(
                $url,
                PHP_URL_PATH
            )
        );

        $path = mb_strtolower($path);

        /*
        | WordPress generated responsive derivatives:
        |
        | photo-300x225.jpg
        | photo-1024x768.jpg
        | photo.jpg
        */

        $path = preg_replace(
            '/-\d+x\d+(?=\.[a-z0-9]{2,6}$)/iu',
            '',
            $path
        ) ?? $path;

        /*
        | Treat photo.jpg.webp as the same logical photo.
        */

        $path = preg_replace(
            '/\.(jpe?g|png)\.webp$/iu',
            '.$1',
            $path
        ) ?? $path;

        return $host . $path;
    }

    private function imageQualityScore(
        string $url
    ): int {
        $path = urldecode(
            (string) parse_url(
                $url,
                PHP_URL_PATH
            )
        );

        if (
            preg_match(
                '/-(\d+)x(\d+)(?=\.[a-z0-9]{2,6}$)/iu',
                $path,
                $matches
            ) === 1
        ) {
            return ((int) $matches[1])
                * ((int) $matches[2]);
        }

        /*
        | A non-sized WordPress URL usually represents the original upload.
        */

        return PHP_INT_MAX;
    }

    /**
     * @param list<array{
     *     asset:ClassifiedAsset,
     *     origin:string
     * }> $assets
     *
     * @return list<array<string,mixed>>
     */
    private function assetMetadata(
        array $assets
    ): array {
        $metadata = [];

        foreach ($assets as $entry) {
            $asset = $entry['asset'] ?? null;

            if (! $asset instanceof ClassifiedAsset) {
                continue;
            }

            $metadata[] = [
                'url' => $asset->url,
                'role' => $asset->role->value,
                'reason' => $asset->reason,
                'semantic_key' => $asset->semanticKey,
                'label' => $asset->label,
                'origin' => $entry['origin']
                    ?? null,
            ];
        }

        return $metadata;
    }

    /*
    |--------------------------------------------------------------------------
    | Outer HTML
    |--------------------------------------------------------------------------
    */

    private function outerHtml(
        Crawler $crawler
    ): string {
        $node = $crawler->getNode(0);

        if ($node === null) {
            return '';
        }

        $document = $node->ownerDocument;

        if ($document === null) {
            return '';
        }

        return $document->saveHTML($node)
            ?: '';
    }

    /*
    |--------------------------------------------------------------------------
    | Description helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<string> $selectors
     */
    private function firstInnerHtml(
        Crawler $crawler,
        array $selectors
    ): ?string {
        foreach ($selectors as $selector) {
            $html = $this->innerHtml(
                $crawler,
                $selector
            );

            if ($html !== null) {
                return $html;
            }
        }

        return null;
    }

    private function innerHtml(
        Crawler $crawler,
        string $selector
    ): ?string {
        try {
            $nodes = $crawler->filter($selector);
        } catch (\Throwable) {
            return null;
        }

        if ($nodes->count() === 0) {
            return null;
        }

        $html = trim(
            $nodes->first()->html('')
        );

        return $html === ''
            ? null
            : $html;
    }

    /**
     * @param list<string> $selectors
     */
    private function firstCrawlerText(
        Crawler $crawler,
        array $selectors
    ): ?string {
        foreach ($selectors as $selector) {
            $text = $this->crawlerText(
                $crawler,
                $selector
            );

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function crawlerText(
        Crawler $crawler,
        string $selector
    ): ?string {
        try {
            $nodes = $crawler->filter($selector);
        } catch (\Throwable) {
            return null;
        }

        if ($nodes->count() === 0) {
            return null;
        }

        $text = trim(
            html_entity_decode(
                $nodes->first()->text(''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        $text = preg_replace(
            '/[ \t]+/u',
            ' ',
            $text
        ) ?: $text;

        $text = trim(
            preg_replace(
                '/\n{3,}/u',
                "\n\n",
                $text
            ) ?: $text
        );

        return $text === ''
            ? null
            : $text;
    }

    /*
    |--------------------------------------------------------------------------
    | URL resolution
    |--------------------------------------------------------------------------
    */

    private function absoluteUrl(
        string $url,
        string $baseUrl
    ): ?string {
        $url = html_entity_decode(
            trim($url),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        if ($url === '') {
            return null;
        }

        $scheme = parse_url(
            $url,
            PHP_URL_SCHEME
        );

        if (
            is_string($scheme)
            && in_array(
                strtolower($scheme),
                ['http', 'https'],
                true
            )
        ) {
            return $url;
        }

        /*
        | Ignore non-HTTP schemes such as mailto:, tel:, webcal: and
        | javascript:.
        */

        if (
            is_string($scheme)
            && $scheme !== ''
        ) {
            return null;
        }

        $baseScheme = parse_url(
            $baseUrl,
            PHP_URL_SCHEME
        );

        $baseHost = parse_url(
            $baseUrl,
            PHP_URL_HOST
        );

        if (
            ! is_string($baseScheme)
            || ! is_string($baseHost)
            || $baseHost === ''
        ) {
            return null;
        }

        $port = parse_url(
            $baseUrl,
            PHP_URL_PORT
        );

        $authority = $baseScheme
            . '://'
            . $baseHost
            . (
                is_int($port)
                ? ':' . $port
                : ''
            );

        if (str_starts_with($url, '//')) {
            return $baseScheme
                . ':'
                . $url;
        }

        if (str_starts_with($url, '/')) {
            return $authority
                . $url;
        }

        $basePath = parse_url(
            $baseUrl,
            PHP_URL_PATH
        );

        $directory = is_string($basePath)
            ? rtrim(
                str_replace(
                    '\\',
                    '/',
                    dirname($basePath)
                ),
                '/'
            )
            : '';

        return $authority
            . (
                $directory !== ''
                ? $directory . '/'
                : '/'
            )
            . $url;
    }

    /*
    |--------------------------------------------------------------------------
    | Allowed hosts
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<string>
     */
    private function allowedHosts(
        EventSource $source
    ): array {
        $configuration = is_array(
            $source->configuration
        )
            ? $source->configuration
            : [];

        $urls = [
            (string) (
                $configuration['api_url']
                ?? ''
            ),

            (string) $source->listing_url,
        ];

        $hosts = [];

        foreach ($urls as $url) {
            if ($url === '') {
                continue;
            }

            $host = parse_url(
                $url,
                PHP_URL_HOST
            );

            if (
                is_string($host)
                && trim($host) !== ''
            ) {
                $hosts[] = strtolower(
                    trim($host)
                );
            }
        }

        if (
            is_array(
                $configuration['allowed_hosts']
                    ?? null
            )
        ) {
            foreach (
                $configuration['allowed_hosts']
                as $host
            ) {
                if (
                    is_string($host)
                    && trim($host) !== ''
                ) {
                    $hosts[] = strtolower(
                        trim($host)
                    );
                }
            }
        }

        return array_values(
            array_unique($hosts)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Date
    |--------------------------------------------------------------------------
    */

    private function date(
        mixed $value,
        string $timezone
    ): ?CarbonImmutable {
        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value,
                $timezone
            )->setTimezone(
                'Europe/Zurich'
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Text
    |--------------------------------------------------------------------------
    */

    private function text(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(
            html_entity_decode(
                strip_tags($value),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        return $value === ''
            ? null
            : $value;
    }

    private function plainText(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace(
            '/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/iu',
            "$0\n",
            $value
        ) ?: $value;

        $value = html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $value = preg_replace(
            '/[ \t]+/u',
            ' ',
            $value
        ) ?: $value;

        $value = trim(
            preg_replace(
                '/\n{3,}/u',
                "\n\n",
                $value
            ) ?: $value
        );

        return $value === ''
            ? null
            : $value;
    }
}
