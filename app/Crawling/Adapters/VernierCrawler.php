<?php

namespace App\Crawling\Adapters;

use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\RawEvent;
use App\Crawling\Data\RawStatusEvidence;
use App\Crawling\Documents\PdfEvidenceExtractor;
use App\Crawling\Exceptions\CrawlerParseException;
use App\Models\EventSource;
use DOMElement;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VernierCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    /**
     * @return iterable<EventReference>
     */
    public function discover(EventSource $source, CrawlOptions $options): iterable
    {
        $url = $source->listing_url;
        $seenPages = [];
        $seenReferences = [];
        $references = [];

        for ($page = 0; $page < $options->maxListingPages && $url !== null; $page++) {
            $url = $this->urls->canonical($url);

            if (isset($seenPages[$url])) {
                break;
            }

            $seenPages[$url] = true;

            $fetched = $this->fetcher->get(
                $url,
                $this->allowedHosts($source),
            );

            $crawler = $this->crawler(
                $fetched->body,
                $fetched->finalUrl,
            );

            foreach ($this->discoverPage($crawler, $fetched->finalUrl) as $reference) {
                if (isset($seenReferences[$reference->hash()])) {
                    continue;
                }

                $seenReferences[$reference->hash()] = true;
                $references[] = $reference;

                if (
                    ! $options->all
                    && $options->eventLimit !== null
                    && count($references) >= $options->eventLimit
                ) {
                    return $references;
                }
            }

            $url = $this->nextPageUrl(
                $crawler,
                $fetched->finalUrl,
                $seenPages,
            );
        }

        return $references;
    }

    public function fetch(EventSource $source, EventReference $reference): RawEvent
    {
        $allowedHosts = $this->allowedHosts($source);

        $fetched = $this->fetcher->get(
            $reference->url,
            $allowedHosts,
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl,
        );

        $jsonLd = $this->jsonLdEvent($crawler);

        $canonicalUrl = $this->urls->canonical(
            $this->attr($crawler, 'link[rel="canonical"]', 'href')
                ?? $this->attr($crawler, 'meta[property="og:url"]', 'content')
                ?? $fetched->finalUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Dates and occurrences
        |--------------------------------------------------------------------------
        */

        $dateTexts = array_merge(
            [$reference->dateText],
            $crawler
                ->filter('time, .date, .field--name-date, [class*="date"]')
                ->each(
                    fn($node): string => $node->text('')
                ),
        );

        $occurrences = $this->dates->parseMany(
            array_values(
                array_filter($dateTexts)
            )
        );

        $mainOccurrence =
            $occurrences[0]
            ?? $this->dates->parseDateText(
                $reference->dateText
            );

        /*
        |--------------------------------------------------------------------------
        | Primary event evidence
        |--------------------------------------------------------------------------
        */

        $raw = $this->rawFragment(
            $crawler,
            '#block-vernier-content, main article, .openagenda-event'
        );

        if (trim($raw) === '') {
            throw new CrawlerParseException(
                'Unable to isolate Vernier event fragment.'
            );
        }

        $eventCrawler = new Crawler(
            $raw,
            $fetched->finalUrl,
        );

        /*
        |--------------------------------------------------------------------------
        | Title
        |--------------------------------------------------------------------------
        */

        $title =
            (
                is_string($jsonLd['name'] ?? null)
                ? trim($jsonLd['name'])
                : null
            )
            ?? $this->attr(
                $crawler,
                'meta[name="title"]',
                'content'
            )
            ?? $this->text(
                $crawler,
                'h1'
            )
            ?? $reference->titleHint;

        /*
        |--------------------------------------------------------------------------
        | Classified event assets
        |--------------------------------------------------------------------------
        |
        | All images found inside the actual event fragment survive in
        | raw_html.
        |
        | Only event_media and unknown are allowed into hero candidates.
        |
        */

        $classifiedAssets = $this->classifiedAssets(
            $eventCrawler,
            $fetched->finalUrl,
        );

        $imageCandidates = $this->eventImageCandidates(
            $classifiedAssets
        );

        /*
        |--------------------------------------------------------------------------
        | Supplementary image candidates
        |--------------------------------------------------------------------------
        |
        | JSON-LD, OpenGraph and the listing thumbnail may contain useful
        | image evidence outside the isolated event fragment.
        |
        */

        $supplementaryCandidates = [
            [
                'url' => $this->imageFromJsonLd($jsonLd),
                'origin' => 'json-ld',
            ],
            [
                'url' => $this->attr(
                    $crawler,
                    'meta[property="og:image"]',
                    'content'
                ),
                'origin' => 'open-graph',
            ],
            [
                'url' => $reference->imageUrl,
                'origin' => 'listing',
            ],
        ];

        foreach ($supplementaryCandidates as $candidate) {
            $candidateUrl = $candidate['url'] ?? null;

            if (
                ! is_string($candidateUrl)
                || trim($candidateUrl) === ''
            ) {
                continue;
            }

            try {
                $candidateUrl = $this->urls->absolute(
                    $candidateUrl,
                    $fetched->finalUrl,
                );
            } catch (\Throwable) {
                continue;
            }

            $imageCandidates[] = [
                'url' => $candidateUrl,
                'origin' => $candidate['origin'],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Deduplicate image candidates
        |--------------------------------------------------------------------------
        */

        $imageCandidates = collect($imageCandidates)
            ->filter(
                fn(mixed $candidate): bool =>
                is_array($candidate)
                    && is_string($candidate['url'] ?? null)
                    && trim($candidate['url']) !== ''
            )
            ->unique(
                fn(array $candidate): string =>
                trim($candidate['url'])
            )
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Convenience imageUrl
        |--------------------------------------------------------------------------
        |
        | ImageValidator only determines RawEvent::imageUrl.
        |
        | It must never decide whether an image survives in raw_html.
        |
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
        | PDF/document evidence
        |--------------------------------------------------------------------------
        */

        $documents = $this->documentCandidates(
            $eventCrawler,
            $fetched->finalUrl,
        );

        $documents = array_slice(
            $documents,
            0,
            5,
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
                        $documentAllowedHosts,
                    );

                    $documentEvidence[] = [
                        'url' => $evidence['url'],
                        'requested_url' => $evidence['requested_url'],
                        'label' => $document['label'],
                        'text' => $evidence['text'],
                        'sha256' => $evidence['sha256'],
                        'content_type' => $evidence['content_type'],
                    ];
                } catch (\Throwable) {
                    /*
                    | PDF extraction is best-effort.
                    |
                    | The link remains preserved even if extraction fails.
                    */
                    continue;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Existing Vernier canonical-content logic
        |--------------------------------------------------------------------------
        */

        $introduction = $this->attr(
            $crawler,
            'meta[name="description"]',
            'content'
        );

        $canonicalDescription = $this->canonicalDescription(
            $crawler,
            $fetched->finalUrl,
            $introduction,
        );

        $canonical = $this->canonicalContent->normalize(
            $canonicalDescription ?? $introduction,
            $fetched->finalUrl,
        );

        /*
        |--------------------------------------------------------------------------
        | Existing source-status detection
        |--------------------------------------------------------------------------
        */

        $dedicatedStatus = $this->text(
            $crawler,
            '.oa-event-status, [data-event-status], [class~="event-status"]'
        );

        $statusEvidence =
            $dedicatedStatus === null
            ? []
            : [
                new RawStatusEvidence(
                    text: $dedicatedStatus,
                    evidenceType: 'dedicated_status_element',
                    detectionMethod: 'source_adapter',
                    sourceSelector: '.oa-event-status, [data-event-status], [class~="event-status"]',
                ),
            ];

        $sourceStatus = $this->sourceStatuses->detect(
            jsonLd: $jsonLd,
            title: $title,
            introduction: $introduction,
            contentHtml: $canonical->html,
            sourceLanguage: 'fr',
            evidence: $statusEvidence,
            sourceUrl: $canonicalUrl,
        );

        /*
        |--------------------------------------------------------------------------
        | Classified image evidence
        |--------------------------------------------------------------------------
        */

        $imageEvidenceHtml = $this->assetEvidenceHtml(
            $classifiedAssets,
            $title,
        );

        $classifiedAssetUrls = array_fill_keys(
            array_map(
                static fn($asset): string => $asset->url,
                $classifiedAssets,
            ),
            true,
        );

        /*
        |--------------------------------------------------------------------------
        | Supplementary image evidence
        |--------------------------------------------------------------------------
        */

        $supplementaryImageHtml = '';

        foreach ($imageCandidates as $candidate) {
            $candidateUrl = $candidate['url'] ?? null;

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
        | Explicit PDF/document candidates
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
                    . e($document['url'])
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
                . e($evidence['url'])
                . '"'
                . ' data-document-sha256="'
                . e($evidence['sha256'])
                . '"'
                . '>';

            if (
                is_string($evidence['label'])
                && trim($evidence['label']) !== ''
            ) {
                $documentEvidenceHtml .=
                    '<h3 data-crawl-role="document-label">'
                    . e($evidence['label'])
                    . '</h3>';
            }

            $documentEvidenceHtml .=
                '<pre data-crawl-role="document-text">'
                . e($evidence['text'])
                . '</pre>';

            $documentEvidenceHtml .=
                '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Structured JSON-LD evidence
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
            '<article data-crawl-adapter="vernier">'
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
        | Logging
        |--------------------------------------------------------------------------
        */

        Log::info('Vernier source event extracted.', [
            'operation' => 'source_extraction',
            'adapter' => 'vernier',
            'canonical_url' => $canonicalUrl,
            'source_text_length' => mb_strlen(
                $this->canonicalContent->plainText($raw) ?? ''
            ),
            'canonical_text_length' => mb_strlen(
                $canonical->text ?? ''
            ),
            'paragraph_count' => substr_count(
                $canonical->html ?? '',
                '<p'
            ),
            'anchor_count' => substr_count(
                $canonical->html ?? '',
                '<a '
            ),
            'occurrence_count' => count($occurrences),
            'status_detection_method' => $sourceStatus->method,
        ]);

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        */

        return new RawEvent(
            canonicalUrl: $canonicalUrl,

            rawHtml: $rawHtml,

            externalId: $reference->externalId
                ?? $this->externalId(
                    $canonicalUrl
                ),

            title: $title,

            startsAt: $this->parseDate(
                $jsonLd['startDate']
                    ?? null
            )
                ?? $mainOccurrence?->startsAt,

            endsAt: $this->parseDate(
                $jsonLd['endDate']
                    ?? null
            )
                ?? $mainOccurrence?->endsAt,

            isRecurring: count($occurrences) > 1
                || str_contains(
                    mb_strtolower(
                        (string) $reference->dateText
                    ),
                    'certains'
                ),

            recurrenceSummary: $reference->dateText,

            description: $canonical->text
                ?? $jsonLd['description']
                ?? $introduction
                ?? $this->text(
                    $crawler,
                    'main article, main'
                ),

            imageUrl: $image,

            sourceLanguage: 'fr',

            occurrences: $occurrences,

            metadata: array_filter([
                /*
                | Existing Vernier metadata
                */
                'image_caption' =>
                $this->text(
                    $crawler,
                    '#block-vernier-content figcaption'
                ),

                'organizer_name' =>
                $this->text(
                    $crawler,
                    '#block-vernier-content article h1 ~ a'
                ),

                'organizer_url' =>
                $this->attr(
                    $crawler,
                    '#block-vernier-content article h1 ~ a',
                    'href'
                ),

                /*
                | Shared evidence metadata
                */
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
            ]),

            canonicalDescriptionHtml: $canonical->html,

            imageValidationReason: $imageValidationReason,

            sourceStatus: $sourceStatus->status,

            sourceStatusLabel: $sourceStatus->label,

            sourceStatusNote: $sourceStatus->note,

            sourceStatusDetectionMethod: $sourceStatus->method,

            statusEvidence: $statusEvidence,
        );
    }

    private function canonicalDescription(
        Crawler $crawler,
        string $baseUrl,
        ?string $introduction
    ): ?string {
        $eventArticle = $crawler->filterXPath(
            '(//main//article[.//h1])[1]'
        );

        if ($eventArticle->count() === 0) {
            return $this->cleanBlockHtml(
                $crawler,
                '.oa-event--single .to-speech, .oa-event--single .section-text, .oa-event-description, main article .field--name-body',
                $baseUrl,
            );
        }

        $parts = [];

        $organizer = $eventArticle
            ->filter(
                'a[href*="/actualites/"], a[rel~="organizer"]'
            )
            ->first();

        if (
            $organizer->count() > 0
            && str_contains(
                mb_strtolower(
                    $eventArticle->text('')
                ),
                'organisé par'
            )
        ) {
            $parts[] =
                '<p>Organisé par '
                . $this->nodeHtml(
                    $organizer->first()
                )
                . '</p>';
        }

        if (
            $introduction !== null
            && trim($introduction) !== ''
        ) {
            $parts[] =
                '<p>'
                . htmlspecialchars(
                    trim($introduction),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
                . '</p>';
        }

        $bodyNodes = $eventArticle->filter(
            'h2, h3, h4, p, ul, ol, blockquote'
        );

        foreach ($bodyNodes as $node) {
            $html =
                $node->ownerDocument
                ?->saveHTML($node)
                ?: '';

            if ($html !== '') {
                $parts[] = $html;
            }
        }

        foreach (
            $eventArticle->filter(
                'a[role="button"], a[class~="btn"], a[class*="btn-"], a[class~="button"], a[class~="cta"]'
            )
            as $action
        ) {
            if (
                in_array(
                    strtolower(
                        (string) $action
                            ->parentNode
                            ?->nodeName
                    ),
                    ['p', 'li'],
                    true
                )
            ) {
                continue;
            }

            $parts[] =
                '<p>'
                . $this->nodeHtml(
                    new Crawler($action)
                )
                . '</p>';
        }

        $sidebar = $crawler->filterXPath(
            '(//main//article[not(.//h1) and .//h3])[1]'
        );

        if ($sidebar->count() > 0) {
            $venue = $this->text(
                $sidebar,
                'h3'
            );

            if ($venue !== null) {
                $parts[] =
                    '<h3>'
                    . htmlspecialchars(
                        $venue,
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )
                    . '</h3>';
            }

            $map = $sidebar
                ->filter(
                    'a[href*="maps.google"], a[href*="google.com/maps"], a[href*="openstreetmap"]'
                )
                ->first();

            if ($map->count() > 0) {
                $address =
                    $this->text(
                        $map,
                        'address'
                    )
                    ?? trim(
                        $map->text('')
                    );

                if ($address !== '') {
                    $parts[] =
                        '<p><strong>Adresse :</strong> '
                        . $this->linkHtml(
                            $map,
                            $address
                        )
                        . '</p>';
                }
            }

            $price = $sidebar->filterXPath(
                './/svg[contains(concat(" ", normalize-space(@class), " "), " icon-wallet ")]/ancestor::div[contains(concat(" ", normalize-space(@class), " "), " flex ")][1]'
            );

            if (
                $price->count() > 0
                && trim(
                    $price->text('')
                ) !== ''
            ) {
                $parts[] =
                    '<p><strong>Tarif :</strong> '
                    . htmlspecialchars(
                        trim(
                            $price->text('')
                        ),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )
                    . '</p>';
            }

            $contacts = [];

            foreach (
                $sidebar->filter(
                    'a[href^="mailto:"], a[href^="tel:"]'
                )
                as $contact
            ) {
                $contacts[] =
                    $this->linkHtml(
                        new Crawler($contact),
                        trim(
                            $contact->textContent
                        )
                    );
            }

            if ($contacts !== []) {
                $parts[] =
                    '<p><strong>Contact :</strong> '
                    . implode(
                        '<br>',
                        array_unique(
                            $contacts
                        )
                    )
                    . '</p>';
            }

            foreach (
                $sidebar->filter(
                    'a[role="button"], a[class~="btn"], a[class*="btn-"], a[class~="button"], a[class~="cta"]'
                )
                as $action
            ) {
                $parts[] =
                    '<p>'
                    . $this->nodeHtml(
                        new Crawler(
                            $action
                        )
                    )
                    . '</p>';
            }
        }

        $html = implode(
            '',
            $parts
        );

        return trim(
            strip_tags($html)
        ) === ''
            ? null
            : $html;
    }

    private function nodeHtml(
        Crawler $node
    ): string {
        $dom = $node->getNode(0);

        return $dom
            ?->ownerDocument
            ?->saveHTML($dom)
            ?: '';
    }

    private function linkHtml(
        Crawler $link,
        string $text
    ): string {
        $href = trim(
            (string) $link->attr(
                'href'
            )
        );

        $target =
            strtolower(
                trim(
                    (string) $link->attr(
                        'target'
                    )
                )
            ) === '_blank'
            ? ' target="_blank"'
            : '';

        return '<a href="'
            . htmlspecialchars(
                $href,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
            . '"'
            . $target
            . '>'
            . htmlspecialchars(
                $text,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
            . '</a>';
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
                    '.oa-list__item article, article[id^="event--"]'
                )
                ->each(
                    function (
                        $node
                    ) use (
                        $pageUrl
                    ): ?EventReference {
                        $href = $this->attr(
                            $node,
                            'a[href*="/evenements/"]',
                            'href'
                        );

                        if ($href === null) {
                            return null;
                        }

                        return new EventReference(
                            url: $this->urls->canonical(
                                $this->urls->absolute(
                                    $href,
                                    $pageUrl
                                )
                            ),

                            externalId: $this->externalId(
                                $href
                            )
                                ?? $this->externalId(
                                    (string) $node->attr(
                                        'id'
                                    )
                                ),

                            listingUrl: $pageUrl,

                            titleHint: $this->text(
                                $node,
                                'h2, h3'
                            ),

                            dateText: $this->text(
                                $node,
                                '.font-bold.text-p-900, time, .date'
                            ),

                            imageUrl: $this->extractBackgroundImage(
                                (string) $node->html(),
                                $pageUrl
                            ),
                        );
                    }
                )
        )
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string, bool> $seenPages
     */
    private function nextPageUrl(
        Crawler $crawler,
        string $pageUrl,
        array $seenPages
    ): ?string {
        $candidates =
            $crawler
            ->filter(
                'a[href*="?page="], a[href*="&page="]'
            )
            ->each(
                fn($node): string =>
                $this->urls->absolute(
                    (string) $node->attr(
                        'href'
                    ),
                    $pageUrl
                )
            );

        foreach ($candidates as $candidate) {
            $canonical =
                $this->urls->canonical(
                    $candidate
                );

            if (
                ! isset(
                    $seenPages[$canonical]
                )
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function externalId(
        string $value
    ): ?string {
        return preg_match(
            '/(\d{6,})/',
            $value,
            $match
        ) === 1
            ? $match[1]
            : null;
    }

    private function extractBackgroundImage(
        string $html,
        string $baseUrl
    ): ?string {
        if (
            preg_match(
                '/background-image:\s*url\(([^)]+)\)/i',
                $html,
                $match
            ) !== 1
        ) {
            return null;
        }

        try {
            return $this->urls->absolute(
                trim(
                    $match[1],
                    '\'" '
                ),
                $baseUrl
            );
        } catch (\Throwable) {
            return null;
        }
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

        $listingHost = parse_url(
            $source->listing_url,
            PHP_URL_HOST
        );

        $configuredHosts =
            $configuration['allowed_hosts']
            ?? [];

        return array_values(
            array_unique(
                array_filter([
                    'vernier.ch',
                    'www.vernier.ch',

                    is_string($listingHost)
                        ? $listingHost
                        : null,

                    ...(
                        is_array($configuredHosts)
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
}
