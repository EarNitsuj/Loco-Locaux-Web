<?php

namespace App\Crawling\Adapters;

use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\ParsedOccurrence;
use App\Crawling\Data\RawEvent;
use App\Crawling\Documents\PdfEvidenceExtractor;
use App\Crawling\Exceptions\CrawlerParseException;
use App\Models\EventSource;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A conservative shared crawler for official, server-rendered commune agendas.
 *
 * Source-specific selectors live in EventSource.configuration so the parser can
 * be reused without treating unrelated navigation links as events.
 */
class ConfiguredHtmlAgendaCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    /**
     * @return iterable<EventReference>
     */
    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $configuration = $this->configuration(
            $source
        );

        $url = $source->listing_url;

        $seenPages = [];
        $seenReferences = [];

        $references = [];

        $emptyPages = 0;
        $pageNumber = 0;

        while ($url !== null) {
            /*
            |--------------------------------------------------------------------------
            | Listing-page limit
            |--------------------------------------------------------------------------
            |
            | --all means natural source exhaustion.
            |
            | maxListingPages remains applicable only to bounded crawls.
            |
            */

            if (
                ! $options->all
                && $pageNumber >= $options->maxListingPages
            ) {
                break;
            }

            /*
            |--------------------------------------------------------------------------
            | Preserve pagination request URL
            |--------------------------------------------------------------------------
            |
            | Pagination URLs must not be canonicalized before requesting them.
            |
            | Some sources use query parameters as part of their pagination/AJAX
            | contract. Stripping or rewriting them can make subsequent batches
            | inaccessible.
            |
            */

            $requestUrl = html_entity_decode(
                trim($url),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($requestUrl === '') {
                break;
            }

            /*
            |--------------------------------------------------------------------------
            | Pagination loop protection
            |--------------------------------------------------------------------------
            */

            if (isset($seenPages[$requestUrl])) {
                break;
            }

            $seenPages[$requestUrl] = true;

            $fetched = $this->fetcher->get(
                $requestUrl,
                $this->allowedHosts(
                    $source,
                    $configuration
                )
            );

            $crawler = $this->crawler(
                $fetched->body,
                $fetched->finalUrl
            );

            /*
            |--------------------------------------------------------------------------
            | Discover current page / batch
            |--------------------------------------------------------------------------
            */

            $pageReferences = $this->discoverPage(
                $crawler,
                $fetched->finalUrl,
                $configuration
            );

            if ($pageReferences === []) {
                $emptyPages++;
            } else {
                $emptyPages = 0;
            }

            /*
            |--------------------------------------------------------------------------
            | Collect unique references
            |--------------------------------------------------------------------------
            */

            foreach ($pageReferences as $reference) {
                $hash = $reference->hash();

                if (isset($seenReferences[$hash])) {
                    continue;
                }

                $seenReferences[$hash] = true;

                $references[] = $reference;

                if (
                    ! $options->all
                    && $options->eventLimit !== null
                    && count($references) >= $options->eventLimit
                ) {
                    return $references;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Determine next page / load-more request
            |--------------------------------------------------------------------------
            */

            $nextUrl = $this->nextPageUrl(
                $crawler,
                $fetched->finalUrl,
                $configuration
            );

            /*
            |--------------------------------------------------------------------------
            | Natural exhaustion
            |--------------------------------------------------------------------------
            |
            | If the source no longer advertises a next page or load-more URL,
            | discovery is complete.
            |
            */

            if ($nextUrl === null) {
                break;
            }

            /*
            |--------------------------------------------------------------------------
            | Empty-page limit for bounded crawls
            |--------------------------------------------------------------------------
            |
            | A complete --all crawl relies on source exhaustion rather than the
            | generic empty-page limit.
            |
            */

            if (
                ! $options->all
                && $pageReferences === []
                && $emptyPages > $options->maxEmptyPages
            ) {
                break;
            }

            $nextUrl = html_entity_decode(
                trim($nextUrl),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($nextUrl === '') {
                break;
            }

            if (isset($seenPages[$nextUrl])) {
                break;
            }

            $url = $nextUrl;

            $pageNumber++;
        }

        return $references;
    }

    public function fetch(
        EventSource $source,
        EventReference $reference
    ): RawEvent {
        $configuration = $this->configuration(
            $source
        );

        $fetched = $this->fetcher->get(
            $reference->url,
            $this->allowedHosts(
                $source,
                $configuration
            )
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl
        );

        $jsonLd = $this->jsonLdEvent(
            $crawler
        );

        $canonical = $this->urls->canonical(
            (
                (
                    $configuration['prefer_reference_url_canonical']
                    ?? false
                )
                ? $reference->url
                : null
            )
                ?? $this->attr(
                    $crawler,
                    'link[rel="canonical"]',
                    'href'
                )
                ?? $this->attr(
                    $crawler,
                    'meta[property="og:url"]',
                    'content'
                )
                ?? $fetched->finalUrl,
        );

        $title =
            $this->string(
                $jsonLd['name'] ?? null
            )
            ?? (
                (
                    $configuration['prefer_listing_title']
                    ?? false
                )
                ? $reference->titleHint
                : null
            )
            ?? $this->text(
                $crawler,
                (string) (
                    $configuration['title_selector']
                    ?? 'main h1, article h1, h1'
                )
            )
            ?? $reference->titleHint;

        if ($title === null) {
            throw new CrawlerParseException(
                "No event title found for {$canonical}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Dates
        |--------------------------------------------------------------------------
        */

        $preferredListingOccurrence =
            ($configuration['prefer_listing_date'] ?? false)
            ? $this->dates->parseDateText(
                $reference->dateText ?? ''
            )
            : null;

        $startsAt =
            $preferredListingOccurrence?->startsAt
            ?? $this->parseDate(
                $this->string(
                    $jsonLd['startDate'] ?? null
                )
            );

        $endsAt =
            $preferredListingOccurrence?->endsAt
            ?? $this->parseDate(
                $this->string(
                    $jsonLd['endDate'] ?? null
                )
            );

        if (
            $startsAt !== null
            && $endsAt?->lessThan($startsAt)
        ) {
            $endsAt = null;
        }

        if ($startsAt === null) {
            $dateText = $this->text(
                $crawler,
                (string) (
                    $configuration['date_selector']
                    ?? 'time, .date, .event-date, .field--name-field-date'
                )
            )
                ?? $reference->dateText;

            $occurrence = $this->dates->parseDateText(
                $dateText ?? ''
            );

            $startsAt = $occurrence?->startsAt;

            $endsAt ??=
                $occurrence?->endsAt;
        }

        if (
            $startsAt !== null
            && $endsAt?->lessThan($startsAt)
        ) {
            $endsAt = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Image
        |--------------------------------------------------------------------------
        */

        $image = null;
        $classifiedAssets = [];
        $imageCandidates = [];

        /*
        |--------------------------------------------------------------------------
        | Description
        |--------------------------------------------------------------------------
        */

        $description =
            $this->string(
                $jsonLd['description'] ?? null
            )
            ?? $this->cleanBlockText(
                $crawler,
                (string) (
                    $configuration['description_selector']
                    ?? 'main article, main .field--name-body, main'
                )
            );

        $occurrences =
            $startsAt === null
            ? []
            : [
                new ParsedOccurrence(
                    $startsAt,
                    $endsAt
                ),
            ];

        /*
        |--------------------------------------------------------------------------
        | Primary raw event evidence
        |--------------------------------------------------------------------------
        */

        $eventContentHtml = $this->rawFragment(
            $crawler,
            (string) (
                $configuration['raw_selector']
                ?? 'main article, main'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Classified event assets
        |--------------------------------------------------------------------------
        |
        | Classification happens only inside the event-specific raw fragment.
        |
        | This prevents unrelated page images from becoming event hero candidates,
        | while preserving accessibility/practical/branding/UI images as evidence.
        |
        */

        $eventContentCrawler = null;

        if (trim($eventContentHtml) !== '') {
            try {
                $eventContentCrawler = new Crawler(
                    $eventContentHtml,
                    $fetched->finalUrl
                );

                $classifiedAssets = $this->classifiedAssets(
                    $eventContentCrawler,
                    $fetched->finalUrl
                );

                $imageCandidates = $this->eventImageCandidates(
                    $classifiedAssets
                );
            } catch (\Throwable) {
                $eventContentCrawler = null;
                $classifiedAssets = [];
                $imageCandidates = [];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Supplementary image candidates
        |--------------------------------------------------------------------------
        |
        | These sources are explicit event-image hints rather than arbitrary
        | images from the page.
        |
        */

        if (
            ! (
                $configuration['ignore_images']
                ?? false
            )
        ) {
            $supplementaryCandidates = [
                [
                    'url' =>
                    $this->imageFromJsonLd(
                        $jsonLd
                    ),

                    'origin' =>
                    'json-ld',
                ],

                [
                    'url' => (
                        $configuration['ignore_meta_image']
                        ?? false
                    )
                        ? null
                        : $this->attr(
                            $crawler,
                            'meta[property="og:image"]',
                            'content'
                        ),

                    'origin' =>
                    'open-graph',
                ],

                [
                    'url' =>
                    $this->attr(
                        $crawler,
                        (string) (
                            $configuration['image_selector']
                            ?? 'main article img, main img'
                        ),
                        'src'
                    ),

                    'origin' =>
                    'configured-selector',
                ],

                [
                    'url' =>
                    $reference->imageUrl,

                    'origin' =>
                    'listing',
                ],
            ];

            foreach ($supplementaryCandidates as $candidate) {
                $candidateUrl =
                    $candidate['url']
                    ?? null;

                if (
                    ! is_string($candidateUrl)
                    || trim($candidateUrl) === ''
                ) {
                    continue;
                }

                $imageCandidates[] = [
                    'url' =>
                    $candidateUrl,

                    'origin' =>
                    $candidate['origin'],
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Deduplicate image candidates
        |--------------------------------------------------------------------------
        */

        $imageCandidates = collect(
            $imageCandidates
        )
            ->filter(
                fn(mixed $candidate): bool =>
                is_array($candidate)
                    && is_string(
                        $candidate['url']
                            ?? null
                    )
                    && trim(
                        $candidate['url']
                    ) !== ''
            )
            ->unique(
                fn(array $candidate): string =>
                trim(
                    $candidate['url']
                )
            )
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Convenience imageUrl
        |--------------------------------------------------------------------------
        */

        if (
            ! (
                $configuration['ignore_images']
                ?? false
            )
        ) {
            foreach ($imageCandidates as $candidate) {
                $image = $this->images->validate(
                    $candidate['url'],
                    $fetched->finalUrl,
                    $source->listing_url,
                    $this->imageAllowedHosts(
                        $source,
                        $configuration
                    ),
                );

                if ($image !== null) {
                    break;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Linked PDF documents
        |--------------------------------------------------------------------------
        */

        $documents = [];
        $documentEvidence = [];

        $extractLinkedPdfs =
            ($configuration['extract_linked_pdfs'] ?? true)
            !== false;

        if (
            $extractLinkedPdfs
            && trim($eventContentHtml) !== ''
        ) {
            if ($eventContentCrawler !== null) {
                try {
                    $documents = $this->documentCandidates(
                        $eventContentCrawler,
                        $fetched->finalUrl
                    );
                } catch (\Throwable) {
                    $documents = [];
                }
            }

            /*
            | Avoid a pathological event page causing dozens of document
            | downloads.
            */

            $maxLinkedPdfs = max(
                0,
                (int) (
                    $configuration['max_linked_pdfs']
                    ?? 5
                )
            );

            if ($maxLinkedPdfs === 0) {
                $documents = [];
            } else {
                $documents = array_slice(
                    $documents,
                    0,
                    $maxLinkedPdfs
                );
            }

            if ($documents !== []) {
                $pdfs = app(
                    PdfEvidenceExtractor::class
                );

                foreach ($documents as $document) {
                    try {
                        $evidence = $pdfs->extract(
                            $document['url'],
                            $this->allowedHosts(
                                $source,
                                $configuration
                            )
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
                        /*
                        | A PDF failure must never discard the event itself.
                        */
                        continue;
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | JSON-LD
        |--------------------------------------------------------------------------
        */

        $jsonLdContent =
            $jsonLd !== []
            ? json_encode(
                $jsonLd,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRETTY_PRINT
            )
            : null;

        /*
        |--------------------------------------------------------------------------
        | Classified image evidence
        |--------------------------------------------------------------------------
        */

        $imageCandidateHtml = $this->assetEvidenceHtml(
            $classifiedAssets,
            $title
        );

        $classifiedAssetUrls = array_fill_keys(
            array_map(
                static fn($asset): string =>
                $asset->url,
                $classifiedAssets
            ),
            true
        );

        foreach ($imageCandidates as $candidate) {
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

            $imageCandidateHtml .=
                '<section data-crawl-source="supplementary-image-evidence">'
                . '<img'
                . ' src="'
                . e($candidateUrl)
                . '"'
                . ' alt="'
                . e($title)
                . '"'
                . ' data-crawl-role="event-image-candidate"'
                . ' data-crawl-asset-role="unknown"'
                . ' data-crawl-origin="'
                . e(
                    $candidate['origin']
                        ?? 'unknown'
                )
                . '"'
                . '>'
                . '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Explicit document candidate links
        |--------------------------------------------------------------------------
        */

        $documentCandidateHtml = '';

        if ($documents !== []) {
            $documentCandidateHtml =
                '<section data-crawl-source="document-candidates">';

            foreach ($documents as $document) {
                $documentCandidateHtml .=
                    '<a'
                    . ' href="'
                    . e(
                        $document['url']
                    )
                    . '"'
                    . ' data-crawl-role="event-document"'
                    . ' data-crawl-document-type="pdf"'
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
        | Extracted PDF evidence
        |--------------------------------------------------------------------------
        */

        $documentEvidenceHtml = '';

        foreach ($documentEvidence as $evidence) {
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
        | Final raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml =
            '<article>'
            . '<section data-crawl-source="event-content">'
            . $eventContentHtml
            . '</section>'
            . $imageCandidateHtml
            . $documentCandidateHtml
            . $documentEvidenceHtml;

        if (
            is_string(
                $jsonLdContent
            )
        ) {
            $rawHtml .=
                '<section data-crawl-source="structured-data">'
                . '<script type="application/ld+json">'
                . $jsonLdContent
                . '</script>'
                . '</section>';
        }

        $rawHtml .= '</article>';

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        |
        | Do NOT derive startsAt/endsAt from PDF text here.
        |
        | The crawler's responsibility is evidence capture.
        | Downstream normalization interprets PDF dates.
        |
        */

        return new RawEvent(
            canonicalUrl: $canonical,

            rawHtml: $rawHtml,

            externalId: $this->externalIdFromStructuredData(
                $jsonLd
            )
                ?? $this->externalIdFromDetail(
                    $crawler,
                    $configuration
                )
                ?? $reference->externalId,

            title: $title,

            startsAt: $startsAt,

            endsAt: $endsAt,

            isRecurring: (bool) (
                $configuration['is_recurring']
                ?? false
            ),

            recurrenceSummary: $this->text(
                $crawler,
                (string) (
                    $configuration['recurrence_selector']
                    ?? '.recurrence, .event-recurrence'
                )
            ),

            description: $description,

            imageUrl: $image,

            sourceLanguage: (string) (
                $configuration['source_language']
                ?? 'fr'
            ),

            sourcePublishedAt: $this->parseDate(
                $this->attr(
                    $crawler,
                    'meta[property="article:published_time"]',
                    'content'
                )
            ),

            occurrences: $occurrences,

            metadata: [
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

                        'text_extracted' =>
                        true,
                    ],
                    $documentEvidence
                ),
            ],

            canonicalDescriptionHtml: $this->cleanBlockHtml(
                $crawler,
                (string) (
                    $configuration['description_selector']
                    ?? 'main article, main .field--name-body, main'
                ),
                $fetched->finalUrl,
            ),

            imageValidationReason: $this->images
                ->lastResult()
                ?->reason,
        );
    }

    /**
     * Find PDF links belonging to this event.
     *
     * @return list<array{
     *     url:string,
     *     label:string
     * }>
     */
    private function documentCandidates(
        Crawler $crawler,
        string $baseUrl
    ): array {
        $documents = [];
        $seen = [];

        foreach (
            $crawler->filter('a[href]')
            as $node
        ) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = html_entity_decode(
                trim(
                    $node->getAttribute(
                        'href'
                    )
                ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($href === '') {
                continue;
            }

            if (
                preg_match(
                    '/\.pdf(?:$|[?#])/iu',
                    $href
                ) !== 1
            ) {
                continue;
            }

            try {
                $url = $this->urls->absolute(
                    $href,
                    $baseUrl
                );
            } catch (\Throwable) {
                continue;
            }

            $url = $this->urls->canonical(
                $url
            );

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $label = $this->string(
                $node->textContent
            );

            if ($label === null) {
                $label = $this->string(
                    $node->getAttribute(
                        'title'
                    )
                );
            }

            $documents[] = [
                'url' =>
                $url,

                'label' =>
                $label ?? '',
            ];
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<EventReference>
     */
    private function discoverPage(
        Crawler $crawler,
        string $baseUrl,
        array $configuration
    ): array {
        $references = [];

        $itemSelector = (string) (
            $configuration['listing_item_selector']
            ?? 'main article, main .event, main .views-row'
        );

        foreach (
            $crawler->filter(
                $itemSelector
            )
            as $item
        ) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $itemCrawler = new Crawler(
                $item,
                $baseUrl
            );

            $link =
                strtolower($item->nodeName) === 'a'
                ? $item->getAttribute(
                    'href'
                )
                : $this->attr(
                    $itemCrawler,
                    (string) (
                        $configuration['event_link_selector']
                        ?? 'a[href]'
                    ),
                    'href'
                );

            if ($link === null) {
                continue;
            }

            if (
                ! $this->isHttpOrRelativeLink(
                    $link
                )
            ) {
                continue;
            }

            $url = $this->urls->absolute(
                $link,
                $baseUrl
            );

            if (
                $this->isListingVariant(
                    $url,
                    $baseUrl,
                    $configuration
                )
                || ! $this->isEventUrl(
                    $url,
                    $configuration
                )
            ) {
                continue;
            }

            $externalId =
                $item->getAttribute(
                    'data-event-id'
                )
                ?: $item->getAttribute(
                    'data-id'
                )
                ?: $this->attr(
                    $itemCrawler,
                    '[data-event-id]',
                    'data-event-id'
                )
                ?? $this->attr(
                    $itemCrawler,
                    '[data-id]',
                    'data-id'
                )
                ?? $this->externalIdFromQuery(
                    $url,
                    $configuration
                )
                ?? $this->externalIdFromUrl(
                    $url
                );

            $references[] =
                new EventReference(
                    url: $url,

                    externalId: $externalId,

                    listingUrl: $baseUrl,

                    titleHint: $this->text(
                        $itemCrawler,
                        (string) (
                            $configuration['listing_title_selector']
                            ?? 'h2, h3, a'
                        )
                    )
                        ?? $this->nodeText(
                            $item
                        ),

                    dateText: $this->text(
                        $itemCrawler,
                        (string) (
                            $configuration['listing_date_selector']
                            ?? 'time, .date, .event-date'
                        )
                    ),

                    imageUrl: $this->listingImage(
                        $itemCrawler,
                        $baseUrl,
                        $configuration
                    ),
                );
        }

        return $references;
    }

    /**
     * Resolve the next listing page or explicitly configured load-more request.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function nextPageUrl(
        Crawler $crawler,
        string $baseUrl,
        array $configuration
    ): ?string {
        /*
        |--------------------------------------------------------------------------
        | Explicit source-specific normal pagination
        |--------------------------------------------------------------------------
        |
        | If a source defines next_page_selector, it takes precedence over the
        | generic fallback.
        |
        */

        $configuredNextSelector =
            $configuration['next_page_selector']
            ?? null;

        if (
            is_string($configuredNextSelector)
            && trim($configuredNextSelector) !== ''
        ) {
            $url = $this->paginationUrlFromSelector(
                $crawler,
                trim($configuredNextSelector),
                (string) (
                    $configuration['next_page_attribute']
                    ?? 'href'
                ),
                $baseUrl
            );

            if ($url !== null) {
                return $url;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Explicit source-specific load-more pagination
        |--------------------------------------------------------------------------
        |
        | This behavior is opt-in.
        |
        | A shared configured-html-agenda source must explicitly define:
        |
        | load_more_selector
        |
        | Optionally:
        |
        | load_more_attribute
        |
        | Example:
        |
        | 'load_more_selector' =>
        |     '#load-more a.load-more[data-url]',
        |
        | 'load_more_attribute' =>
        |     'data-url',
        |
        | No generic .load-more / data-url assumptions are made for unrelated
        | communes.
        |
        */

        $loadMoreSelector =
            $configuration['load_more_selector']
            ?? null;

        if (
            is_string($loadMoreSelector)
            && trim($loadMoreSelector) !== ''
        ) {
            $url = $this->paginationUrlFromSelector(
                $crawler,
                trim($loadMoreSelector),
                (string) (
                    $configuration['load_more_attribute']
                    ?? 'data-url'
                ),
                $baseUrl
            );

            if ($url !== null) {
                return $url;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Generic conventional pagination fallback
        |--------------------------------------------------------------------------
        |
        | This retains the original behavior for configured-html-agenda sources
        | which use normal server-rendered next-page links.
        |
        */

        return $this->paginationUrlFromSelector(
            $crawler,
            'a[rel="next"], .pager__item--next a, .pagination .next a',
            'href',
            $baseUrl
        );
    }

    private function paginationUrlFromSelector(
        Crawler $crawler,
        string $selector,
        string $attribute,
        string $baseUrl
    ): ?string {
        try {
            $nodes = $crawler->filter(
                $selector
            );
        } catch (\Throwable) {
            return null;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $value = html_entity_decode(
                trim(
                    $node->getAttribute(
                        $attribute
                    )
                ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($value === '') {
                continue;
            }

            if (
                ! $this->isHttpOrRelativeLink(
                    $value
                )
            ) {
                continue;
            }

            try {
                /*
                | Resolve relative URLs but deliberately do not canonicalize the
                | result.
                |
                | Pagination/AJAX query parameters can be significant.
                */
                return $this->urls->absolute(
                    $value,
                    $baseUrl
                );
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function isEventUrl(
        string $url,
        array $configuration
    ): bool {
        $fragments =
            $configuration['event_path_contains']
            ?? [
                'agenda',
                'evenement',
                'evenements',
                'event',
            ];

        if (
            ! is_array($fragments)
            || $fragments === []
        ) {
            return true;
        }

        $path = strtolower(
            (string) parse_url(
                $url,
                PHP_URL_PATH
            )
        );

        return collect(
            $fragments
        )
            ->filter(
                fn(mixed $fragment): bool =>
                is_string($fragment)
                    && $fragment !== ''
            )
            ->contains(
                fn(string $fragment): bool =>
                str_contains(
                    $path,
                    strtolower($fragment)
                )
            );
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function isListingVariant(
        string $url,
        string $baseUrl,
        array $configuration
    ): bool {
        if (
            $this->urls->canonical(
                $url
            )
            ===
            $this->urls->canonical(
                $baseUrl
            )
        ) {
            return true;
        }

        $excluded =
            $configuration['exclude_url_fragments']
            ?? ['archives='];

        return collect(
            is_array($excluded)
                ? $excluded
                : []
        )
            ->filter(
                fn(mixed $fragment): bool =>
                is_string($fragment)
            )
            ->contains(
                fn(string $fragment): bool =>
                str_contains(
                    strtolower($url),
                    strtolower($fragment)
                )
            );
    }

    private function isHttpOrRelativeLink(
        string $link
    ): bool {
        $scheme = strtolower(
            (string) parse_url(
                $link,
                PHP_URL_SCHEME
            )
        );

        return $scheme === ''
            || in_array(
                $scheme,
                ['http', 'https'],
                true
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(
        EventSource $source
    ): array {
        return is_array(
            $source->configuration
        )
            ? $source->configuration
            : [];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private function allowedHosts(
        EventSource $source,
        array $configuration
    ): array {
        $host = parse_url(
            $source->listing_url,
            PHP_URL_HOST
        );

        $configured =
            $configuration['allowed_hosts']
            ?? [];

        return array_values(
            array_unique(
                array_filter([
                    is_string($host)
                        ? $host
                        : null,

                    ...is_array($configured)
                        ? $configured
                        : [],
                ], 'is_string')
            )
        );
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private function imageAllowedHosts(
        EventSource $source,
        array $configuration
    ): array {
        $configured =
            $configuration['image_allowed_hosts']
            ?? [];

        return array_values(
            array_unique([
                ...$this->allowedHosts(
                    $source,
                    $configuration
                ),

                ...is_array($configured)
                    ? array_values(
                        array_filter(
                            $configured,
                            'is_string'
                        )
                    )
                    : [],
            ])
        );
    }

    private function string(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(
            strip_tags(
                $value
            )
        );

        return $value === ''
            ? null
            : html_entity_decode(
                $value,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
    }

    private function nodeText(
        DOMElement $node
    ): ?string {
        return $this->string(
            $node->textContent
        );
    }

    private function externalIdFromUrl(
        string $url
    ): ?string {
        return preg_match(
            '/-(\d+)(?:\/)?$/',
            (string) parse_url(
                $url,
                PHP_URL_PATH
            ),
            $matches
        ) === 1
            ? $matches[1]
            : null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function externalIdFromDetail(
        Crawler $crawler,
        array $configuration
    ): ?string {
        $selector = (string) (
            $configuration['external_id_selector']
            ?? 'article[id^="post-"]'
        );

        $attribute = (string) (
            $configuration['external_id_attribute']
            ?? 'id'
        );

        $value = $this->attr(
            $crawler,
            $selector,
            $attribute
        );

        if ($value === null) {
            return null;
        }

        $pattern = (string) (
            $configuration['external_id_pattern']
            ?? '/(?:post-)?(\d+)/'
        );

        return @preg_match(
            $pattern,
            $value,
            $match
        ) === 1
            ? (
                $match[1]
                ?? $match[0]
            )
            : null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function listingImage(
        Crawler $crawler,
        string $baseUrl,
        array $configuration
    ): ?string {
        $image = $this->attr(
            $crawler,
            (string) (
                $configuration['listing_image_selector']
                ?? 'img'
            ),
            (string) (
                $configuration['listing_image_attribute']
                ?? 'src'
            ),
        );

        return $image === null
            ? null
            : $this->urls->absolute(
                $image,
                $baseUrl
            );
    }

    /**
     * @param  array<string, mixed>  $jsonLd
     */
    private function externalIdFromStructuredData(
        array $jsonLd
    ): ?string {
        $id =
            $jsonLd['@id']
            ?? null;

        return is_string($id)
            && preg_match(
                '/\/(\d{5,})(?:\D|$)/',
                $id,
                $match
            ) === 1
            ? $match[1]
            : null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function externalIdFromQuery(
        string $url,
        array $configuration
    ): ?string {
        $parameter =
            $configuration['external_id_query_parameter']
            ?? null;

        if (
            ! is_string($parameter)
            || $parameter === ''
        ) {
            return null;
        }

        parse_str(
            (string) parse_url(
                $url,
                PHP_URL_QUERY
            ),
            $query
        );

        $value =
            $query[$parameter]
            ?? null;

        if (
            ! is_string($value)
            || $value === ''
        ) {
            return null;
        }

        if (
            (
                $configuration['external_id_query_base64']
                ?? false
            )
            === true
        ) {
            $decoded = base64_decode(
                $value,
                true
            );

            if (
                is_string($decoded)
                && $decoded !== ''
            ) {
                $pattern =
                    $configuration['external_id_query_pattern']
                    ?? null;

                if (
                    is_string($pattern)
                    && @preg_match(
                        $pattern,
                        $decoded,
                        $match
                    ) === 1
                ) {
                    return $match[1]
                        ?? $match[0];
                }
            }
        }

        return substr(
            hash(
                'sha256',
                $value
            ),
            0,
            24
        );
    }
}
