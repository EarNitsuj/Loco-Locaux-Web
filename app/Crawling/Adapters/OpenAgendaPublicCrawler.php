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

class OpenAgendaPublicCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $host = parse_url(
            $source->listing_url,
            PHP_URL_HOST
        );

        $allowedHosts = is_string($host)
            ? [$host]
            : [];

        $fetched = $this->fetcher->get(
            $source->listing_url,
            $allowedHosts
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl
        );

        $references = [];

        foreach (
            $crawler->filter(
                'main article[data-event-id], main article[data-uid]'
            )
            as $node
        ) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $item = new Crawler(
                $node,
                $fetched->finalUrl
            );

            $href = $this->attr(
                $item,
                'a[href]',
                'href'
            );

            $id =
                $node->getAttribute(
                    'data-event-id'
                )
                ?: $node->getAttribute(
                    'data-uid'
                );

            if (
                $href === null
                || $id === ''
            ) {
                continue;
            }

            $references[] =
                new EventReference(
                    url: $this->urls->canonical(
                        $this->urls->absolute(
                            $href,
                            $fetched->finalUrl
                        )
                    ),

                    externalId: $id,

                    listingUrl: $fetched->finalUrl,

                    titleHint: $this->text(
                        $item,
                        'h2, h3'
                    ),

                    imageUrl: $this->listingImage(
                        $item,
                        $fetched->finalUrl
                    ),
                );

            if (
                ! $options->all
                && $options->eventLimit !== null
                && count($references)
                >= $options->eventLimit
            ) {
                break;
            }
        }

        return $references;
    }

    public function fetch(
        EventSource $source,
        EventReference $reference
    ): RawEvent {
        $host = parse_url(
            $source->listing_url,
            PHP_URL_HOST
        );

        $allowedHosts = is_string($host)
            ? [$host]
            : [];

        $fetched = $this->fetcher->get(
            $reference->url,
            $allowedHosts
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Structured event data
        |--------------------------------------------------------------------------
        */

        $event = $this->jsonLdEvent(
            $crawler
        );

        $start = $this->parseDate(
            is_string(
                $event['startDate']
                    ?? null
            )
                ? $event['startDate']
                : null
        );

        $end = $this->parseDate(
            is_string(
                $event['endDate']
                    ?? null
            )
                ? $event['endDate']
                : null
        );

        if (
            $start !== null
            && $end?->lessThan($start)
        ) {
            $end = null;
        }

        $title =
            is_string(
                $event['name']
                    ?? null
            )
            ? trim(
                $event['name']
            )
            : $reference->titleHint;

        if (
            $start === null
            || $title === null
            || trim($title) === ''
        ) {
            throw new CrawlerParseException(
                "No OpenAgenda event data found for {$reference->url}"
            );
        }

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

        $description =
            is_string(
                $event['description']
                    ?? null
            )
            ? trim(
                html_entity_decode(
                    strip_tags(
                        $event['description']
                    ),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            )
            : null;

        /*
        |--------------------------------------------------------------------------
        | Primary event evidence
        |--------------------------------------------------------------------------
        |
        | The previous implementation reconstructed raw_html from only:
        |
        | - title
        | - description
        |
        | That discarded addresses, registration information, links, images,
        | accessibility details and other event-specific evidence.
        |
        | We now preserve the actual event page fragment.
        |
        */

        $eventContentHtml =
            $this->rawFragment(
                $crawler,
                'main article, main'
            );

        if (
            trim(
                $eventContentHtml
            ) === ''
        ) {
            /*
            | Keep a conservative structured-data fallback rather than losing
            | the event entirely if the site's DOM changes.
            */

            $eventContentHtml =
                '<article>'
                . '<h1>'
                . e($title)
                . '</h1>'
                . (
                    $description !== null
                    && $description !== ''
                    ? '<p>'
                    . e($description)
                    . '</p>'
                    : ''
                )
                . '</article>';
        }

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
        | JSON-LD, OpenGraph and listing images may live outside the isolated
        | event fragment.
        |
        */

        $supplementaryCandidates = [
            [
                'url' =>
                $this->imageFromJsonLd(
                    $event
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
                is_array(
                    $candidate
                )
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
        | ImageValidator affects only imageUrl.
        |
        | It does not determine whether image evidence survives in raw_html.
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

        /*
        | Avoid excessive document downloads from pathological pages.
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
                                    ? [
                                        $documentHost,
                                    ]
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
                    | Never discard an event because a linked PDF cannot be
                    | downloaded or converted to text.
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
        | Structured JSON-LD evidence
        |--------------------------------------------------------------------------
        */

        $structuredDataHtml = '';

        if ($event !== []) {
            $json = json_encode(
                $event,
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
            '<article data-crawl-adapter="openagenda-public">'
            . '<section data-crawl-source="event-content">'
            . $eventContentHtml
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

            externalId: $reference->externalId,

            title: $title,

            startsAt: $start,

            endsAt: $end,

            description: $description,

            imageUrl: $image,

            sourceLanguage: 'fr',

            occurrences: [
                new ParsedOccurrence(
                    $start,
                    $end
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
                'main article, main',
                $fetched->finalUrl
            ),

            imageValidationReason: $imageValidationReason,
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

    private function listingImage(
        Crawler $crawler,
        string $baseUrl
    ): ?string {
        $image =
            $this->attr(
                $crawler,
                'img[src]',
                'src'
            );

        if ($image === null) {
            return null;
        }

        try {
            return $this->urls->absolute(
                $image,
                $baseUrl
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
