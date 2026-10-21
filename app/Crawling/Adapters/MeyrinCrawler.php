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

class MeyrinCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    /**
     * @return iterable<EventReference>
     */
    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $url = $source->listing_url;

        $seenPages = [];
        $seenReferences = [];

        $emptyPages = 0;
        $pageNumber = 0;

        $references = [];

        while ($url !== null) {
            /*
            |--------------------------------------------------------------------------
            | Listing-page limit
            |--------------------------------------------------------------------------
            |
            | A complete --all crawl must continue until Meyrin's own pagination
            | is naturally exhausted.
            |
            | maxListingPages remains useful only for intentionally bounded
            | crawls.
            |
            */

            if (
                ! $options->all
                && $pageNumber >= $options->maxListingPages
            ) {
                break;
            }

            $url = $this->urls->canonical(
                $url
            );

            /*
            |--------------------------------------------------------------------------
            | Pagination-loop protection
            |--------------------------------------------------------------------------
            */

            if (isset($seenPages[$url])) {
                break;
            }

            $seenPages[$url] = true;

            $fetched = $this->fetcher->get(
                $url,
                ['meyrin.ch']
            );

            $crawler = $this->crawler(
                $fetched->body,
                $fetched->finalUrl
            );

            $pageReferences = $this->discoverPage(
                $crawler,
                $fetched->finalUrl
            );

            /*
            |--------------------------------------------------------------------------
            | Empty-page handling
            |--------------------------------------------------------------------------
            |
            | For --all, an empty intermediate page must not automatically mean
            | the source is exhausted if the page still exposes a valid next
            | page.
            |
            | Natural exhaustion is determined by nextPageUrl().
            |
            */

            if ($pageReferences === []) {
                $emptyPages++;

                if (
                    ! $options->all
                    && $emptyPages > $options->maxEmptyPages
                ) {
                    break;
                }
            } else {
                $emptyPages = 0;
            }

            /*
            |--------------------------------------------------------------------------
            | Collect unique event references
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
            | Natural pagination exhaustion
            |--------------------------------------------------------------------------
            |
            | Follow Meyrin's next/load-more page until the source stops
            | providing another forward page.
            |
            */

            $nextUrl = $this->nextPageUrl(
                $crawler,
                $fetched->finalUrl
            );

            if ($nextUrl === null) {
                break;
            }

            $nextUrl = $this->urls->canonical(
                $nextUrl
            );

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
        $allowedHosts = ['meyrin.ch'];

        $fetched = $this->fetcher->get(
            $reference->url,
            $allowedHosts
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl
        );

        $jsonLd = $this->jsonLdEvent(
            $crawler
        );

        /*
        |--------------------------------------------------------------------------
        | Canonical URL
        |--------------------------------------------------------------------------
        */

        $canonical = $this->urls->canonical(
            $this->attr(
                $crawler,
                'link[rel="canonical"]',
                'href'
            )
                ?? $this->attr(
                    $crawler,
                    'meta[property="og:url"]',
                    'content'
                )
                ?? $fetched->finalUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Dates / occurrences
        |--------------------------------------------------------------------------
        */

        $dateTexts = array_merge(
            is_array(
                $reference->metadata['occurrence_texts'] ?? null
            )
                ? $reference->metadata['occurrence_texts']
                : [],

            $crawler
                ->filter(
                    '.prog_label, .content-block-date, time, .date'
                )
                ->each(
                    fn($node): string => $node->text('')
                ),
        );

        $occurrences = $this->occurrences(
            $crawler
        );

        if ($occurrences === []) {
            $occurrences = $this->dates->parseMany(
                $dateTexts
            );
        }

        $overallRange =
            $this->overallRange($crawler)
            ?? $this->dates->parseDateText(
                $reference->dateText
            );

        $mainOccurrence =
            $overallRange
            ?? $occurrences[0]
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Title
        |--------------------------------------------------------------------------
        */

        $title =
            is_string($jsonLd['name'] ?? null)
            ? trim($jsonLd['name'])
            : null;

        $title ??=
            $this->text(
                $crawler,
                'h1, .page-title'
            )
            ?? $reference->titleHint;

        /*
        |--------------------------------------------------------------------------
        | Primary event evidence
        |--------------------------------------------------------------------------
        */

        $raw = $this->rawFragment(
            $crawler,
            '.page-node-type-evenements main .main--content, main article, .main--content'
        );

        if (trim($raw) === '') {
            throw new CrawlerParseException(
                'Unable to isolate Meyrin event fragment.'
            );
        }

        $eventCrawler = new Crawler(
            $raw,
            $fetched->finalUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Classified event assets
        |--------------------------------------------------------------------------
        |
        | Every image contained in the isolated event fragment survives as
        | evidence.
        |
        | Only event_media + unknown enter the hero-image candidate pool.
        |
        */

        $classifiedAssets = $this->classifiedAssets(
            $eventCrawler,
            $fetched->finalUrl
        );

        $imageCandidates = $this->eventImageCandidates(
            $classifiedAssets
        );

        /*
        |--------------------------------------------------------------------------
        | Supplementary image candidates
        |--------------------------------------------------------------------------
        */

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
                'url' =>
                $this->attr(
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
                    'img.main--content-image',
                    'src'
                ),

                'origin' =>
                'event-main-image',
            ],

            [
                'url' =>
                $this->attr(
                    $crawler,
                    '.main--content img, article img',
                    'src'
                ),

                'origin' =>
                'event-content-selector',
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

            try {
                $candidateUrl = $this->urls->absolute(
                    $candidateUrl,
                    $fetched->finalUrl
                );
            } catch (\Throwable) {
                continue;
            }

            $imageCandidates[] = [
                'url' =>
                $candidateUrl,

                'origin' =>
                $candidate['origin'],
            ];
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
                        $candidate['url'] ?? null
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

        $image = null;
        $imageValidationReason = null;

        foreach ($imageCandidates as $candidate) {
            try {
                $validated = $this->images->validate(
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
        | Linked PDF documents
        |--------------------------------------------------------------------------
        */

        $documents = $this->documentCandidates(
            $eventCrawler,
            $fetched->finalUrl
        );

        $documents = array_slice(
            $documents,
            0,
            5
        );

        $documentEvidence = [];

        if ($documents !== []) {
            $pdfs = app(
                PdfEvidenceExtractor::class
            );

            foreach ($documents as $document) {
                try {
                    $documentHost = parse_url(
                        $document['url'],
                        PHP_URL_HOST
                    );

                    $documentAllowedHosts = array_values(
                        array_unique([
                            ...$allowedHosts,

                            ...(
                                is_string($documentHost)
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
                    /*
                    | Best effort only.
                    |
                    | The PDF link remains present in raw_html even when its
                    | contents cannot be downloaded or converted to text.
                    */
                    continue;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Classified image evidence
        |--------------------------------------------------------------------------
        */

        $imageEvidenceHtml = $this->assetEvidenceHtml(
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

        /*
        |--------------------------------------------------------------------------
        | Supplementary image evidence
        |--------------------------------------------------------------------------
        */

        $supplementaryImageHtml = '';

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
        | Explicit PDF/document candidate links
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
        | JSON-LD evidence
        |--------------------------------------------------------------------------
        */

        $structuredDataHtml = '';

        if ($jsonLd !== []) {
            $json = json_encode(
                $jsonLd,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRETTY_PRINT
            );

            if (is_string($json)) {
                $structuredDataHtml =
                    '<section data-crawl-source="structured-data">'
                    . '<script type="application/ld+json">'
                    . $json
                    . '</script>'
                    . '</section>';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Final raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml =
            '<article data-crawl-adapter="meyrin">'
            . '<section data-crawl-source="event-content">'
            . $raw
            . '</section>'
            . $imageEvidenceHtml
            . $supplementaryImageHtml
            . $documentCandidateHtml
            . $documentEvidenceHtml
            . $structuredDataHtml
            . '</article>';

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        */

        return new RawEvent(
            canonicalUrl: $canonical,

            rawHtml: $rawHtml,

            externalId: $reference->externalId
                ?? $this->externalId(
                    $fetched->body
                ),

            title: $title,

            startsAt: $this->parseDate(
                $jsonLd['startDate'] ?? null
            )
                ?? $mainOccurrence?->startsAt,

            endsAt: $this->parseDate(
                $jsonLd['endDate'] ?? null
            )
                ?? $mainOccurrence?->endsAt,

            isRecurring: count($occurrences) > 1,

            recurrenceSummary: count($occurrences) > 1
                ? (
                    $this->rangeText($crawler)
                    ?? $reference->dateText
                )
                : null,

            description: (
                is_string(
                    $jsonLd['description'] ?? null
                )
                ? $jsonLd['description']
                : null
            )
                ?? $this->attr(
                    $crawler,
                    'meta[name="description"]',
                    'content'
                )
                ?? $this->blockText(
                    $crawler,
                    '.main--content-text-intro, .main--content-body, .field--name-body, .main--content'
                ),

            imageUrl: $image,

            sourceLanguage: 'fr',

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
            ],

            canonicalDescriptionHtml: $this->cleanBlockHtml(
                $crawler,
                '.main--content-text-intro, .main--content-body, .field--name-body',
                $fetched->finalUrl
            ),

            imageValidationReason: $imageValidationReason,
        );
    }

    /**
     * @return list<EventReference>
     */
    private function discoverPage(
        Crawler $crawler,
        string $pageUrl
    ): array {
        return collect(
            $crawler
                ->filter(
                    '.events_container[data-event-id], [data-event-id].events_container'
                )
                ->each(
                    function (
                        $node
                    ) use (
                        $pageUrl
                    ): ?EventReference {
                        $href = $this->attr(
                            $node,
                            '.evt_node_content a[href], a.btn[href], a[href*="/fr/agenda/"]',
                            'href'
                        );

                        if ($href === null) {
                            return null;
                        }

                        $image = $this->attr(
                            $node,
                            'img',
                            'src'
                        );

                        if (
                            is_string($image)
                            && (
                                str_contains(
                                    mb_strtolower($image),
                                    '/themes/custom/meyrin/'
                                )
                                || str_contains(
                                    mb_strtolower($image),
                                    'enfant-blanc.svg'
                                )
                            )
                        ) {
                            $image = null;
                        }

                        $occurrenceTexts =
                            $node
                            ->filter(
                                '.prog_date_container .prog_label, .prog_label'
                            )
                            ->each(
                                fn($dateNode): string =>
                                $dateNode->text('')
                            );

                        return new EventReference(
                            url: $this->urls->canonical(
                                $this->urls->absolute(
                                    $href,
                                    $pageUrl
                                )
                            ),

                            externalId: $node->attr(
                                'data-event-id'
                            )
                                ?: null,

                            listingUrl: $pageUrl,

                            titleHint: $this->text(
                                $node,
                                '.card-title, h2, h3'
                            ),

                            dateText: $this->text(
                                $node,
                                '.content-block-date, .date'
                            ),

                            imageUrl: $image
                                ? $this->urls->absolute(
                                    $image,
                                    $pageUrl
                                )
                                : null,

                            metadata: [
                                'occurrence_texts' =>
                                $occurrenceTexts,
                            ],
                        );
                    }
                )
        )
            ->filter()
            ->values()
            ->all();
    }

    private function nextPageUrl(
        Crawler $crawler,
        string $pageUrl
    ): ?string {
        /*
        |--------------------------------------------------------------------------
        | Meyrin pagination
        |--------------------------------------------------------------------------
        |
        | Prefer an explicit rel="next" / Drupal next control when available.
        |
        | Generic pagers often contain previous, current and future page links.
        | We therefore collect only links whose ?page=N value is greater than
        | the current page, and then choose the nearest forward page.
        |
        */

        $selectors = [
            'a[rel="next"][href]',
            '.pager__item--next a[href]',
            '.js-pager__items a[href*="page="]',
            '.pager a[href*="page="]',
        ];

        $currentPage = $this->pageNumberFromUrl(
            $pageUrl
        );

        $forwardCandidates = [];

        foreach ($selectors as $selector) {
            $nodes = $crawler->filter(
                $selector
            );

            foreach ($nodes as $node) {
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

                try {
                    $absolute = $this->urls->absolute(
                        $href,
                        $pageUrl
                    );
                } catch (\Throwable) {
                    continue;
                }

                $rel = mb_strtolower(
                    trim(
                        $node->getAttribute(
                            'rel'
                        )
                    )
                );

                /*
                | Explicit rel="next" is authoritative.
                */

                if (
                    preg_match(
                        '/(?:^|\s)next(?:$|\s)/u',
                        $rel
                    ) === 1
                ) {
                    return $absolute;
                }

                $candidatePage = $this->pageNumberFromUrl(
                    $absolute
                );

                if ($candidatePage <= $currentPage) {
                    continue;
                }

                $forwardCandidates[$candidatePage] = $absolute;
            }

            /*
            | Once a more-specific selector has yielded a valid forward page,
            | do not fall through to broader pager selectors.
            */

            if ($forwardCandidates !== []) {
                break;
            }
        }

        if ($forwardCandidates === []) {
            return null;
        }

        /*
        | Select the nearest forward page:
        |
        | page 0 → page 1
        | page 1 → page 2
        | page 2 → page 3
        */

        ksort(
            $forwardCandidates,
            SORT_NUMERIC
        );

        $next = reset(
            $forwardCandidates
        );

        return is_string($next)
            && $next !== ''
            ? $next
            : null;
    }

    private function pageNumberFromUrl(
        string $url
    ): int {
        $query = parse_url(
            $url,
            PHP_URL_QUERY
        );

        if (
            ! is_string($query)
            || $query === ''
        ) {
            /*
            | Meyrin's initial /fr/agenda page is Drupal page 0.
            */
            return 0;
        }

        parse_str(
            $query,
            $parameters
        );

        $page = $parameters['page']
            ?? null;

        if (
            is_int($page)
            || (
                is_string($page)
                && ctype_digit($page)
            )
        ) {
            return max(
                0,
                (int) $page
            );
        }

        return 0;
    }

    private function externalId(
        string $body
    ): ?string {
        return preg_match(
            '/currentPath":"node\\\\\/(\d+)/',
            $body,
            $match
        ) === 1
            ? $match[1]
            : null;
    }

    private function overallRange(
        Crawler $crawler
    ): ?ParsedOccurrence {
        return $this->dates->parseDateText(
            $this->rangeText($crawler)
        );
    }

    private function rangeText(
        Crawler $crawler
    ): ?string {
        $eventDates = $crawler
            ->filter(
                '.event-dates-list'
            )
            ->first();

        if ($eventDates->count() > 0) {
            $parts =
                $eventDates
                ->filter(
                    '.event-dates-item'
                )
                ->each(
                    function (
                        Crawler $node
                    ): string {
                        $label =
                            $this->text(
                                $node,
                                '.event-dates-label'
                            );

                        $value =
                            $this->text(
                                $node,
                                '.event-dates-value'
                            );

                        return trim(
                            implode(
                                ' ',
                                array_filter([
                                    $label,
                                    $value,
                                ])
                            )
                        );
                    }
                );

            $text = trim(
                implode(
                    ' ',
                    $parts
                )
            );

            if ($text !== '') {
                return $text;
            }
        }

        return $this->text(
            $crawler,
            '.content-block-date, .event-dates'
        );
    }

    /**
     * @return list<ParsedOccurrence>
     */
    private function occurrences(
        Crawler $crawler
    ): array {
        return collect(
            $crawler
                ->filter(
                    '.prog_date_wrapper_item'
                )
                ->each(
                    function (
                        Crawler $node
                    ): ?ParsedOccurrence {
                        $date = $this->text(
                            $node,
                            '.prog_date'
                        );

                        if ($date === null) {
                            return null;
                        }

                        $startTime =
                            $this->text(
                                $node,
                                '.prog_time'
                            )
                            ?? '';

                        $endTime =
                            $this->text(
                                $node,
                                '.prog_time_end'
                            )
                            ?? '';

                        $start =
                            $this->dates
                            ->parseDateText(
                                trim(
                                    $date
                                        . ' '
                                        . $startTime
                                )
                            );

                        $end =
                            $endTime === ''
                            ? null
                            : $this->dates
                            ->parseDateText(
                                trim(
                                    $date
                                        . ' '
                                        . $endTime
                                )
                            )
                            ?->startsAt;

                        return $start === null
                            ? null
                            : new ParsedOccurrence(
                                $start->startsAt,
                                $end
                            );
                    }
                )
        )
            ->filter()
            ->unique(
                fn(
                    ParsedOccurrence $occurrence
                ): string =>
                $occurrence->key()
            )
            ->values()
            ->all();
    }

    private function blockText(
        Crawler $crawler,
        string $selector
    ): ?string {
        $node = $crawler->filter(
            $selector
        );

        if ($node->count() === 0) {
            return null;
        }

        $html = $node
            ->reduce(
                fn(
                    Crawler $item,
                    int $index
                ): bool =>
                $index < 2
            )
            ->each(
                fn(
                    Crawler $item
                ): string =>
                $item->html('')
            );

        $html = implode(
            "\n",
            $html
        );

        $html =
            preg_replace(
                '/<(br|\/p|\/div|\/li|\/h[1-6])>/iu',
                "$0\n",
                $html
            )
            ?: $html;

        $text = html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text =
            preg_replace(
                '/[ \t]+/u',
                ' ',
                $text
            )
            ?: $text;

        $text =
            preg_replace(
                '/\n{3,}/u',
                "\n\n",
                $text
            )
            ?: $text;

        $text = trim(
            $text
        );

        return $text === ''
            ? null
            : $text;
    }

    /**
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

            $label = trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    html_entity_decode(
                        $node->textContent,
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )
                ) ?: ''
            );

            if ($label === '') {
                $label = trim(
                    $node->getAttribute(
                        'title'
                    )
                );
            }

            $documents[] = [
                'url' =>
                $url,

                'label' =>
                $label,
            ];
        }

        return $documents;
    }
}
