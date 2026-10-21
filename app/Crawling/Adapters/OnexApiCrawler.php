<?php

namespace App\Crawling\Adapters;

use App\Crawling\Assets\AssetRole;
use App\Crawling\Assets\EventAssetExtractor;
use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\ParsedOccurrence;
use App\Crawling\Data\RawEvent;
use App\Crawling\Documents\PdfEvidenceExtractor;
use App\Crawling\Fetchers\HttpPageFetcher;
use App\Crawling\Fetchers\ImageValidator;
use App\Crawling\Support\UrlNormalizer;
use App\Models\EventSource;
use Carbon\CarbonImmutable;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

class OnexApiCrawler implements CommuneCrawler
{
    public function __construct(
        private readonly HttpPageFetcher $fetcher,
        private readonly UrlNormalizer $urls,
        private readonly ImageValidator $images,
        private readonly EventAssetExtractor $assets,
    ) {}

    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $configuration = is_array($source->configuration)
            ? $source->configuration
            : [];

        $apiUrl = (string) (
            $configuration['contents_api_url']
            ?? 'https://www.onex.ch/api/contents?type=events&locale=fr&site=1'
        );

        $records = $this->fetcher->getJson(
            $apiUrl,
            $this->allowedHosts(
                $source,
                $apiUrl
            )
        );

        $members = $records['hydra:member'] ?? [];

        if (! is_array($members)) {
            return [];
        }

        return collect($members)
            ->filter(
                fn(mixed $record): bool =>
                is_array($record)
            )
            ->map(
                function (array $record) use ($apiUrl): ?EventReference {
                    $id = $record['id'] ?? null;

                    $url =
                        $record['aio:urls']['1']['langs']['fr'][0]
                        ?? null;

                    if (
                        (
                            ! is_int($id)
                            && ! ctype_digit(
                                (string) $id
                            )
                        )
                        || ! is_string($url)
                        || trim($url) === ''
                    ) {
                        return null;
                    }

                    return new EventReference(
                        url: $this->urls->canonical(
                            $url
                        ),

                        externalId: (string) $id,

                        listingUrl: $apiUrl,

                        titleHint: is_string(
                            $record['title'] ?? null
                        )
                            ? trim(
                                $record['title']
                            )
                            : null,

                        metadata: [
                            'record' =>
                            $record,
                        ],
                    );
                }
            )
            ->filter()
            ->when(
                ! $options->all
                    && $options->eventLimit !== null,

                fn($references) =>
                $references->take(
                    $options->eventLimit
                )
            )
            ->values()
            ->all();
    }

    public function fetch(
        EventSource $source,
        EventReference $reference
    ): RawEvent {
        $configuration = is_array(
            $source->configuration
        )
            ? $source->configuration
            : [];

        $record =
            $reference->metadata['record']
            ?? [];

        if (! is_array($record)) {
            $record = [];
        }

        /*
        |--------------------------------------------------------------------------
        | Structured API dates
        |--------------------------------------------------------------------------
        */

        $startsAt = $this->date(
            $record['aio:dateFrom'] ?? null
        );

        $endsAt = $this->date(
            $record['aio:dateTo'] ?? null
        );

        /*
        |--------------------------------------------------------------------------
        | Calendar / occurrence evidence
        |--------------------------------------------------------------------------
        |
        | The calendar endpoint is a distinct source of evidence used to
        | construct ParsedOccurrence objects.
        |
        | Preserve the original response in raw_html rather than only keeping
        | the interpreted occurrences.
        |
        */

        $calendarUrl =
            $configuration['calendar_api_url']
            ?? null;

        $calendarEvidence = [];
        $occurrences = [];

        if (
            is_string($calendarUrl)
            && trim($calendarUrl) !== ''
        ) {
            $calendarUrl = str_replace(
                '{id}',
                rawurlencode(
                    (string) $reference->externalId
                ),
                $calendarUrl
            );

            $calendar = $this->fetcher->getJson(
                $calendarUrl,
                $this->allowedHosts(
                    $source,
                    $calendarUrl
                )
            );

            if (is_array($calendar)) {
                $calendarEvidence = $calendar;

                $occurrences = collect(
                    $calendar
                )
                    ->filter(
                        fn(mixed $entry): bool =>
                        is_array($entry)
                    )
                    ->map(
                        fn(array $entry): ?ParsedOccurrence =>
                        $this->occurrence(
                            $entry
                        )
                    )
                    ->filter()
                    ->unique(
                        fn(ParsedOccurrence $occurrence): string =>
                        $occurrence->key()
                    )
                    ->values()
                    ->all();
            }
        }

        if (
            $occurrences === []
            && $startsAt !== null
        ) {
            $occurrences = [
                new ParsedOccurrence(
                    $startsAt,
                    $endsAt
                ),
            ];
        }

        $startsAt ??=
            $occurrences[0]->startsAt
            ?? null;

        $endsAt ??=
            collect(
                $occurrences
            )
            ->last()
            ?->endsAt;

        /*
        |--------------------------------------------------------------------------
        | API title / description
        |--------------------------------------------------------------------------
        */

        $title = is_string(
            $record['title'] ?? null
        )
            ? trim(
                $record['title']
            )
            : $reference->titleHint;

        $apiDescription =
            $this->description(
                $record
            );

        /*
        |--------------------------------------------------------------------------
        | Fetch rendered Onex event page
        |--------------------------------------------------------------------------
        */

        $detailPage = $this->fetcher->get(
            $reference->url,
            $this->allowedHosts(
                $source,
                $reference->url
            )
        );

        $crawler = new Crawler(
            $detailPage->body,
            $detailPage->finalUrl
        );

        $canonicalUrl =
            $this->urls->canonical(
                $this->attribute(
                    $crawler,
                    'link[rel="canonical"]',
                    'href'
                )
                    ?? $this->attribute(
                        $crawler,
                        'meta[property="og:url"]',
                        'content'
                    )
                    ?? $detailPage->finalUrl
            );

        /*
        |--------------------------------------------------------------------------
        | Find event-specific page content
        |--------------------------------------------------------------------------
        */

        $eventCrawler = $this->eventCrawler(
            $crawler,
            $configuration
        );

        $detailHtml =
            $eventCrawler !== null
            ? $this->outerHtml(
                $eventCrawler
            )
            : '';

        /*
        |--------------------------------------------------------------------------
        | Rendered text
        |--------------------------------------------------------------------------
        */

        $renderedText =
            $eventCrawler !== null
            ? $this->cleanText(
                $eventCrawler->text('')
            )
            : null;

        $description =
            $apiDescription
            ?? $renderedText;

        /*
        |--------------------------------------------------------------------------
        | Classified page assets
        |--------------------------------------------------------------------------
        |
        | All assets belonging to the event-specific page fragment survive.
        |
        | Accessibility, practical information, organizer branding and UI
        | assets remain evidence but do not enter the hero candidate pool.
        |
        */

        $classifiedAssets =
            $eventCrawler !== null
            ? $this->assets->extract(
                $eventCrawler,
                $detailPage->finalUrl
            )
            : [];

        /*
|--------------------------------------------------------------------------
| Remove Onex page-level decorative media
|--------------------------------------------------------------------------
|
| The rendered event page contains the generic Agenda header image
| bg-hero-agenda.*. It belongs to the surrounding Agenda page template,
| not to the individual event.
|
| It remains preserved inside event-content HTML, but must not participate
| in explicit event image evidence / hero selection.
|
*/

        $classifiedAssets = array_values(
            array_filter(
                $classifiedAssets,
                fn($asset): bool =>
                ! $this->isOnexPageChromeImage(
                    $asset->url
                )
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Collapse responsive renditions of the same Onex image
        |--------------------------------------------------------------------------
        |
        | The HTML contains mobile/tablet/desktop/WebP/JPEG renditions of the same
        | underlying image. The original HTML already preserves every srcset.
        |
        | Explicit image evidence only needs one semantic representative.
        |
        */

        $classifiedAssets =
            $this->collapseOnexImageVariants(
                $classifiedAssets
            );

        $imageCandidates =
            $this->eventImageCandidates(
                $classifiedAssets
            );

        /*
        |--------------------------------------------------------------------------
        | Structured Onex API image evidence
        |--------------------------------------------------------------------------
        |
        | aio:images is explicitly attached to the event API record, so all
        | available originals/transformed formats should survive.
        |
        */

        foreach (
            $this->apiImageUrls(
                $record,
                $reference->url
            )
            as $url
        ) {
            $imageCandidates[] = [
                'url' =>
                $url,

                'origin' =>
                'onex-api',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | OpenGraph image evidence
        |--------------------------------------------------------------------------
        */

        $openGraphImage = $this->attribute(
            $crawler,
            'meta[property="og:image"]',
            'content'
        );

        if (
            is_string($openGraphImage)
            && trim($openGraphImage) !== ''
        ) {
            try {
                $imageCandidates[] = [
                    'url' =>
                    $this->urls->absolute(
                        $openGraphImage,
                        $detailPage->finalUrl
                    ),

                    'origin' =>
                    'open-graph',
                ];
            } catch (\Throwable) {
                //
            }
        }

        $imageCandidates =
            $this->uniqueCandidates(
                $imageCandidates
            );

        /*
        |--------------------------------------------------------------------------
        | Convenience imageUrl
        |--------------------------------------------------------------------------
        |
        | ImageValidator only determines RawEvent::imageUrl.
        |
        | A failed image validation never removes the source evidence from
        | raw_html.
        |
        */

        $image = null;
        $imageValidationReason = null;

        foreach (
            $imageCandidates
            as $candidate
        ) {
            try {
                $validated =
                    $this->images->validate(
                        $candidate['url']
                    );

                $imageValidationReason =
                    $this->images
                    ->lastResult()
                    ?->reason;

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
        | Related event documents
        |--------------------------------------------------------------------------
        */

        $documents =
            $eventCrawler !== null
            ? $this->documentsFromCrawler(
                $eventCrawler,
                $detailPage->finalUrl
            )
            : [];

        $documents = array_slice(
            $documents,
            0,
            5
        );

        /*
        |--------------------------------------------------------------------------
        | PDF document evidence
        |--------------------------------------------------------------------------
        |
        | Extraction is best effort.
        |
        | If a document cannot be downloaded or parsed, its original link is
        | still preserved in raw_html.
        |
        */

        $documentEvidence = [];

        if ($documents !== []) {
            $pdfs = app(
                PdfEvidenceExtractor::class
            );

            foreach ($documents as $document) {
                try {
                    $documentHost =
                        parse_url(
                            $document['url'],
                            PHP_URL_HOST
                        );

                    $documentAllowedHosts =
                        array_values(
                            array_unique([
                                ...$this->allowedHosts(
                                    $source,
                                    $document['url']
                                ),

                                ...(
                                    is_string(
                                        $documentHost
                                    )
                                    && $documentHost !== ''
                                    ? [$documentHost]
                                    : []
                                ),
                            ])
                        );

                    $evidence = $pdfs->extract(
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
                    ];
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Full API JSON evidence
        |--------------------------------------------------------------------------
        */

        $recordJson = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
        );

        $calendarJson =
            $calendarEvidence !== []
            ? json_encode(
                $calendarEvidence,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRETTY_PRINT
            )
            : null;

        /*
        |--------------------------------------------------------------------------
        | Classified asset evidence
        |--------------------------------------------------------------------------
        */

        $assetEvidenceHtml =
            $this->assetEvidenceHtml(
                $classifiedAssets,
                $title
            );

        $classifiedAssetUrls =
            array_fill_keys(
                array_map(
                    static fn($asset): string =>
                    $asset->url,
                    $classifiedAssets
                ),
                true
            );

        /*
        |--------------------------------------------------------------------------
        | Supplementary image evidence
        |--------------------------------------------------------------------------
        |
        | Assets already found and classified in the rendered page are not
        | duplicated here.
        |
        */

        $supplementaryImageHtml = '';

        foreach (
            $imageCandidates
            as $candidate
        ) {
            $candidateUrl =
                $candidate['url']
                ?? null;

            if (
                ! is_string($candidateUrl)
                || isset(
                    $classifiedAssetUrls[$candidateUrl]
                )
            ) {
                continue;
            }

            $supplementaryImageHtml .=
                '<img'
                . ' src="'
                . e($candidateUrl)
                . '"'
                . ' alt="'
                . e($title ?? '')
                . '"'
                . ' data-crawl-role="event-image-candidate"'
                . ' data-crawl-asset-role="unknown"'
                . ' data-crawl-origin="'
                . e(
                    $candidate['origin']
                        ?? 'unknown'
                )
                . '"'
                . '>';
        }

        if ($supplementaryImageHtml !== '') {
            $supplementaryImageHtml =
                '<section data-crawl-source="supplementary-image-evidence">'
                . $supplementaryImageHtml
                . '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Explicit document candidates
        |--------------------------------------------------------------------------
        */

        $documentCandidateHtml = '';

        if ($documents !== []) {
            $documentCandidateHtml =
                '<section data-crawl-source="document-candidates">';

            foreach (
                $documents
                as $document
            ) {
                $documentType =
                    preg_match(
                        '/\.pdf(?:$|[?#])/iu',
                        $document['url']
                    ) === 1
                    ? 'pdf'
                    : 'download';

                $documentCandidateHtml .=
                    '<a'
                    . ' href="'
                    . e(
                        $document['url']
                    )
                    . '"'
                    . ' data-crawl-role="event-document"'
                    . ' data-crawl-document-type="'
                    . e(
                        $documentType
                    )
                    . '"'
                    . '>'
                    . e(
                        $document['label'] !== ''
                            ? $document['label']
                            : 'Document'
                    )
                    . '</a>';
            }

            $documentCandidateHtml .=
                '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Extracted document evidence
        |--------------------------------------------------------------------------
        */

        $documentEvidenceHtml = '';

        foreach (
            $documentEvidence
            as $evidence
        ) {
            $documentEvidenceHtml .=
                '<section'
                . ' data-crawl-source="linked-document"'
                . ' data-document-type="pdf"'
                . ' data-document-url="'
                . e(
                    $evidence['url']
                )
                . '"'
                . ' data-document-sha256="'
                . e(
                    $evidence['sha256']
                )
                . '"'
                . '>';

            if (
                is_string(
                    $evidence['label']
                )
                && trim(
                    $evidence['label']
                ) !== ''
            ) {
                $documentEvidenceHtml .=
                    '<h3 data-crawl-role="document-label">'
                    . e(
                        $evidence['label']
                    )
                    . '</h3>';
            }

            $documentEvidenceHtml .=
                '<pre data-crawl-role="document-text">'
                . e(
                    $evidence['text']
                )
                . '</pre>';

            $documentEvidenceHtml .=
                '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Build raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml =
            '<article'
            . ' data-crawl-adapter="onex-api"'
            . ' data-start="'
            . e(
                $startsAt?->toIso8601String()
                    ?? ''
            )
            . '"'
            . ' data-end="'
            . e(
                $endsAt?->toIso8601String()
                    ?? ''
            )
            . '"'
            . '>';

        /*
        | PRIMARY:
        | actual rendered Onex event page.
        */

        if ($detailHtml !== '') {
            $rawHtml .=
                '<section data-crawl-source="event-content">'
                . $detailHtml
                . '</section>';
        } else {
            /*
            | API fallback only if page isolation fails.
            */

            $rawHtml .=
                '<section data-crawl-source="api-fallback">'
                . '<h1>'
                . e(
                    $title
                        ?? 'Onex event'
                )
                . '</h1>';

            if (
                $apiDescription !== null
            ) {
                $rawHtml .=
                    '<p>'
                    . e(
                        $apiDescription
                    )
                    . '</p>';
            }

            $rawHtml .=
                '</section>';
        }

        /*
        | Classified page assets.
        */

        $rawHtml .=
            $assetEvidenceHtml;

        /*
        | API / OpenGraph image evidence.
        */

        $rawHtml .=
            $supplementaryImageHtml;

        /*
        | Original document links.
        */

        $rawHtml .=
            $documentCandidateHtml;

        /*
        | Extracted PDF text.
        */

        $rawHtml .=
            $documentEvidenceHtml;

        /*
        | Structured Onex contents API.
        */

        if (is_string($recordJson)) {
            $rawHtml .=
                '<section data-crawl-source="structured-data">'
                . '<script type="application/json">'
                . $recordJson
                . '</script>'
                . '</section>';
        }

        /*
        | Structured Onex calendar API.
        */

        if (is_string($calendarJson)) {
            $rawHtml .=
                '<section data-crawl-source="calendar-data">'
                . '<script type="application/json">'
                . $calendarJson
                . '</script>'
                . '</section>';
        }

        $rawHtml .=
            '</article>';

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        */

        return new RawEvent(
            canonicalUrl: $canonicalUrl,

            rawHtml: $rawHtml,

            externalId: $reference->externalId,

            title: $title,

            startsAt: $startsAt,

            endsAt: $endsAt,

            isRecurring: count($occurrences) > 1,

            recurrenceSummary: count($occurrences) > 1
                ? count($occurrences)
                . ' scheduled occurrences'
                : null,

            description: $description,

            imageUrl: $image,

            sourceLanguage: (string) (
                $configuration['source_language']
                ?? 'fr'
            ),

            sourcePublishedAt: $this->date(
                $record['aio:createdAt']
                    ?? null
            ),

            occurrences: $occurrences,

            metadata: [
                'address' =>
                $record['address']
                    ?? null,

                'categories' =>
                $record['categories']
                    ?? null,

                'image_candidates' =>
                $imageCandidates,

                'assets' =>
                $this->assetMetadata(
                    $classifiedAssets
                ),

                'documents' =>
                $documents,

                'document_evidence' =>
                array_map(
                    static fn(
                        array $evidence
                    ): array => [
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

                        'text_extracted' =>
                        true,
                    ],
                    $documentEvidence
                ),

                'calendar_occurrence_count' =>
                count(
                    $occurrences
                ),
            ],

            imageValidationReason: $imageValidationReason,
        );
    }

    private function isOnexPageChromeImage(
        string $url
    ): bool {
        $path = mb_strtolower(
            (string) parse_url(
                $url,
                PHP_URL_PATH
            )
        );

        return str_contains(
            $path,
            'bg-hero-agenda.'
        );
    }

    /**
     * Collapse responsive Onex renditions into one semantic asset.
     *
     * @param array<int, mixed> $assets
     * @return array<int, mixed>
     */
    private function collapseOnexImageVariants(
        array $assets
    ): array {
        $grouped = [];

        foreach ($assets as $asset) {
            $key =
                $this->onexImageIdentity(
                    $asset->url
                );

            /*
        | If the same semantic image appears more than once, prefer the
        | deterministically classified event_media version.
        */

            if (! isset($grouped[$key])) {
                $grouped[$key] = $asset;

                continue;
            }

            if (
                $asset->role === AssetRole::EventMedia
                && $grouped[$key]->role !== AssetRole::EventMedia
            ) {
                $grouped[$key] = $asset;
            }
        }

        return array_values(
            $grouped
        );
    }

    private function onexImageIdentity(
        string $url
    ): string {
        $path =
            (string) parse_url(
                $url,
                PHP_URL_PATH
            );

        $query =
            (string) parse_url(
                $url,
                PHP_URL_QUERY
            );

        /*
    | Example:
    |
    | /media/image/0/mini_16_10/foo.jpg.webp
    | /media/image/0/zoom_16_10/foo.jpg
    |
    | become the same semantic key:
    |
    | foo.jpg?<hash>
    */

        $filename =
            basename(
                $path
            );

        $filename =
            preg_replace(
                '/\.webp$/iu',
                '',
                $filename
            ) ?: $filename;

        return mb_strtolower(
            $filename
                . '?'
                . $query
        );
    }

    /**
     * Find the event-specific section of the Onex page.
     *
     * @param array<string, mixed> $configuration
     */
    private function eventCrawler(
        Crawler $crawler,
        array $configuration
    ): ?Crawler {
        $selectors = [];

        /*
        | Allows tightening the selector through source configuration without
        | modifying the crawler.
        */

        if (
            is_string(
                $configuration['detail_selector']
                    ?? null
            )
            && trim(
                $configuration['detail_selector']
            ) !== ''
        ) {
            $selectors[] = trim(
                $configuration['detail_selector']
            );
        }

        $selectors = [
            ...$selectors,

            'main article',
            'main .event-detail',
            'main .event',
            'main .detail',
            'main .content',
            'main',
        ];

        foreach (
            $selectors
            as $selector
        ) {
            try {
                $nodes =
                    $crawler->filter(
                        $selector
                    );
            } catch (\Throwable) {
                continue;
            }

            if (
                $nodes->count() === 0
            ) {
                continue;
            }

            $node =
                $nodes->first();

            if (
                trim(
                    strip_tags(
                        $node->html('')
                    )
                ) === ''
            ) {
                continue;
            }

            return $node;
        }

        return null;
    }

    /**
     * @param array<int, mixed> $classifiedAssets
     * @return list<array{url:string,origin:string}>
     */
    private function eventImageCandidates(
        array $classifiedAssets
    ): array {
        $candidates = [];
        $seen = [];

        foreach (
            $classifiedAssets
            as $asset
        ) {
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

            if (
                isset(
                    $seen[$asset->url]
                )
            ) {
                continue;
            }

            $seen[$asset->url] = true;

            $candidates[] = [
                'url' =>
                $asset->url,

                'origin' =>
                'event-detail-page',
            ];
        }

        return $candidates;
    }

    /**
     * Render all classified page assets into raw evidence.
     *
     * @param array<int, mixed> $classifiedAssets
     */
    private function assetEvidenceHtml(
        array $classifiedAssets,
        ?string $title
    ): string {
        if ($classifiedAssets === []) {
            return '';
        }

        $html =
            '<section data-crawl-source="image-evidence">';

        foreach (
            $classifiedAssets
            as $asset
        ) {
            $html .=
                '<img'
                . ' src="'
                . e(
                    $asset->url
                )
                . '"'
                . ' alt="'
                . e(
                    $asset->label
                        ?? $title
                        ?? ''
                )
                . '"'
                . ' data-crawl-role="'
                . e(
                    $this->crawlAssetRole(
                        $asset->role
                    )
                )
                . '"'
                . ' data-crawl-asset-role="'
                . e(
                    $asset->role->value
                )
                . '"'
                . ' data-crawl-origin="event-detail-page"';

            if (
                is_string(
                    $asset->reason
                )
                && $asset->reason !== ''
            ) {
                $html .=
                    ' data-crawl-classification-reason="'
                    . e(
                        $asset->reason
                    )
                    . '"';
            }

            if (
                is_string(
                    $asset->semanticKey
                )
                && $asset->semanticKey !== ''
            ) {
                $html .=
                    ' data-crawl-semantic-key="'
                    . e(
                        $asset->semanticKey
                    )
                    . '"';
            }

            if (
                is_string(
                    $asset->label
                )
                && $asset->label !== ''
            ) {
                $html .=
                    ' data-crawl-label="'
                    . e(
                        $asset->label
                    )
                    . '"';
            }

            $html .= '>';
        }

        $html .=
            '</section>';

        return $html;
    }

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
     * @param array<int, mixed> $classifiedAssets
     * @return list<array<string, mixed>>
     */
    private function assetMetadata(
        array $classifiedAssets
    ): array {
        return array_map(
            static fn($asset): array => [
                'url' =>
                $asset->url,

                'role' =>
                $asset->role->value,

                'reason' =>
                $asset->reason,

                'semantic_key' =>
                $asset->semanticKey,

                'label' =>
                $asset->label,
            ],
            $classifiedAssets
        );
    }

    /**
     * Return one representative URL per Onex image.
     *
     * All alternative formats remain preserved in the structured API JSON.
     *
     * @param array<string, mixed> $record
     * @return list<string>
     */
    private function apiImageUrls(
        array $record,
        string $baseUrl
    ): array {
        $images =
            is_array(
                $record['aio:images']
                    ?? null
            )
            ? $record['aio:images']
            : [];

        $result = [];

        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            $formats =
                is_array(
                    $image['formats']
                        ?? null
                )
                ? $image['formats']
                : [];

            /*
        | Prefer the same 16:10 rendition used by the actual event carousel.
        */

            $preferredKeys = [
                'zoom_16_10',
                'big_16_10',
                'normal_16_10',
                'medium_16_10',
                'hero_desktop',
                'summary_desktop',
            ];

            $candidate = null;

            foreach ($preferredKeys as $key) {
                if (
                    is_array(
                        $formats[$key]
                            ?? null
                    )
                    && is_string(
                        $formats[$key]['url']
                            ?? null
                    )
                    && trim(
                        $formats[$key]['url']
                    ) !== ''
                ) {
                    $candidate =
                        $formats[$key]['url'];

                    break;
                }
            }

            /*
        | Fallback to the first available format.
        */

            if ($candidate === null) {
                foreach ($formats as $format) {
                    if (
                        is_array($format)
                        && is_string(
                            $format['url']
                                ?? null
                        )
                        && trim(
                            $format['url']
                        ) !== ''
                    ) {
                        $candidate =
                            $format['url'];

                        break;
                    }
                }
            }

            /*
        | Some API versions may expose a direct URL.
        */

            if (
                $candidate === null
                && is_string(
                    $image['url']
                        ?? null
                )
                && trim(
                    $image['url']
                ) !== ''
            ) {
                $candidate =
                    $image['url'];
            }

            if ($candidate === null) {
                continue;
            }

            try {
                $result[] =
                    $this->urls->absolute(
                        $candidate,
                        $baseUrl
                    );
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values(
            array_unique(
                $result
            )
        );
    }

    /**
     * @param array<int, array{url:string,origin:string}> $candidates
     * @return list<array{url:string,origin:string}>
     */
    private function uniqueCandidates(
        array $candidates
    ): array {
        $result = [];
        $seen = [];

        foreach (
            $candidates
            as $candidate
        ) {
            $url =
                $candidate['url']
                ?? null;

            if (
                ! is_string($url)
                || trim($url) === ''
            ) {
                continue;
            }

            $url = trim(
                $url
            );

            if (
                isset(
                    $seen[$url]
                )
            ) {
                continue;
            }

            $seen[$url] = true;

            $result[] = [
                'url' =>
                $url,

                'origin' =>
                (string) (
                    $candidate['origin']
                    ?? 'unknown'
                ),
            ];
        }

        return $result;
    }

    /**
     * Find PDFs/downloads attached to the actual event.
     *
     * @return list<array{url:string,label:string}>
     */
    private function documentsFromCrawler(
        Crawler $crawler,
        string $baseUrl
    ): array {
        $documents = [];
        $seen = [];

        foreach (
            $crawler->filter(
                'a[href]'
            )
            as $node
        ) {
            if (
                ! $node instanceof DOMElement
            ) {
                continue;
            }

            $href = trim(
                $node->getAttribute(
                    'href'
                )
            );

            if ($href === '') {
                continue;
            }

            $href =
                html_entity_decode(
                    $href,
                    ENT_QUOTES
                        | ENT_HTML5,
                    'UTF-8'
                );

            $lower =
                strtolower(
                    $href
                );

            $isDocument =
                preg_match(
                    '/\.pdf(?:$|[?#])/iu',
                    $href
                ) === 1
                || str_contains(
                    $lower,
                    '/media/document/'
                )
                || str_contains(
                    $lower,
                    '/download/'
                );

            if (! $isDocument) {
                continue;
            }

            try {
                $url =
                    $this->urls->absolute(
                        $href,
                        $baseUrl
                    );
            } catch (\Throwable) {
                continue;
            }

            $url =
                $this->urls->canonical(
                    $url
                );

            if (
                isset(
                    $seen[$url]
                )
            ) {
                continue;
            }

            $seen[$url] = true;

            $documents[] = [
                'url' =>
                $url,

                'label' =>
                $this->cleanText(
                    $node->textContent
                )
                    ?? '',
            ];
        }

        return $documents;
    }

    private function outerHtml(
        Crawler $crawler
    ): string {
        $node =
            $crawler->getNode(0);

        if ($node === null) {
            return '';
        }

        $document =
            $node->ownerDocument;

        if ($document === null) {
            return '';
        }

        return $document->saveHTML(
            $node
        ) ?: '';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function occurrence(
        array $entry
    ): ?ParsedOccurrence {
        $start =
            $this->date(
                $entry['start']
                    ?? null
            );

        if ($start === null) {
            return null;
        }

        return new ParsedOccurrence(
            $start,

            $this->date(
                $entry['end']
                    ?? null
            )
                ?->endOfDay()
        );
    }

    private function date(
        mixed $value
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
                'Europe/Zurich'
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function description(
        array $record
    ): ?string {
        if (
            is_string(
                $record['description']
                    ?? null
            )
            && trim(
                $record['description']
            ) !== ''
        ) {
            return $this->cleanText(
                $record['description']
            );
        }

        $parts = [];

        if (
            is_string(
                $record['header']
                    ?? null
            )
        ) {
            $parts[] =
                $record['header'];
        }

        foreach (
            is_array(
                $record['blocks']
                    ?? null
            )
                ? $record['blocks']
                : []
            as $block
        ) {
            if (
                is_array($block)
                && is_string(
                    $block['html']
                        ?? null
                )
            ) {
                $parts[] =
                    $block['html'];
            }
        }

        return $this->cleanText(
            implode(
                "\n",
                $parts
            )
        );
    }

    private function cleanText(
        ?string $value
    ): ?string {
        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        $value =
            preg_replace(
                '/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/iu',
                "$0\n",
                $value
            )
            ?: $value;

        $value =
            html_entity_decode(
                strip_tags(
                    $value
                ),
                ENT_QUOTES
                    | ENT_HTML5,
                'UTF-8'
            );

        $value =
            preg_replace(
                '/[ \t]+/u',
                ' ',
                $value
            )
            ?: $value;

        $value =
            preg_replace(
                '/ *\n */u',
                "\n",
                $value
            )
            ?: $value;

        $value = trim(
            preg_replace(
                '/\n{3,}/u',
                "\n\n",
                $value
            )
                ?: $value
        );

        return $value === ''
            ? null
            : $value;
    }

    private function attribute(
        Crawler $crawler,
        string $selector,
        string $attribute
    ): ?string {
        try {
            $node =
                $crawler
                ->filter(
                    $selector
                )
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (
            $node->count() === 0
        ) {
            return null;
        }

        $value =
            $node->attr(
                $attribute
            );

        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        return trim(
            $value
        );
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(
        EventSource $source,
        string $requestUrl
    ): array {
        $configuration =
            is_array(
                $source->configuration
            )
            ? $source->configuration
            : [];

        $configured =
            is_array(
                $configuration['allowed_hosts']
                    ?? null
            )
            ? $configuration['allowed_hosts']
            : [];

        return array_values(
            array_unique(
                array_filter([
                    parse_url(
                        $source->listing_url,
                        PHP_URL_HOST
                    ),

                    parse_url(
                        $requestUrl,
                        PHP_URL_HOST
                    ),

                    ...$configured,
                ], 'is_string')
            )
        );
    }
}
