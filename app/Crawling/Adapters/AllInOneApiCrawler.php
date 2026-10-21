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
use App\Crawling\Support\UrlNormalizer;
use App\Models\EventSource;
use Carbon\CarbonImmutable;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

class AllInOneApiCrawler implements CommuneCrawler
{
    public function __construct(
        private readonly HttpPageFetcher $fetcher,
        private readonly UrlNormalizer $urls,
        private readonly ImageValidator $images,
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

        /*
        |--------------------------------------------------------------------------
        | Important
        |--------------------------------------------------------------------------
        |
        | Unlike OnexApiCrawler, NEVER fall back to Onex's API here.
        |
        */

        $apiUrl = $configuration['contents_api_url']
            ?? $source->listing_url;

        if (
            ! is_string($apiUrl)
            || trim($apiUrl) === ''
        ) {
            return [];
        }

        $records = $this->fetcher->getJson(
            $apiUrl,
            $this->allowedHosts(
                $source,
                $apiUrl
            )
        );

        $members = $records['hydra:member']
            ?? [];

        if (! is_array($members)) {
            return [];
        }

        return collect($members)
            ->filter(
                fn(mixed $record): bool =>
                is_array($record)
            )
            ->map(
                function (array $record) use (
                    $apiUrl
                ): ?EventReference {
                    $id = $record['id']
                        ?? null;

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
                            $record['title']
                                ?? null
                        )
                            ? trim(
                                $record['title']
                            )
                            : null,

                        metadata: [
                            'record' => $record,
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
        | Dates from AllInOne API
        |--------------------------------------------------------------------------
        */

        $startsAt = $this->date(
            $record['aio:dateFrom']
                ?? null
        );

        $endsAt = $this->date(
            $record['aio:dateTo']
                ?? null
        );

        /*
        |--------------------------------------------------------------------------
        | Occurrences from calendar API
        |--------------------------------------------------------------------------
        */

        $calendarUrl =
            $configuration['calendar_api_url']
            ?? null;

        $calendar = [];
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

            $occurrences = collect(
                $calendar
            )
                ->filter(
                    fn(mixed $entry): bool =>
                    is_array($entry)
                )
                ->map(
                    fn(
                        array $entry
                    ): ?ParsedOccurrence =>
                    $this->occurrence(
                        $entry
                    )
                )
                ->filter()
                ->values()
                ->all();
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
            collect($occurrences)
            ->last()
            ?->endsAt;

        /*
        |--------------------------------------------------------------------------
        | Core API information
        |--------------------------------------------------------------------------
        */

        $title = is_string(
            $record['title']
                ?? null
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
        | Fetch the real event detail page
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

        /*
        |--------------------------------------------------------------------------
        | Event-specific page container
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

        $renderedText =
            $eventCrawler !== null
            ? $this->cleanText(
                $eventCrawler->text('')
            )
            : null;

        /*
        |--------------------------------------------------------------------------
        | Prefer API description as convenience field
        |--------------------------------------------------------------------------
        */

        $description =
            $apiDescription
            ?? $renderedText;

        /*
        |--------------------------------------------------------------------------
        | API image identity
        |--------------------------------------------------------------------------
        |
        | AllInOne pages often contain a generic page/banner image in addition
        | to the actual image assigned to the event.
        |
        | When aio:images is available, we use it to distinguish the actual
        | event media from generic page decoration.
        |
        */

        $apiImageTokens =
            $this->apiImageIdentityTokens(
                $record,
                $detailPage->finalUrl
            );

        /*
        |--------------------------------------------------------------------------
        | Classified assets from the rendered event page
        |--------------------------------------------------------------------------
        */

        $classifiedAssets =
            $eventCrawler !== null
            ? $this->assets->extract(
                $eventCrawler,
                $detailPage->finalUrl
            )
            : [];

        $classifiedAssets =
            $this->relevantAssets(
                $classifiedAssets,
                $apiImageTokens
            );

        /*
        |--------------------------------------------------------------------------
        | Collapse responsive variants
        |--------------------------------------------------------------------------
        */

        $classifiedAssets =
            $this->collapseAssetVariants(
                $classifiedAssets
            );

        /*
        |--------------------------------------------------------------------------
        | Hero candidates
        |--------------------------------------------------------------------------
        |
        | Only EventMedia and Unknown assets may compete for imageUrl.
        |
        */

        $imageCandidates = [];

        foreach (
            $classifiedAssets as $asset
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

            $imageCandidates[] = [
                'url' => $asset->url,
                'origin' =>
                'event-detail-page',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Open Graph
        |--------------------------------------------------------------------------
        */

        $ogImage = $this->attribute(
            $crawler,
            'meta[property="og:image"]',
            'content'
        );

        if (
            is_string($ogImage)
            && trim($ogImage) !== ''
        ) {
            try {
                $ogImage =
                    $this->urls->absolute(
                        $ogImage,
                        $detailPage->finalUrl
                    );

                /*
                | If the API tells us which image belongs to the event,
                | avoid introducing an unrelated global/site OG image.
                */

                if (
                    $apiImageTokens === []
                    || $this->urlMatchesTokens(
                        $ogImage,
                        $apiImageTokens
                    )
                ) {
                    $imageCandidates[] = [
                        'url' => $ogImage,
                        'origin' =>
                        'open-graph',
                    ];
                }
            } catch (\Throwable) {
                //
            }
        }

        /*
        |--------------------------------------------------------------------------
        | API image evidence
        |--------------------------------------------------------------------------
        |
        | Preserve one useful representative per logical API image.
        |
        | The complete aio:images structure remains in the structured JSON
        | below, so we do not need to duplicate every resize as <img>.
        |
        */

        foreach (
            $this->preferredApiImages(
                $record,
                $detailPage->finalUrl
            )
            as $apiImage
        ) {
            $imageCandidates[] = [
                'url' => $apiImage,
                'origin' =>
                'allinone-api',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Collapse/dedupe image candidates
        |--------------------------------------------------------------------------
        */

        $imageCandidates =
            $this->collapseCandidateVariants(
                $imageCandidates
            );

        /*
        |--------------------------------------------------------------------------
        | Convenience imageUrl
        |--------------------------------------------------------------------------
        |
        | Validation affects imageUrl only.
        |
        */

        $image = null;
        $imageValidationReason = null;

        foreach (
            $imageCandidates as $candidate
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
        | Related documents
        |--------------------------------------------------------------------------
        |
        | This includes:
        |
        | - direct *.pdf URLs
        | - /media/document/ URLs
        | - /download/ URLs
        | - extensionless attachment endpoints
        |
        | Example:
        |
        | /in/rest/public/Attachment/attach_cmsUpload_...
        |
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
        | PDF evidence
        |--------------------------------------------------------------------------
        |
        | Extraction is best effort.
        |
        | Extensionless attachment URLs are intentionally sent through the PDF
        | extractor. The response itself determines whether PDF extraction can
        | succeed.
        |
        | A failed extraction never removes the original document link.
        |
        */

        $documentEvidence = [];
        $documentExtractionErrors = [];

        foreach (
            $documents as $document
        ) {
            try {
                $documentHost =
                    parse_url(
                        $document['url'],
                        PHP_URL_HOST
                    );

                $allowedHosts =
                    $this->allowedHosts(
                        $source,
                        $document['url']
                    );

                /*
                |--------------------------------------------------------------------------
                | External linked-document host
                |--------------------------------------------------------------------------
                |
                | Events can legitimately link to PDFs hosted by another official
                | organisation, such as bm-geneve.ch.
                |
                */

                if (
                    is_string($documentHost)
                    && $documentHost !== ''
                ) {
                    $allowedHosts =
                        array_values(
                            array_unique([
                                ...$allowedHosts,
                                $documentHost,
                            ])
                        );
                }

                $evidence =
                    $this->pdfs->extract(
                        $document['url'],
                        $allowedHosts
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
        | Structured evidence
        |--------------------------------------------------------------------------
        */

        $recordJson = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
        );

        $calendarJson = json_encode(
            $calendar,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
        );

        /*
        |--------------------------------------------------------------------------
        | raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml =
            '<article'
            . ' data-crawl-adapter="allinone-api"'
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
        |--------------------------------------------------------------------------
        | Primary rendered event evidence
        |--------------------------------------------------------------------------
        */

        if ($detailHtml !== '') {
            $rawHtml .=
                '<section data-crawl-source="event-content">'
                . $detailHtml
                . '</section>';
        } else {
            $rawHtml .=
                '<section data-crawl-source="api-fallback">'
                . '<h1>'
                . e(
                    $title
                        ?? 'Official event'
                )
                . '</h1>';

            if (
                $apiDescription !== null
            ) {
                $rawHtml .=
                    '<p>'
                    . e($apiDescription)
                    . '</p>';
            }

            $rawHtml .=
                '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Classified asset evidence
        |--------------------------------------------------------------------------
        */

        $rawHtml .=
            $this->assetEvidenceHtml(
                $classifiedAssets,
                $title
            );

        /*
        |--------------------------------------------------------------------------
        | Supplementary image evidence
        |--------------------------------------------------------------------------
        |
        | Do not repeat a URL already represented by classified assets.
        |
        */

        $classifiedAssetUrls =
            array_fill_keys(
                array_map(
                    static fn(
                        ClassifiedAsset $asset
                    ): string =>
                    $asset->url,

                    $classifiedAssets
                ),
                true
            );

        $supplementaryHtml = '';

        foreach (
            $imageCandidates as $candidate
        ) {
            if (
                isset(
                    $classifiedAssetUrls[$candidate['url']]
                )
            ) {
                continue;
            }

            $supplementaryHtml .=
                '<img'
                . ' src="'
                . e(
                    $candidate['url']
                )
                . '"'
                . ' alt="'
                . e(
                    $title
                        ?? ''
                )
                . '"'
                . ' data-crawl-role="event-image-candidate"'
                . ' data-crawl-asset-role="unknown"'
                . ' data-crawl-origin="'
                . e(
                    $candidate['origin']
                )
                . '"'
                . '>';
        }

        if ($supplementaryHtml !== '') {
            $rawHtml .=
                '<section data-crawl-source="supplementary-image-evidence">'
                . $supplementaryHtml
                . '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Document candidates
        |--------------------------------------------------------------------------
        |
        | Do not claim an extensionless attachment is definitely a PDF before
        | the actual response has been inspected.
        |
        */

        if ($documents !== []) {
            $rawHtml .=
                '<section data-crawl-source="document-candidates">';

            foreach (
                $documents as $document
            ) {
                $rawHtml .=
                    '<a'
                    . ' href="'
                    . e(
                        $document['url']
                    )
                    . '"'
                    . ' data-crawl-role="event-document"'
                    . ' data-crawl-document-type="'
                    . e(
                        $document['type']
                            ?? 'document'
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

            $rawHtml .=
                '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Extracted PDF text
        |--------------------------------------------------------------------------
        */

        foreach (
            $documentEvidence as $evidence
        ) {
            $rawHtml .=
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
                $rawHtml .=
                    '<h3 data-crawl-role="document-label">'
                    . e(
                        $evidence['label']
                    )
                    . '</h3>';
            }

            $rawHtml .=
                '<pre data-crawl-role="document-text">'
                . e(
                    $evidence['text']
                )
                . '</pre>';

            $rawHtml .=
                '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | AllInOne structured API payload
        |--------------------------------------------------------------------------
        */

        if (
            is_string($recordJson)
        ) {
            $rawHtml .=
                '<section data-crawl-source="structured-data">'
                . '<script type="application/json">'
                . $recordJson
                . '</script>'
                . '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Calendar payload
        |--------------------------------------------------------------------------
        */

        if (
            is_string($calendarJson)
            && $calendar !== []
        ) {
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
        | Result
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

                'document_extraction_errors' =>
                $documentExtractionErrors,
            ],

            imageValidationReason: $imageValidationReason,
        );
    }

    /**
     * Locate the event-specific part of the rendered page.
     */
    private function eventCrawler(
        Crawler $crawler,
        array $configuration
    ): ?Crawler {
        $selectors = [];

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
            $selectors as $selector
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
     * Keep semantic/non-hero evidence while preventing generic site artwork
     * from becoming an event image when aio:images tells us which media
     * actually belongs to this event.
     *
     * Assets matching aio:images are deterministic event media.
     *
     * @param list<ClassifiedAsset> $assets
     * @param list<string> $apiImageTokens
     *
     * @return list<ClassifiedAsset>
     */
    private function relevantAssets(
        array $assets,
        array $apiImageTokens
    ): array {
        $result = [];

        foreach ($assets as $asset) {
            /*
        |--------------------------------------------------------------------------
        | Preserve explicit non-hero semantic evidence
        |--------------------------------------------------------------------------
        */

            if (
                in_array(
                    $asset->role,
                    [
                        AssetRole::Accessibility,
                        AssetRole::PracticalInfo,
                        AssetRole::OrganizerBranding,
                        AssetRole::UiChrome,
                    ],
                    true
                )
            ) {
                $result[] = $asset;

                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Remove known generic AllInOne agenda artwork
        |--------------------------------------------------------------------------
        */

            if (
                $this->isGenericAgendaHero(
                    $asset->url
                )
            ) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | No structured image identity available
        |--------------------------------------------------------------------------
        |
        | Fall back to the shared classifier without inventing semantics.
        |
        */

            if ($apiImageTokens === []) {
                $result[] = $asset;

                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Ignore unrelated EventMedia / Unknown page artwork
        |--------------------------------------------------------------------------
        */

            if (
                ! $this->urlMatchesTokens(
                    $asset->url,
                    $apiImageTokens
                )
            ) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | aio:images is deterministic event-media evidence
        |--------------------------------------------------------------------------
        |
        | The rendered image URL belongs to an image explicitly assigned to
        | this event in the structured AllInOne record.
        |
        */

            if (
                $asset->role === AssetRole::EventMedia
            ) {
                $result[] = $asset;

                continue;
            }

            $result[] = new ClassifiedAsset(
                url: $asset->url,
                role: AssetRole::EventMedia,
                semanticKey: $asset->semanticKey,
                label: $asset->label,
                reason: 'allinone_api_image',
            );
        }

        return $result;
    }

    /**
     * @param list<ClassifiedAsset> $assets
     *
     * @return list<ClassifiedAsset>
     */
    private function collapseAssetVariants(
        array $assets
    ): array {
        $nonHeroAssets = [];
        $families = [];

        foreach (
            $assets as $asset
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
                $nonHeroAssets[] =
                    $asset;

                continue;
            }

            $key =
                $this->imageFamilyKey(
                    $asset->url
                );

            $score =
                $this->imageVariantScore(
                    $asset->url
                );

            if (
                ! isset(
                    $families[$key]
                )
                || $score
                > $families[$key]['score']
            ) {
                $families[$key] = [
                    'asset' => $asset,
                    'score' => $score,
                ];
            }
        }

        return [
            ...$nonHeroAssets,

            ...array_map(
                static fn(
                    array $item
                ): ClassifiedAsset =>
                $item['asset'],

                array_values(
                    $families
                )
            ),
        ];
    }

    /**
     * @param list<array{
     *     url:string,
     *     origin:string
     * }> $candidates
     *
     * @return list<array{
     *     url:string,
     *     origin:string
     * }>
     */
    private function collapseCandidateVariants(
        array $candidates
    ): array {
        $families = [];

        foreach (
            $candidates as $candidate
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

            $url = trim($url);

            $origin =
                (string) (
                    $candidate['origin']
                    ?? 'unknown'
                );

            /*
            | Source priority:
            |
            | rendered detail page
            | OpenGraph
            | structured API
            */

            $originScore =
                match ($origin) {
                    'event-detail-page' => 300,
                    'open-graph' => 200,
                    'allinone-api' => 100,
                    default => 0,
                };

            $key =
                $this->imageFamilyKey(
                    $url
                );

            $score =
                $originScore
                + $this->imageVariantScore(
                    $url
                );

            if (
                ! isset(
                    $families[$key]
                )
                || $score
                > $families[$key]['score']
            ) {
                $families[$key] = [
                    'score' =>
                    $score,

                    'candidate' => [
                        'url' =>
                        $url,

                        'origin' =>
                        $origin,
                    ],
                ];
            }
        }

        return array_values(
            array_map(
                static fn(
                    array $item
                ): array =>
                $item['candidate'],

                $families
            )
        );
    }

    /**
     * Return one useful representative per aio:images record.
     *
     * @return list<string>
     */
    private function preferredApiImages(
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

        foreach (
            $images as $image
        ) {
            if (
                ! is_array($image)
            ) {
                continue;
            }

            $formats =
                is_array(
                    $image['formats']
                        ?? null
                )
                ? $image['formats']
                : [];

            $preferredNames = [
                'zoom_16_10',
                'zoom_16_9',

                'hero_desktop',

                'big_16_10',
                'big_16_9',

                'normal_16_10',
                'normal_16_9',

                'medium_16_10',
                'medium_16_9',

                'summary_desktop_zoom',
                'summary_desktop',

                'medium_1_1',
            ];

            $candidate = null;

            foreach (
                $preferredNames as $name
            ) {
                $url =
                    $formats[$name]['url']
                    ?? null;

                if (
                    is_string($url)
                    && trim($url) !== ''
                ) {
                    $candidate = $url;

                    break;
                }
            }

            if ($candidate === null) {
                foreach (
                    $formats as $format
                ) {
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
     * Build tokens that identify images explicitly attached to the
     * AllInOne API event record.
     *
     * @return list<string>
     */
    private function apiImageIdentityTokens(
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

        $tokens = [];

        foreach (
            $images as $image
        ) {
            if (
                ! is_array($image)
            ) {
                continue;
            }

            foreach (
                [
                    $image['hash']
                        ?? null,

                    $image['name']
                        ?? null,

                    $image['fileName']
                        ?? null,
                ]
                as $token
            ) {
                if (
                    is_string($token)
                    && mb_strlen(
                        trim($token)
                    ) >= 6
                ) {
                    $tokens[] =
                        mb_strtolower(
                            trim($token)
                        );
                }
            }

            $extension =
                is_string(
                    $image['extension']
                        ?? null
                )
                ? trim(
                    $image['extension']
                )
                : '';

            $fileName =
                is_string(
                    $image['fileName']
                        ?? null
                )
                ? trim(
                    $image['fileName']
                )
                : '';

            if (
                $fileName !== ''
                && $extension !== ''
            ) {
                $tokens[] =
                    mb_strtolower(
                        $fileName
                            . '.'
                            . $extension
                    );
            }

            $formats =
                is_array(
                    $image['formats']
                        ?? null
                )
                ? $image['formats']
                : [];

            foreach (
                $formats as $format
            ) {
                if (
                    ! is_array($format)
                ) {
                    continue;
                }

                $url =
                    $format['url']
                    ?? null;

                if (
                    ! is_string($url)
                    || trim($url) === ''
                ) {
                    continue;
                }

                try {
                    $url =
                        $this->urls->absolute(
                            $url,
                            $baseUrl
                        );
                } catch (\Throwable) {
                    continue;
                }

                $basename =
                    basename(
                        (string) parse_url(
                            $url,
                            PHP_URL_PATH
                        )
                    );

                if (
                    mb_strlen(
                        $basename
                    ) >= 6
                ) {
                    $tokens[] =
                        mb_strtolower(
                            $basename
                        );
                }
            }
        }

        return array_values(
            array_unique(
                array_filter(
                    $tokens,
                    static fn(
                        string $token
                    ): bool =>
                    trim($token) !== ''
                )
            )
        );
    }

    /**
     * @param list<string> $tokens
     */
    private function urlMatchesTokens(
        string $url,
        array $tokens
    ): bool {
        $url =
            mb_strtolower(
                html_entity_decode(
                    $url,
                    ENT_QUOTES
                        | ENT_HTML5,
                    'UTF-8'
                )
            );

        foreach (
            $tokens as $token
        ) {
            if (
                $token !== ''
                && str_contains(
                    $url,
                    $token
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function isGenericAgendaHero(
        string $url
    ): bool {
        $url =
            mb_strtolower(
                $url
            );

        return str_contains(
            $url,
            'bg-hero-agenda'
        )
            || str_contains(
                $url,
                'hero-agenda'
            );
    }

    private function imageFamilyKey(
        string $url
    ): string {
        $decoded =
            html_entity_decode(
                $url,
                ENT_QUOTES
                    | ENT_HTML5,
                'UTF-8'
            );

        $path =
            (string) parse_url(
                $decoded,
                PHP_URL_PATH
            );

        $basename =
            mb_strtolower(
                basename($path)
            );

        /*
        | .webp versions of JPEG/PNG files represent the same logical image.
        */

        $basename =
            preg_replace(
                '/\.webp$/iu',
                '',
                $basename
            ) ?: $basename;

        $query =
            (string) parse_url(
                $decoded,
                PHP_URL_QUERY
            );

        /*
        | AllInOne uses an image hash in the query string.
        */

        return $basename
            . '|'
            . $query;
    }

    private function imageVariantScore(
        string $url
    ): int {
        $url =
            mb_strtolower(
                $url
            );

        return match (true) {
            str_contains(
                $url,
                '/raw_16_10/'
            ) => 100,

            str_contains(
                $url,
                '/raw_16_9/'
            ) => 99,

            str_contains(
                $url,
                '/zoom_16_10/'
            ) => 95,

            str_contains(
                $url,
                '/zoom_16_9/'
            ) => 94,

            str_contains(
                $url,
                '/hero_desktop_2x/'
            ) => 92,

            str_contains(
                $url,
                '/hero_desktop/'
            ) => 90,

            str_contains(
                $url,
                '/big_16_10/'
            ) => 80,

            str_contains(
                $url,
                '/big_16_9/'
            ) => 79,

            str_contains(
                $url,
                '/normal_16_10/'
            ) => 70,

            str_contains(
                $url,
                '/normal_16_9/'
            ) => 69,

            str_contains(
                $url,
                '/medium_16_10/'
            ) => 60,

            str_contains(
                $url,
                '/medium_16_9/'
            ) => 59,

            str_contains(
                $url,
                '/hero_tablet/'
            ) => 55,

            str_contains(
                $url,
                '/medium_1_1/'
            ) => 50,

            str_contains(
                $url,
                '/summary_desktop_zoom/'
            ) => 45,

            str_contains(
                $url,
                '/summary_desktop/'
            ) => 40,

            str_contains(
                $url,
                '/hero_mobile/'
            ) => 30,

            str_contains(
                $url,
                '/mini_16_10/'
            ) => 20,

            str_contains(
                $url,
                '/mini_16_9/'
            ) => 19,

            default => 10,
        };
    }

    /**
     * @param list<ClassifiedAsset> $assets
     */
    private function assetEvidenceHtml(
        array $assets,
        ?string $title
    ): string {
        if ($assets === []) {
            return '';
        }

        $html =
            '<section data-crawl-source="image-evidence">';

        foreach (
            $assets as $asset
        ) {
            $html .=
                '<img'
                . ' src="'
                . e($asset->url)
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
                        $asset
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
                    $asset->semanticKey
                )
                && trim(
                    $asset->semanticKey
                ) !== ''
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
                    $asset->reason
                )
                && trim(
                    $asset->reason
                ) !== ''
            ) {
                $html .=
                    ' data-crawl-classification-reason="'
                    . e(
                        $asset->reason
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
        ClassifiedAsset $asset
    ): string {
        return match ($asset->role) {
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
     * @param list<ClassifiedAsset> $assets
     *
     * @return list<array<string, mixed>>
     */
    private function assetMetadata(
        array $assets
    ): array {
        return array_map(
            static fn(
                ClassifiedAsset $asset
            ): array => [
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

            $assets
        );
    }

    /**
     * @return list<array{
     *     url:string,
     *     label:string,
     *     type:string
     * }>
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

            $href =
                html_entity_decode(
                    trim(
                        $node->getAttribute(
                            'href'
                        )
                    ),
                    ENT_QUOTES
                        | ENT_HTML5,
                    'UTF-8'
                );

            if ($href === '') {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Document detection
            |--------------------------------------------------------------------------
            |
            | Do not rely only on filename extensions.
            |
            | Several official sites expose documents through endpoints such as:
            |
            | /in/rest/public/Attachment/attach_cmsUpload_UUID
            |
            | The URL has no ".pdf", but the HTTP response can still be a PDF.
            |
            */

            $documentType =
                $this->documentTypeFromHref(
                    $href
                );

            if ($documentType === null) {
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

            $label =
                $this->cleanText(
                    $node->textContent
                );

            if (
                $label === null
                || trim($label) === ''
            ) {
                $label =
                    trim(
                        $node->getAttribute(
                            'title'
                        )
                    );
            }

            $documents[] = [
                'url' =>
                $url,

                'label' =>
                $label
                    ?? '',

                'type' =>
                $documentType,
            ];
        }

        return $documents;
    }

    /**
     * Determine whether a link is a probable event document.
     *
     * "pdf" means the URL itself explicitly identifies the resource as PDF.
     *
     * "document" means the resource is exposed through an attachment/download
     * endpoint and its real content type must be determined when fetched.
     */
    private function documentTypeFromHref(
        string $href
    ): ?string {
        $decoded =
            html_entity_decode(
                trim($href),
                ENT_QUOTES
                    | ENT_HTML5,
                'UTF-8'
            );

        if ($decoded === '') {
            return null;
        }

        if (
            preg_match(
                '/\.pdf(?:$|[?#])/iu',
                $decoded
            ) === 1
        ) {
            return 'pdf';
        }

        $lower =
            mb_strtolower(
                $decoded
            );

        $path =
            mb_strtolower(
                (string) parse_url(
                    $decoded,
                    PHP_URL_PATH
                )
            );

        /*
        |--------------------------------------------------------------------------
        | Known document endpoint patterns
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $path,
                '/media/document/'
            )
            || str_contains(
                $path,
                '/download/'
            )
            || str_contains(
                $path,
                '/downloads/'
            )
            || str_contains(
                $path,
                '/attachment/'
            )
            || str_contains(
                $path,
                '/attachments/'
            )
            || str_contains(
                $path,
                'attach_cmsupload_'
            )
            || str_contains(
                $lower,
                'download='
            )
        ) {
            return 'document';
        }

        return null;
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

            if (
                $node->count() === 0
            ) {
                return null;
            }

            $value =
                $node->attr(
                    $attribute
                );

            return is_string($value)
                && trim($value) !== ''
                ? trim($value)
                : null;
        } catch (\Throwable) {
            return null;
        }
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
            )?->endOfDay()
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
            ) ?: $value;

        $value =
            html_entity_decode(
                strip_tags($value),
                ENT_QUOTES
                    | ENT_HTML5,
                'UTF-8'
            );

        $value =
            preg_replace(
                '/[ \t]+/u',
                ' ',
                $value
            ) ?: $value;

        $value =
            preg_replace(
                '/ *\n */u',
                "\n",
                $value
            ) ?: $value;

        $value =
            trim(
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
                array_filter(
                    [
                        parse_url(
                            $source->listing_url,
                            PHP_URL_HOST
                        ),

                        parse_url(
                            $requestUrl,
                            PHP_URL_HOST
                        ),

                        ...$configured,
                    ],
                    'is_string'
                )
            )
        );
    }
}
