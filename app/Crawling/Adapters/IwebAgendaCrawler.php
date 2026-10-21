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

class IwebAgendaCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $fetched = $this->fetcher->get(
            $source->listing_url,
            $this->allowedHosts($source)
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl
        );

        $dataset = $crawler->filter(
            '#anlassList[data-entities]'
        );

        $encoded = $dataset->count() === 0
            ? null
            : trim(
                $dataset->first()->attr('data-entities') ?? ''
            );

        if ($encoded === null || $encoded === '') {
            throw new CrawlerParseException(
                "No i-web event dataset found at {$fetched->finalUrl}"
            );
        }

        try {
            $payload = json_decode(
                $encoded,
                true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new CrawlerParseException(
                "Invalid i-web event dataset at {$fetched->finalUrl}",
                previous: $exception
            );
        }

        $records = is_array(
            $payload['data'] ?? null
        )
            ? $payload['data']
            : [];

        $references = [];
        $seen = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $id = $record['id'] ?? null;

            if (
                (
                    ! is_int($id)
                    && ! ctype_digit((string) $id)
                )
                || isset($seen[(string) $id])
            ) {
                continue;
            }

            $title = $this->recordTitle(
                $record['name'] ?? null
            );

            if ($title === null) {
                continue;
            }

            /*
                |--------------------------------------------------------------------------
                | iWeb record link
                |--------------------------------------------------------------------------
                |
                | Some iWeb installations put an image/media endpoint inside the
                | record's "name" HTML:
                |
                |     /_rte/anlass/{id}
                |
                | That endpoint is NOT an HTML event detail page.
                |
                | When this happens:
                |
                | - preserve the URL as image/media evidence;
                | - use the listing URL as RawEvent canonical/source URL;
                | - build the RawEvent directly from the structured listing record.
                |
                | A genuine HTML detail URL continues through the normal detail-page
                | fetch path.
                |
                */

            $recordLink = $this->recordLink(
                $record['name'] ?? null
            );

            $absoluteLink = null;

            if ($recordLink !== null) {
                try {
                    $absoluteLink = $this->urls->absolute(
                        $recordLink,
                        $fetched->finalUrl
                    );
                } catch (\Throwable) {
                    $absoluteLink = null;
                }
            }

            $mediaUrl = null;
            $detailUrl = null;

            if (
                $absoluteLink !== null
                && $this->isIwebMediaUrl($absoluteLink)
            ) {
                $mediaUrl = $absoluteLink;
            } elseif ($absoluteLink !== null) {
                $detailUrl = $absoluteLink;
            }

            /*
                | When no genuine detail page exists, the source listing itself is
                | the canonical source page for the record.
                |
                | SourceEvent.external_id remains the persistent record identity, so
                | multiple records may safely share this listing URL.
                */

            $referenceUrl = $detailUrl
                ?? $fetched->finalUrl;

            $seen[(string) $id] = true;

            $references[] = new EventReference(
                url: $this->urls->canonical(
                    $referenceUrl
                ),

                externalId: (string) $id,

                listingUrl: $fetched->finalUrl,

                titleHint: $title,

                dateText: $this->recordText(
                    $record['_anlassDate'] ?? null
                ),

                imageUrl: $mediaUrl,

                metadata: [
                    'record' =>
                    $record,

                    'detail_url' =>
                    $detailUrl,

                    'media_url' =>
                    $mediaUrl,

                    'listing_record_only' =>
                    $detailUrl === null,
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

        return $references;
    }

    public function fetch(
        EventSource $source,
        EventReference $reference
    ): RawEvent {
        $record =
            is_array(
                $reference->metadata['record'] ?? null
            )
            ? $reference->metadata['record']
            : [];

        $listingRecordOnly =
            ($reference->metadata['listing_record_only'] ?? false)
            === true;

        /*
            |--------------------------------------------------------------------------
            | Structured-record-only iWeb event
            |--------------------------------------------------------------------------
            |
            | If iWeb did not provide a real HTML detail page, do not attempt to
            | fetch its /_rte/anlass/{id} media endpoint as HTML.
            |
            | The embedded data-entities record already provides structured source
            | evidence including the title, dates, venue/locality and organizer.
            |
            */

        if ($listingRecordOnly) {
            return $this->fetchFromListingRecord(
                $source,
                $reference,
                $record
            );
        }

        /*
            |--------------------------------------------------------------------------
            | Genuine HTML detail page
            |--------------------------------------------------------------------------
            */

        $allowedHosts = $this->allowedHosts(
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

        $title =
            $this->attr(
                $crawler,
                'meta[property="og:title"]',
                'content'
            )
            ?? $this->text(
                $crawler,
                'main h1.contentTitle, main h1'
            )
            ?? $reference->titleHint;

        if ($title === null) {
            throw new CrawlerParseException(
                "No i-web event title found for {$canonical}"
            );
        }

        /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

        [$startsAt, $endsAt] = $this->dates(
            $record,
            $this->text(
                $crawler,
                '.icms-lead-container'
            )
        );

        /*
            |--------------------------------------------------------------------------
            | Primary event evidence
            |--------------------------------------------------------------------------
            */

        $raw = $this->stableRawContent(
            $crawler
        );

        if (trim($raw) === '') {
            throw new CrawlerParseException(
                "Unable to isolate i-web event content for {$canonical}"
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
            | iWeb commonly provides its page image through OpenGraph metadata,
            | which may live outside the isolated event fragment.
            |
            */

        $supplementaryCandidates = [
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

            /*
                | Preserve an iWeb media URL found during discovery, if any.
                */

            [
                'url' =>
                $reference->metadata['media_url']
                    ?? null,

                'origin' =>
                'iweb-listing-record',
            ],
        ];

        foreach (
            $supplementaryCandidates
            as $candidate
        ) {
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
                $candidateUrl =
                    $this->urls->absolute(
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

        $imageCandidates =
            collect($imageCandidates)
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
            */

        $documents =
            $this->documentCandidates(
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

            foreach (
                $documents
                as $document
            ) {
                try {
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
                                    is_string($documentHost)
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
                        | PDF extraction remains best-effort.
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
            | Document candidate links
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
            | iWeb structured listing evidence
            |--------------------------------------------------------------------------
            */

        $recordEvidenceHtml =
            $this->recordEvidenceHtml(
                $record
            );

        /*
            |--------------------------------------------------------------------------
            | Final raw_html
            |--------------------------------------------------------------------------
            */

        $rawHtml =
            '<article data-crawl-adapter="iweb-agenda">'
            . '<section data-crawl-source="event-content">'
            . $raw
            . '</section>'
            . $imageEvidenceHtml
            . $supplementaryImageHtml
            . $documentCandidateHtml
            . $documentEvidenceHtml
            . $recordEvidenceHtml
            . '</article>';

        return new RawEvent(
            canonicalUrl: $canonical,

            rawHtml: $rawHtml,

            externalId: $reference->externalId,

            title: $title,

            startsAt: $startsAt,

            endsAt: $endsAt,

            description: $this->cleanBlockText(
                $crawler,
                '.icms-text-container .icms-wysiwyg'
            ),

            imageUrl: $image,

            sourceLanguage: 'fr',

            occurrences: $startsAt === null
                ? []
                : [
                    new ParsedOccurrence(
                        $startsAt,
                        $endsAt
                    ),
                ],

            metadata: [
                'venue' =>
                $this->recordText(
                    $record['lokalitaet'] ?? null
                ),

                'locality' =>
                $this->recordText(
                    $record['ort'] ?? null
                ),

                'organizer' =>
                $this->recordText(
                    $record['organisator'] ?? null
                ),

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
                '.icms-text-container .icms-wysiwyg',
                $fetched->finalUrl
            ),

            imageValidationReason: $imageValidationReason,
        );
    }

    /*
        |--------------------------------------------------------------------------
        | Structured listing-record fallback
        |--------------------------------------------------------------------------
        */

    private function fetchFromListingRecord(
        EventSource $source,
        EventReference $reference,
        array $record
    ): RawEvent {
        $title =
            $reference->titleHint
            ?? $this->recordTitle(
                $record['name'] ?? null
            );

        if ($title === null) {
            throw new CrawlerParseException(
                "No i-web event title found for listing record {$reference->externalId}"
            );
        }

        $mediaUrl =
            is_string(
                $reference->metadata['media_url']
                    ?? null
            )
            ? trim(
                $reference->metadata['media_url']
            )
            : null;

        if ($mediaUrl === '') {
            $mediaUrl = null;
        }

        [$startsAt, $endsAt] =
            $this->dates(
                $record,
                $this->recordText(
                    $record['_anlassDate']
                        ?? null
                )
            );

        /*
            |--------------------------------------------------------------------------
            | Convenience image
            |--------------------------------------------------------------------------
            |
            | /_rte/anlass/{id} is treated as media evidence, never as HTML.
            |
            */

        $image = null;
        $imageValidationReason = null;

        if ($mediaUrl !== null) {
            try {
                $image =
                    $this->images->validate(
                        $mediaUrl
                    );

                $imageValidationReason =
                    $this->images
                    ->lastResult()
                    ?->reason;
            } catch (\Throwable) {
                /*
                    | Preserve the URL as evidence even when convenience validation
                    | fails.
                    */
                $image = null;
            }
        }

        /*
            |--------------------------------------------------------------------------
            | Structured evidence
            |--------------------------------------------------------------------------
            */

        $recordEvidenceHtml =
            $this->recordEvidenceHtml(
                $record
            );

        $imageEvidenceHtml = '';

        if ($mediaUrl !== null) {
            $imageEvidenceHtml =
                '<section data-crawl-source="supplementary-image-evidence">'
                . '<img'
                . ' src="'
                . e($mediaUrl)
                . '"'
                . ' alt="'
                . e($title)
                . '"'
                . ' data-crawl-role="event-image-candidate"'
                . ' data-crawl-asset-role="event_media"'
                . ' data-crawl-origin="iweb-listing-record"'
                . '>'
                . '</section>';
        }

        /*
            |--------------------------------------------------------------------------
            | Human-readable listing evidence
            |--------------------------------------------------------------------------
            |
            | Keep a concise HTML representation in addition to the source JSON so
            | downstream normalization does not have to infer basic meaning from raw
            | property names alone.
            |
            */

        $contentHtml =
            '<section data-crawl-source="event-content">'
            . '<h1>'
            . e($title)
            . '</h1>';

        $dateText =
            $this->recordText(
                $record['_anlassDate']
                    ?? null
            );

        if ($dateText !== null) {
            $contentHtml .=
                '<p data-crawl-role="event-date">'
                . e($dateText)
                . '</p>';
        }

        $venue =
            $this->recordText(
                $record['lokalitaet']
                    ?? null
            );

        if ($venue !== null) {
            $contentHtml .=
                '<p data-crawl-role="event-venue">'
                . e($venue)
                . '</p>';
        }

        $locality =
            $this->recordText(
                $record['ort']
                    ?? null
            );

        if ($locality !== null) {
            $contentHtml .=
                '<p data-crawl-role="event-locality">'
                . e($locality)
                . '</p>';
        }

        $organizer =
            $this->recordText(
                $record['organisator']
                    ?? null
            );

        if ($organizer !== null) {
            $contentHtml .=
                '<p data-crawl-role="event-organizer">'
                . e($organizer)
                . '</p>';
        }

        /*
            | Preserve other useful textual record fields without imposing an event
            | interpretation on unknown iWeb property names.
            */

        foreach (
            [
                'beschreibung',
                'description',
                'text',
                'lead',
                'kurztext',
            ]
            as $field
        ) {
            $value =
                $this->recordText(
                    $record[$field] ?? null
                );

            if ($value === null) {
                continue;
            }

            $contentHtml .=
                '<p data-crawl-role="event-description">'
                . e($value)
                . '</p>';

            break;
        }

        $contentHtml .=
            '</section>';

        $rawHtml =
            '<article'
            . ' data-crawl-adapter="iweb-agenda"'
            . ' data-crawl-mode="listing-record"'
            . '>'
            . $contentHtml
            . $imageEvidenceHtml
            . $recordEvidenceHtml
            . '</article>';

        $description = $this->firstRecordDescription(
            $record
        );

        return new RawEvent(
            canonicalUrl: $reference->listingUrl
                ?? $source->listing_url,

            rawHtml: $rawHtml,

            externalId: $reference->externalId,

            title: $title,

            startsAt: $startsAt,

            endsAt: $endsAt,

            description: $description,

            imageUrl: $image
                ?? $mediaUrl,

            sourceLanguage: 'fr',

            occurrences: $startsAt === null
                ? []
                : [
                    new ParsedOccurrence(
                        $startsAt,
                        $endsAt
                    ),
                ],

            metadata: [
                'venue' =>
                $venue,

                'locality' =>
                $locality,

                'organizer' =>
                $organizer,

                'image_candidates' =>
                $mediaUrl === null
                    ? []
                    : [
                        [
                            'url' =>
                            $mediaUrl,

                            'origin' =>
                            'iweb-listing-record',
                        ],
                    ],

                'assets' =>
                $mediaUrl === null
                    ? []
                    : [
                        [
                            'url' =>
                            $mediaUrl,

                            'role' =>
                            'event_media',

                            'reason' =>
                            'iweb-media-endpoint',

                            'semantic_key' =>
                            null,

                            'label' =>
                            $title,
                        ],
                    ],

                'documents' =>
                [],

                'document_evidence' =>
                [],

                'iweb_listing_record_only' =>
                true,

                'iweb_media_url' =>
                $mediaUrl,
            ],

            canonicalDescriptionHtml: $description === null
                ? null
                : '<p>'
                . e($description)
                . '</p>',

            imageValidationReason: $imageValidationReason,
        );
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

        $host = parse_url(
            $source->listing_url,
            PHP_URL_HOST
        );

        $configuredHosts =
            is_array(
                $configuration['allowed_hosts'] ?? null
            )
            ? $configuration['allowed_hosts']
            : [];

        return array_values(
            array_unique(
                array_filter([
                    is_string($host)
                        ? $host
                        : null,

                    ...array_values(
                        array_filter(
                            $configuredHosts,
                            'is_string'
                        )
                    ),
                ], 'is_string')
            )
        );
    }

    private function recordLink(
        mixed $html
    ): ?string {
        if (
            ! is_string($html)
            || trim($html) === ''
        ) {
            return null;
        }

        $crawler = $this->crawler(
            '<div>'
                . $html
                . '</div>',
            'https://official.invalid'
        );

        return $this->attr(
            $crawler,
            'a[href]',
            'href'
        );
    }

    private function recordTitle(
        mixed $html
    ): ?string {
        if (
            ! is_string($html)
            || trim($html) === ''
        ) {
            return null;
        }

        return $this->recordText(
            $html
        );
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
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        return $value === ''
            ? null
            : $value;
    }

    /*
        |--------------------------------------------------------------------------
        | iWeb media URL detection
        |--------------------------------------------------------------------------
        */

    private function isIwebMediaUrl(
        string $url
    ): bool {
        $path = parse_url(
            $url,
            PHP_URL_PATH
        );

        if (! is_string($path)) {
            return false;
        }

        return preg_match(
            '#/_rte/anlass/\d+(?:/)?$#i',
            $path
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *     CarbonImmutable|null,
     *     CarbonImmutable|null
     * }
     */
    private function dates(
        array $record,
        ?string $detailText
    ): array {
        $startDate = $this->isoDate(
            $record['_datumVon'] ?? null
        );

        $endDate =
            $this->isoDate(
                $record['_datumBis'] ?? null
            )
            ?? $startDate;

        if (
            $startDate === null
            || $endDate === null
        ) {
            return [
                null,
                null,
            ];
        }

        preg_match_all(
            '/(\d{1,2})h(\d{2})/u',
            $detailText ?? '',
            $matches,
            PREG_SET_ORDER
        );

        $start =
            isset($matches[0])
            ? $startDate->setTime(
                (int) $matches[0][1],
                (int) $matches[0][2]
            )
            : $startDate;

        $end =
            isset($matches[1])
            ? $endDate->setTime(
                (int) $matches[1][1],
                (int) $matches[1][2]
            )
            : $endDate->endOfDay();

        if (
            $end->lessThan($start)
            && $endDate->isSameDay(
                $startDate
            )
        ) {
            $end = $end->addDay();
        }

        return [
            $start,
            $end,
        ];
    }

    private function isoDate(
        mixed $value
    ): ?CarbonImmutable {
        if (
            ! is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            ) !== 1
        ) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $value,
                'Europe/Zurich'
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function stableRawContent(
        Crawler $crawler
    ): string {
        $raw = $this->rawFragment(
            $crawler,
            '.icms-content-col-a, #maincontent'
        );

        if ($raw !== '') {
            return $raw;
        }

        $nodes = $crawler->filter(
            '.icms-lead-container, .icms-text-container, .icms-container-callout'
        );

        if ($nodes->count() === 0) {
            return '';
        }

        return '<article>'
            . implode(
                '',
                $nodes->each(
                    fn(Crawler $node): string =>
                    '<section>'
                        . $node->html('')
                        . '</section>'
                )
            )
            . '</article>';
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

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordEvidenceHtml(
        array $record
    ): string {
        if ($record === []) {
            return '';
        }

        $recordJson = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
        );

        if (! is_string($recordJson)) {
            return '';
        }

        /*
            | Escape closing script tags defensively while retaining readable JSON.
            */

        $recordJson = str_ireplace(
            '</script',
            '<\/script',
            $recordJson
        );

        return
            '<section data-crawl-source="listing-record">'
            . '<script type="application/json">'
            . $recordJson
            . '</script>'
            . '</section>';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function firstRecordDescription(
        array $record
    ): ?string {
        foreach (
            [
                'beschreibung',
                'description',
                'text',
                'lead',
                'kurztext',
            ]
            as $field
        ) {
            $value =
                $this->recordText(
                    $record[$field] ?? null
                );

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
