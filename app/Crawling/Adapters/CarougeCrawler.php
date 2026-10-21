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

class CarougeCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $url = $source->listing_url;
        $seenPages = [];
        $seenReferences = [];
        $references = [];

        for (
            $page = 0;
            $page < $options->maxListingPages
                && $url !== null;
            $page++
        ) {
            $url = $this->urls->canonical(
                $url
            );

            if (isset($seenPages[$url])) {
                break;
            }

            $seenPages[$url] = true;

            $fetched = $this->fetcher->get(
                $url,
                $this->allowedHosts($source)
            );

            $crawler = $this->crawler(
                $fetched->body,
                $fetched->finalUrl
            );

            foreach (
                $crawler->filter(
                    'main article.card-teaser.event'
                )
                as $node
            ) {
                $item = new Crawler(
                    $node,
                    $fetched->finalUrl
                );

                $href = $this->attr(
                    $item,
                    'a[href^="/agenda/"]',
                    'href'
                );

                if ($href === null) {
                    continue;
                }

                $eventUrl =
                    $this->urls->canonical(
                        $this->urls->absolute(
                            $href,
                            $fetched->finalUrl
                        )
                    );

                if (
                    isset(
                        $seenReferences[$eventUrl]
                    )
                ) {
                    continue;
                }

                $seenReferences[$eventUrl] = true;

                $listingImage =
                    $this->attr(
                        $item,
                        'img[src]',
                        'src'
                    );

                if (
                    is_string($listingImage)
                    && trim($listingImage) !== ''
                ) {
                    try {
                        $listingImage =
                            $this->urls->absolute(
                                $listingImage,
                                $fetched->finalUrl
                            );
                    } catch (\Throwable) {
                        $listingImage = null;
                    }
                } else {
                    $listingImage = null;
                }

                $references[] =
                    new EventReference(
                        url: $eventUrl,

                        listingUrl: $fetched->finalUrl,

                        titleHint: $this->text(
                            $item,
                            'h2, h3, h4, .card-title'
                        ),

                        dateText: $this->text(
                            $item,
                            '.small-date, time'
                        ),

                        imageUrl: $listingImage,
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

            $next = $this->attr(
                $crawler,
                '.pager__item--next a, a[rel="next"]',
                'href'
            );

            $url = $next === null
                ? null
                : $this->urls->absolute(
                    $next,
                    $fetched->finalUrl
                );
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
                    ?? $this->attr(
                        $crawler,
                        'meta[property="og:url"]',
                        'content'
                    )
                    ?? $fetched->finalUrl
            );

        $title =
            $this->text(
                $crawler,
                '.paragraph--content_header h1, main h1'
            )
            ?? $reference->titleHint;

        if ($title === null) {
            throw new CrawlerParseException(
                "No Carouge event title found for {$canonical}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Occurrences
        |--------------------------------------------------------------------------
        */

        $occurrences =
            $this->occurrences(
                $crawler
            );

        $first =
            $occurrences[0]
            ?? null;

        $last =
            $occurrences === []
            ? null
            : $occurrences[array_key_last(
                $occurrences
            )];

        /*
        |--------------------------------------------------------------------------
        | Primary event evidence
        |--------------------------------------------------------------------------
        |
        | Carouge keeps important evidence in two areas:
        |
        | - .paragraph--content_header
        |   title, header/banner image, introductory information
        |
        | - #paragraphs
        |   main event content
        |
        | The previous implementation preserved only #paragraphs and could
        | therefore lose the actual header image from raw_html.
        |
        */

        $headerHtml =
            $this->rawFragment(
                $crawler,
                '.paragraph--content_header'
            );

        $bodyHtml =
            $this->rawFragment(
                $crawler,
                '#paragraphs'
            );

        if (
            trim($headerHtml) === ''
            && trim($bodyHtml) === ''
        ) {
            throw new CrawlerParseException(
                "Unable to isolate Carouge event content for {$canonical}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Compose event evidence without duplicating the header
        |--------------------------------------------------------------------------
        |
        | On the current Carouge structure, #paragraphs already contains the
        | paragraph--content_header section.
        |
        | Only prepend the separately captured header if #paragraphs does not
        | contain it.
        |
        */

        $bodyContainsHeader = false;

        if (trim($bodyHtml) !== '') {
            try {
                $bodyCrawler = new Crawler(
                    $bodyHtml,
                    $fetched->finalUrl
                );

                $bodyContainsHeader =
                    $bodyCrawler
                    ->filter(
                        '.paragraph--content_header'
                    )
                    ->count() > 0;
            } catch (\Throwable) {
                $bodyContainsHeader = false;
            }
        }

        $eventContentHtml =
            $bodyContainsHeader
            ? $bodyHtml
            : $headerHtml . $bodyHtml;

        $eventCrawler = new Crawler(
            $eventContentHtml,
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
        | The explicit Carouge header selector, OpenGraph metadata and listing
        | thumbnail are trusted supplementary image hints.
        |
        */

        $supplementaryCandidates = [
            [
                'url' =>
                $this->attr(
                    $crawler,
                    '.paragraph--content_header .content-header-image img[src]',
                    'src'
                ),

                'origin' =>
                'event-header',
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
                $reference->imageUrl,

                'origin' =>
                'listing',
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
                ! is_string(
                    $candidateUrl
                )
                || trim(
                    $candidateUrl
                ) === ''
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
            collect(
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
        | Image validation affects only the convenience imageUrl.
        |
        | All classified event evidence remains in raw_html regardless of
        | whether ImageValidator accepts it.
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
                    | PDF extraction is best-effort.
                    |
                    | The original link remains preserved even if pdftotext,
                    | HTTP retrieval or another extraction step fails.
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
            '<article data-crawl-adapter="carouge">'
            . '<section data-crawl-source="event-content">'
            . $eventContentHtml
            . '</section>'
            . $imageEvidenceHtml
            . $supplementaryImageHtml
            . $documentCandidateHtml
            . $documentEvidenceHtml
            . '</article>';

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        */

        return new RawEvent(
            canonicalUrl: $canonical,

            rawHtml: $rawHtml,

            title: $title,

            startsAt: $first?->startsAt,

            endsAt: $last?->endsAt
                ?? $last?->startsAt,

            isRecurring: count($occurrences) > 1,

            recurrenceSummary: count($occurrences) > 1
                ? count($occurrences)
                . ' dates publiées'
                : null,

            description: $this->cleanBlockText(
                $crawler,
                '.paragraph--content_header .description, #paragraphs > .paragraph--section .text'
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
                '.paragraph--content_header .description, #paragraphs > .paragraph--section .text',
                $fetched->finalUrl
            ),

            imageValidationReason: $imageValidationReason,
        );
    }

    /**
     * @return list<ParsedOccurrence>
     */
    private function occurrences(
        Crawler $crawler
    ): array {
        $values =
            $crawler
            ->filter(
                '.essential .hours time[datetime]'
            )
            ->each(
                fn(
                    Crawler $time
                ): ?CarbonImmutable =>
                $this->parseDate(
                    $time->attr(
                        'datetime'
                    )
                )
            );

        $occurrences = [];

        for (
            $index = 0;
            $index < count($values);
            $index += 2
        ) {
            $start =
                $values[$index]
                ?? null;

            $end =
                $values[$index + 1]
                ?? null;

            if ($start === null) {
                continue;
            }

            $occurrence =
                new ParsedOccurrence(
                    $start,
                    $end
                );

            $occurrences[$occurrence->key()] = $occurrence;
        }

        return array_values(
            $occurrences
        );
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
                'url' =>
                $url,

                'label' =>
                $label,
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
                    'carouge.ch',
                    'www.carouge.ch',

                    is_string(
                        $listingHost
                    )
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
}
