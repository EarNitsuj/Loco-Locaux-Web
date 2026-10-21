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
use Carbon\CarbonImmutable;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

class WordPressMecCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    private const MONTHS = [
        'jan' => 1,
        'janv' => 1,
        'january' => 1,
        'janvier' => 1,

        'feb' => 2,
        'fev' => 2,
        'fevr' => 2,
        'févr' => 2,
        'february' => 2,
        'fevrier' => 2,
        'février' => 2,

        'mar' => 3,
        'mars' => 3,
        'march' => 3,

        'apr' => 4,
        'avr' => 4,
        'april' => 4,
        'avril' => 4,

        'may' => 5,
        'mai' => 5,

        'jun' => 6,
        'juin' => 6,
        'june' => 6,

        'jul' => 7,
        'juil' => 7,
        'july' => 7,
        'juillet' => 7,

        'aug' => 8,
        'aout' => 8,
        'août' => 8,
        'august' => 8,

        'sep' => 9,
        'sept' => 9,
        'september' => 9,
        'septembre' => 9,

        'oct' => 10,
        'october' => 10,
        'octobre' => 10,

        'nov' => 11,
        'november' => 11,
        'novembre' => 11,

        'dec' => 12,
        'déc' => 12,
        'december' => 12,
        'decembre' => 12,
        'décembre' => 12,
    ];

    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $configuration = is_array(
            $source->configuration
        )
            ? $source->configuration
            : [];

        $baseUrl = (string) (
            $configuration['api_url']
            ?? $source->listing_url
        );

        $seenIds = [];
        $references = [];

        for (
            $page = 1;
            $page <= $options->maxListingPages;
            $page++
        ) {
            $url = $this->pageUrl(
                $baseUrl,
                $page
            );

            $records = $this->fetcher->getJson(
                $url,
                $this->allowedHosts($source)
            );

            if ($records === []) {
                break;
            }

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $id = $record['id']
                    ?? null;

                $link = $record['link']
                    ?? null;

                if (
                    (
                        ! is_int($id)
                        && ! ctype_digit(
                            (string) $id
                        )
                    )
                    || ! is_string($link)
                    || $link === ''
                ) {
                    continue;
                }

                if (
                    isset(
                        $seenIds[(string) $id]
                    )
                ) {
                    continue;
                }

                $seenIds[(string) $id] = true;

                $references[] =
                    new EventReference(
                        url: $this->urls->canonical(
                            $link
                        ),

                        externalId: (string) $id,

                        listingUrl: $url,

                        titleHint: $this->recordText(
                            $record['title']['rendered']
                                ?? null
                        ),

                        metadata: [
                            'record' => $record,
                        ],
                    );

                if (
                    ! $options->all
                    && $options->eventLimit !== null
                    && count($references)
                    >= $options->eventLimit
                ) {
                    return $references;
                }
            }
        }

        return $references;
    }

    public function fetch(
        EventSource $source,
        EventReference $reference
    ): RawEvent {
        $allowedHosts =
            $this->allowedHosts(
                $source
            );

        $fetched = $this->fetcher->get(
            $reference->url,
            $allowedHosts
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl
        );

        $canonical =
            $this->urls->canonical(
                $this->attr(
                    $crawler,
                    'link[rel="canonical"]',
                    'href'
                )
                    ?? $fetched->finalUrl
            );

        $title =
            $this->text(
                $crawler,
                '.mec-single-event .mec-single-title'
            )
            ?? $reference->titleHint;

        if ($title === null) {
            throw new CrawlerParseException(
                "No MEC event title found for {$canonical}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Dates
        |--------------------------------------------------------------------------
        */

        [
            $startDate,
            $rangeEndDate,
        ] = $this->calendarRange(
            $this->text(
                $crawler,
                '.mec-single-event-date .mec-start-date-label'
            )
        );

        $endDate =
            $this->calendarDate(
                $this->text(
                    $crawler,
                    '.mec-single-event-date .mec-end-date-label'
                )
            )
            ?? $rangeEndDate
            ?? $startDate;

        [
            $startTime,
            $endTime,
        ] = $this->times(
            $this->text(
                $crawler,
                '.mec-single-event-time .mec-events-abbr'
            )
        );

        $startsAt =
            $startDate?->setTime(
                $startTime[0],
                $startTime[1]
            );

        $endsAt =
            $endDate === null
            ? null
            : (
                $endTime === null
                ? (
                    $startTime === [0, 0]
                    ? $endDate->endOfDay()
                    : null
                )
                : $endDate->setTime(
                    $endTime[0],
                    $endTime[1]
                )
            );

        /*
        | MEC can represent an overnight event using an end time earlier than
        | the start time while keeping the same calendar date.
        */

        if (
            $startsAt !== null
            && $endsAt !== null
            && $endsAt->lessThan(
                $startsAt
            )
            && $endDate?->isSameDay(
                $startDate
            )
        ) {
            $endsAt =
                $endsAt->addDay();
        }

        /*
        |--------------------------------------------------------------------------
        | Primary event evidence
        |--------------------------------------------------------------------------
        */

        $raw = $this->rawFragment(
            $crawler,
            '.mec-single-event'
        );

        if (trim($raw) === '') {
            throw new CrawlerParseException(
                "Unable to isolate MEC event content for {$canonical}"
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
        | All images found inside the isolated MEC event are preserved as
        | evidence.
        |
        | Only:
        |
        | - event_media
        | - unknown
        |
        | participate in hero-image selection.
        |
        | Accessibility, practical information, organizer branding and UI
        | chrome remain available to downstream AI without competing for the
        | event banner.
        |
        */

        $classifiedAssets =
            $this->classifiedAssets(
                $eventCrawler,
                $fetched->finalUrl
            );

        $imageCandidates =
            $this->eventImageCandidates(
                $classifiedAssets
            );

        /*
        |--------------------------------------------------------------------------
        | Supplementary image candidates
        |--------------------------------------------------------------------------
        |
        | OpenGraph metadata lives outside .mec-single-event, so preserve it as
        | a supplementary image hint.
        |
        */

        $ogImage = $this->attr(
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
                        $fetched->finalUrl
                    );

                $imageCandidates[] = [
                    'url' => $ogImage,
                    'origin' => 'open-graph',
                ];
            } catch (\Throwable) {
                // Keep crawling even if malformed image metadata is present.
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
        |
        | ImageValidator determines only the convenience imageUrl.
        |
        | It does NOT decide whether an asset survives in raw_html.
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
        | Linked PDF documents
        |--------------------------------------------------------------------------
        |
        | PDF discovery is deliberately limited to .mec-single-event.
        |
        | The crawler exposes the evidence but does not interpret it.
        |
        */

        $documents =
            $this->documentCandidates(
                $eventCrawler,
                $fetched->finalUrl
            );

        /*
        | Avoid pathological event pages causing excessive document downloads.
        */

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

            foreach (
                $documents
                as $document
            ) {
                try {
                    /*
                    | A WordPress attachment may be served from the same site or
                    | from a media/CDN host referenced by the event itself.
                    */

                    $documentHost =
                        parse_url(
                            $document['url'],
                            PHP_URL_HOST
                        );

                    $documentAllowedHosts =
                        array_values(
                            array_unique([
                                ...$allowedHosts,

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

                    $evidence =
                        $pdfs->extract(
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
                    | Extraction failure must never discard the event.
                    |
                    | The original PDF link remains in event-content and in the
                    | explicit document-candidates section.
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

        $imageEvidenceHtml =
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
                ! is_string(
                    $candidateUrl
                )
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
                . '>';
        }

        if (
            $supplementaryImageHtml !== ''
        ) {
            $supplementaryImageHtml =
                '<section data-crawl-source="supplementary-image-evidence">'
                . $supplementaryImageHtml
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

            foreach (
                $documents
                as $document
            ) {
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
                        $document['label']
                            !== ''
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
        | Final raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml =
            '<article data-crawl-adapter="wordpress-mec">'
            . '<section data-crawl-source="event-content">'
            . $raw
            . '</section>'
            . $imageEvidenceHtml
            . $supplementaryImageHtml
            . $documentCandidateHtml
            . $documentEvidenceHtml
            . '</article>';

        /*
        |--------------------------------------------------------------------------
        | Source API record
        |--------------------------------------------------------------------------
        */

        $record =
            is_array(
                $reference
                    ->metadata['record']
                    ?? null
            )
            ? $reference
                ->metadata['record']
            : [];

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        */

        return new RawEvent(
            canonicalUrl: $canonical,

            rawHtml: $rawHtml,

            externalId: $reference->externalId,

            title: $title,

            startsAt: $startsAt,

            endsAt: $endsAt,

            description: $this->cleanBlockText(
                $crawler,
                '.mec-single-event-description'
            ),

            imageUrl: $image,

            sourceLanguage: 'fr',

            sourcePublishedAt: $this->parseDate(
                is_string(
                    $record['date']
                        ?? null
                )
                    ? $record['date']
                    : null
            ),

            occurrences: $startsAt === null
                ? []
                : [
                    new ParsedOccurrence(
                        $startsAt,
                        $endsAt
                    ),
                ],

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
                '.mec-single-event-description',
                $fetched->finalUrl
            ),

            imageValidationReason: $imageValidationReason,
        );
    }

    /**
     * Find PDF links belonging to this MEC event.
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
            | Recognise:
            |
            | file.pdf
            | file.pdf?download=1
            | file.pdf#page=2
            */

            if (
                preg_match(
                    '/\.pdf(?:$|[?#])/iu',
                    $href
                ) !== 1
            ) {
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

            $label = trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    html_entity_decode(
                        $node->textContent,
                        ENT_QUOTES
                            | ENT_HTML5,
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
                'url' => $url,
                'label' => $label,
            ];
        }

        return $documents;
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(
        EventSource $source
    ): array {
        $configuration =
            is_array(
                $source->configuration
            )
            ? $source->configuration
            : [];

        $apiHost = parse_url(
            (string) (
                $configuration['api_url']
                ?? $source->listing_url
            ),
            PHP_URL_HOST
        );

        $listingHost =
            parse_url(
                $source->listing_url,
                PHP_URL_HOST
            );

        $configuredHosts =
            $configuration['allowed_hosts']
            ?? [];

        return array_values(
            array_unique(
                array_filter([
                    is_string($apiHost)
                        ? $apiHost
                        : null,

                    is_string($listingHost)
                        ? $listingHost
                        : null,

                    ...(
                        is_array(
                            $configuredHosts
                        )
                        ? array_values(
                            array_filter(
                                $configuredHosts,
                                'is_string'
                            )
                        )
                        : []
                    ),
                ], 'is_string')
            )
        );
    }

    private function pageUrl(
        string $url,
        int $page
    ): string {
        $separator =
            str_contains(
                $url,
                '?'
            )
            ? '&'
            : '?';

        return $url
            . $separator
            . 'page='
            . $page;
    }

    private function calendarDate(
        ?string $value
    ): ?CarbonImmutable {
        if ($value === null) {
            return null;
        }

        $value =
            mb_strtolower(
                trim(
                    str_replace(
                        '.',
                        '',
                        $value
                    )
                )
            );

        if (
            preg_match(
                '/([[:alpha:]éûôîàèùç]+)\s+(\d{1,2})\s+(\d{4})/u',
                $value,
                $match
            ) === 1
        ) {
            $month =
                self::MONTHS[$match[1]]
                ?? null;

            $day =
                (int) $match[2];

            $year =
                (int) $match[3];
        } elseif (
            preg_match(
                '/(\d{1,2})\s+([[:alpha:]éûôîàèùç]+)\s+(\d{4})/u',
                $value,
                $match
            ) === 1
        ) {
            $month =
                self::MONTHS[$match[2]]
                ?? null;

            $day =
                (int) $match[1];

            $year =
                (int) $match[3];
        } else {
            return null;
        }

        return $month === null
            ? null
            : CarbonImmutable::create(
                $year,
                $month,
                $day,
                0,
                0,
                0,
                'Europe/Zurich'
            );
    }

    /**
     * @return array{
     *     CarbonImmutable|null,
     *     CarbonImmutable|null
     * }
     */
    private function calendarRange(
        ?string $value
    ): array {
        if ($value === null) {
            return [
                null,
                null,
            ];
        }

        $value =
            mb_strtolower(
                trim(
                    str_replace(
                        '.',
                        '',
                        $value
                    )
                )
            );

        if (
            preg_match(
                '/([[:alpha:]éûôîàèùç]+)\s+(\d{1,2})\s*-\s*(\d{1,2})\s+(\d{4})/u',
                $value,
                $match
            ) === 1
        ) {
            $month =
                self::MONTHS[$match[1]]
                ?? null;

            $year =
                (int) $match[4];

            return $month === null
                ? [
                    null,
                    null,
                ]
                : [
                    CarbonImmutable::create(
                        $year,
                        $month,
                        (int) $match[2],
                        0,
                        0,
                        0,
                        'Europe/Zurich'
                    ),

                    CarbonImmutable::create(
                        $year,
                        $month,
                        (int) $match[3],
                        0,
                        0,
                        0,
                        'Europe/Zurich'
                    ),
                ];
        }

        if (
            preg_match(
                '/(\d{1,2})\s*-\s*(\d{1,2})\s+([[:alpha:]éûôîàèùç]+)\s+(\d{4})/u',
                $value,
                $match
            ) === 1
        ) {
            $month =
                self::MONTHS[$match[3]]
                ?? null;

            $year =
                (int) $match[4];

            return $month === null
                ? [
                    null,
                    null,
                ]
                : [
                    CarbonImmutable::create(
                        $year,
                        $month,
                        (int) $match[1],
                        0,
                        0,
                        0,
                        'Europe/Zurich'
                    ),

                    CarbonImmutable::create(
                        $year,
                        $month,
                        (int) $match[2],
                        0,
                        0,
                        0,
                        'Europe/Zurich'
                    ),
                ];
        }

        $date =
            $this->calendarDate(
                $value
            );

        return [
            $date,
            $date,
        ];
    }

    /**
     * @return array{
     *     array{int, int},
     *     array{int, int}|null
     * }
     */
    private function times(
        ?string $value
    ): array {
        if (
            $value === null
            || preg_match(
                '/(\d{1,2})[:h](\d{2})(?:\s*-\s*(\d{1,2})[:h](\d{2}))?/',
                $value,
                $match
            ) !== 1
        ) {
            return [
                [0, 0],
                null,
            ];
        }

        return [
            [
                (int) $match[1],
                (int) $match[2],
            ],

            isset($match[3])
                ? [
                    (int) $match[3],
                    (int) $match[4],
                ]
                : null,
        ];
    }

    private function recordText(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(
            html_entity_decode(
                strip_tags($value),
                ENT_QUOTES
                    | ENT_HTML5,
                'UTF-8'
            )
        );

        return $value !== ''
            ? $value
            : null;
    }
}
