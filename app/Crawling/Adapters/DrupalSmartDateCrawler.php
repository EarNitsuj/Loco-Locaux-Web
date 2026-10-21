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

class DrupalSmartDateCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $host = parse_url(
            $source->listing_url,
            PHP_URL_HOST
        );

        $allowed = is_string($host)
            ? [$host]
            : [];

        $fetched = $this->fetcher->get(
            $source->listing_url,
            $allowed
        );

        $crawler = $this->crawler(
            $fetched->body,
            $fetched->finalUrl
        );

        $references = [];

        foreach (
            $crawler->filter(
                'main article.card.card-linked'
            )
            as $node
        ) {
            $card = new Crawler(
                $node,
                $fetched->finalUrl
            );

            $href = $this->attr(
                $card,
                '.card-title a[href]',
                'href'
            );

            if ($href === null) {
                continue;
            }

            $url = $this->urls->canonical(
                $this->urls->absolute(
                    $href,
                    $fetched->finalUrl
                )
            );

            $listingImage = $this->attr(
                $card,
                'img[src]',
                'src'
            );

            $references[] = new EventReference(
                url: $url,

                listingUrl: $fetched->finalUrl,

                titleHint: $this->text(
                    $card,
                    '.card-title'
                ),

                dateText: $this->text(
                    $card,
                    '.card-header'
                ),

                imageUrl: $listingImage === null
                    ? null
                    : $this->urls->absolute(
                        $listingImage,
                        $fetched->finalUrl
                    ),
            );

            if (
                ! $options->all
                && $options->eventLimit !== null
                && count($references) >= $options->eventLimit
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

        $canonical = $this->urls->canonical(
            $this->attr(
                $crawler,
                'link[rel="canonical"]',
                'href'
            )
                ?? $fetched->finalUrl
        );

        $title = $this->text(
            $crawler,
            'main h1.page-title, main h1'
        )
            ?? $reference->titleHint;

        if ($title === null) {
            throw new CrawlerParseException(
                "No SmartDate title found for {$canonical}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Occurrences
        |--------------------------------------------------------------------------
        */

        $occurrences = [];

        foreach (
            $crawler->filter(
                'main .event-dates .event-date'
            )
            as $node
        ) {
            $eventDate = new Crawler(
                $node,
                $fetched->finalUrl
            );

            $values = $eventDate
                ->filter('time[datetime]')
                ->each(
                    fn(Crawler $time): string =>
                    (string) $time->attr('datetime')
                );

            $start = $this->parseDate(
                $values[0] ?? null
            );

            $end = $this->parseDate(
                $values[count($values) - 1]
                    ?? null
            );

            if ($start === null) {
                continue;
            }

            $occurrences[] = new ParsedOccurrence(
                $start,
                $end?->lessThan($start)
                    ? null
                    : $end
            );
        }

        if ($occurrences === []) {
            throw new CrawlerParseException(
                "No SmartDate occurrences found for {$canonical}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Primary event evidence
        |--------------------------------------------------------------------------
        */

        $raw = $this->rawFragment(
            $crawler,
            'main'
        );

        if (trim($raw) === '') {
            throw new CrawlerParseException(
                "Unable to isolate SmartDate event content for {$canonical}"
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
        | All event-local image evidence survives.
        |
        | Only event_media and unknown assets participate in hero-image
        | selection. Accessibility/practical/branding/UI images remain evidence
        | but are excluded from hero selection.
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
        |
        | OpenGraph and listing images can live outside the isolated event DOM,
        | so they remain useful supplementary event-image hints.
        |
        */

        $supplementaryCandidates = [
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
                'url' => $candidateUrl,
                'origin' => $candidate['origin'],
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
                        $candidate['url']
                            ?? null
                    )
                    && trim(
                        $candidate['url']
                    ) !== ''
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
        | Validation only determines the convenience imageUrl.
        |
        | It does NOT determine whether image evidence survives into raw_html.
        |
        */

        $image = null;
        $imageValidationReason = null;

        foreach ($imageCandidates as $candidate) {
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
        | Discover PDFs only inside the isolated event content.
        |
        | Extraction failure must never discard the event or original PDF link.
        |
        */

        $documents = $this->documentCandidates(
            $eventCrawler,
            $fetched->finalUrl
        );

        /*
        | Prevent pathological pages from causing excessive PDF downloads.
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

            foreach ($documents as $document) {
                try {
                    $documentHost = parse_url(
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
                    | Keep the original link as evidence even if PDF text
                    | extraction fails.
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
            '<article data-crawl-adapter="drupal-smart-date">'
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
        | RawEvent
        |--------------------------------------------------------------------------
        */

        return new RawEvent(
            canonicalUrl: $canonical,

            rawHtml: $rawHtml,

            title: $title,

            startsAt: $occurrences[0]
                ->startsAt,

            endsAt: $occurrences[count($occurrences) - 1]
                ->endsAt,

            isRecurring: count($occurrences) > 1,

            recurrenceSummary: count($occurrences)
                . ' occurrence(s) publiée(s)',

            description: $this->cleanBlockText(
                $crawler,
                'main .section-content p'
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
                'main .section-content p',
                $fetched->finalUrl
            ),

            imageValidationReason: $imageValidationReason,
        );
    }

    /**
     * Find PDF links belonging to the isolated event content.
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
                $url =
                    $this->urls->absolute(
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
}
