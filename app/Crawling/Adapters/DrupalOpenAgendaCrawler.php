<?php

namespace App\Crawling\Adapters;

use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\ParsedOccurrence;
use App\Crawling\Data\RawEvent;
use App\Crawling\Exceptions\CrawlerParseException;
use App\Models\EventSource;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;
use App\Crawling\Documents\PdfEvidenceExtractor;
use DOMElement;

class DrupalOpenAgendaCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
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
            $fetched = $this->fetcher->get($url, $this->allowedHosts($source));
            $crawler = $this->crawler($fetched->body, $fetched->finalUrl);

            foreach ($this->discoverPage($crawler, $fetched->finalUrl) as $reference) {
                $deduplicationKey = $reference->externalId === null
                    ? $reference->hash()
                    : 'external-id:' . $reference->externalId;

                if (isset($seenReferences[$deduplicationKey])) {
                    continue;
                }

                $seenReferences[$deduplicationKey] = true;
                $references[] = $reference;

                if (! $options->all && $options->eventLimit !== null && count($references) >= $options->eventLimit) {
                    return $references;
                }
            }

            $href = $this->attr($crawler, 'a[rel="next"], .pager__item--next a, .pagination .next a', 'href');
            $url = $href === null ? null : $this->urls->absolute($href, $fetched->finalUrl);
        }

        return $references;
    }

    public function fetch(EventSource $source, EventReference $reference): RawEvent
    {
        $fetched = $this->fetcher->get($reference->url, $this->allowedHosts($source));
        $crawler = $this->crawler($fetched->body, $fetched->finalUrl);
        $canonical = $this->urls->canonical(
            $this->attr($crawler, 'link[rel="canonical"]', 'href')
                ?? $this->attr($crawler, 'meta[property="og:url"]', 'content')
                ?? $fetched->finalUrl,
        );
        $title = $this->attr($crawler, 'meta[name="title"]', 'content')
            ?? $this->text($crawler, '.oa-event--single .oa-event-title, main h1, h1')
            ?? $reference->titleHint;

        if ($title === null) {
            throw new CrawlerParseException("No OpenAgenda event title found for {$canonical}");
        }

        $occurrences = $this->occurrences($crawler);
        $rangeText = $this->text($crawler, '.oa-event--single > .border-bottom h4, .oa-event--single .oa-event-daterange, .oa-event--single .oa-event-date')
            ?? $reference->dateText;
        $overall = $this->dates->parseDateText($rangeText);
        $first = $occurrences[0] ?? null;
        $last = $occurrences === [] ? null : $occurrences[array_key_last($occurrences)];
        /*
        |--------------------------------------------------------------------------
        | Primary event evidence
        |--------------------------------------------------------------------------
        */

        $raw = $this->rawFragment(
            $crawler,
            '.oa-event--single, article.oa-event, main article'
        );

        if ($raw === '') {
            throw new CrawlerParseException(
                "Unable to isolate OpenAgenda event content for {$canonical}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Classified event assets
        |--------------------------------------------------------------------------
        */

        $eventCrawler = new Crawler(
            $raw,
            $fetched->finalUrl
        );

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
        | Event-local <img> elements are already handled by EventAssetExtractor.
        |
        | OpenGraph and listing images are explicit supplementary hints which may
        | exist outside the isolated event DOM.
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
            $url = $candidate['url'] ?? null;

            if (
                ! is_string($url)
                || trim($url) === ''
            ) {
                continue;
            }

            try {
                $url = $this->urls->absolute(
                    $url,
                    $fetched->finalUrl
                );
            } catch (\Throwable) {
                continue;
            }

            $imageCandidates[] = [
                'url' => $url,
                'origin' => $candidate['origin'],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Deduplicate candidates
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
                trim($candidate['url'])
            )
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Convenience imageUrl
        |--------------------------------------------------------------------------
        */

        $image = null;

        foreach ($imageCandidates as $candidate) {
            try {
                $validated = $this->images->validate(
                    $candidate['url']
                );

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
        | Classified raw image evidence
        |--------------------------------------------------------------------------
        */

        $imageEvidenceHtml = $this->assetEvidenceHtml(
            $classifiedAssets,
            $title
        );

        /*
        |--------------------------------------------------------------------------
        | Supplementary image evidence
        |--------------------------------------------------------------------------
        */

        $classifiedAssetUrls = array_fill_keys(
            array_map(
                static fn($asset): string =>
                $asset->url,
                $classifiedAssets
            ),
            true
        );

        $supplementaryImageHtml = '';

        foreach ($imageCandidates as $candidate) {
            $url = $candidate['url'] ?? null;

            if (
                ! is_string($url)
                || isset($classifiedAssetUrls[$url])
            ) {
                continue;
            }

            $supplementaryImageHtml .=
                '<img'
                . ' src="' . e($url) . '"'
                . ' alt="' . e($title) . '"'
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
        | Linked PDF documents
        |--------------------------------------------------------------------------
        |
        | Discover PDFs only inside the isolated event DOM.
        |
        | The original links remain untouched in the event HTML even if extraction
        | fails.
        |
        */

        $documents = $this->documentCandidates(
            $eventCrawler,
            $fetched->finalUrl
        );

        $documentEvidence = [];

        if ($documents !== []) {
            $pdfs = app(
                PdfEvidenceExtractor::class
            );

            foreach ($documents as $document) {
                try {
                    /*
                    | OpenAgenda documents are commonly hosted on:
                    |
                    | img.openagenda.com
                    | cdn.openagenda.com
                    |
                    | Include the actual document host in addition to the normal
                    | source allow-list.
                    */

                    $documentHost = parse_url(
                        $document['url'],
                        PHP_URL_HOST
                    );

                    $documentAllowedHosts = array_values(
                        array_unique([
                            ...$this->allowedHosts(
                                $source
                            ),

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
                    | PDF extraction must never cause the event crawl itself to fail.
                    */
                    continue;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Document candidates
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
        | Final raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml =
            '<article data-crawl-adapter="drupal-openagenda">'
            . '<section data-crawl-source="event-content">'
            . $raw
            . '</section>'
            . $imageEvidenceHtml
            . $supplementaryImageHtml
            . $documentCandidateHtml
            . $documentEvidenceHtml
            . '</article>';

        return new RawEvent(
            canonicalUrl: $canonical,
            rawHtml: $rawHtml,
            externalId: $reference->externalId ?? $this->externalId($crawler, $canonical),
            title: $title,
            startsAt: $overall?->startsAt ?? $first?->startsAt,
            endsAt: $overall?->endsAt ?? $last?->endsAt ?? $last?->startsAt,
            isRecurring: count($occurrences) > 1,
            recurrenceSummary: count($occurrences) > 1 ? $rangeText : null,
            description: $this->attr($crawler, 'meta[name="description"]', 'content')
                ?? $this->cleanBlockText($crawler, '.oa-event--single .to-speech, .oa-event--single .section-text, .oa-event-description'),
            imageUrl: $image,
            sourceLanguage: (string) (($source->configuration ?? [])['source_language'] ?? 'fr'),
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
            canonicalDescriptionHtml: $this->cleanBlockHtml($crawler, '.oa-event--single .to-speech, .oa-event--single .section-text, .oa-event-description', $fetched->finalUrl),
            imageValidationReason: $this->images->lastResult()?->reason,
        );
    }

    /** @return list<EventReference> */
    private function discoverPage(Crawler $crawler, string $pageUrl): array
    {
        $items = $crawler->filter('#oa-agenda article[id^="event--"], #oa-agenda a[id^="event--"], .oa-agenda article[id^="event--"], .oa-agenda .oa-event--list[id^="event--"]');

        return collect($items->each(function (Crawler $item) use ($pageUrl): ?EventReference {
            $href = $item->nodeName() === 'a'
                ? $item->attr('href')
                : $this->attr($item, 'a[href]', 'href');

            if ($href === null) {
                return null;
            }

            $id = $item->attr('id');
            $image = $this->attr($item, 'img[src]', 'src') ?? $this->backgroundImage($item->html(''));

            return new EventReference(
                url: $this->urls->canonical($this->urls->absolute($href, $pageUrl)),
                externalId: preg_match('/event--(\d+)/', $id, $match) === 1 ? $match[1] : null,
                listingUrl: $pageUrl,
                titleHint: $this->text($item, '.card-title, .oa-event-title, h2, h3, h4, h5'),
                dateText: $this->text($item, '.card-text, .card-subtitle, .oa-event-daterange, time'),
                imageUrl: $image === null ? null : $this->urls->absolute($image, $pageUrl),
            );
        }))->filter()->values()->all();
    }

    /** @return list<ParsedOccurrence> */
    private function occurrences(Crawler $crawler): array
    {
        $occurrences = [];

        foreach ($crawler->filter('.carousel-oa-dates li.list-group-item, .oa-dates li, .oa-event-dates li') as $node) {
            $item = new Crawler($node);
            $parts = $item->children()->each(fn(Crawler $child): string => trim($child->text('')));
            $date = $parts[0] ?? null;
            $times = $parts[1] ?? '';

            if ($date === null || preg_match('/(\d{1,2})[.\/]([0-1]?\d)[.\/](\d{4})/', $date, $dateMatch) !== 1) {
                continue;
            }

            preg_match('/(\d{1,2})[:h](\d{2})(?:\s*-\s*(\d{1,2})[:h](\d{2}))?/', $times, $timeMatch);
            $start = CarbonImmutable::create(
                (int) $dateMatch[3],
                (int) $dateMatch[2],
                (int) $dateMatch[1],
                isset($timeMatch[1]) ? (int) $timeMatch[1] : 0,
                isset($timeMatch[2]) ? (int) $timeMatch[2] : 0,
                0,
                'Europe/Zurich',
            );
            $end = isset($timeMatch[3])
                ? $start->setTime((int) $timeMatch[3], (int) $timeMatch[4])
                : null;
            $occurrences[(new ParsedOccurrence($start, $end))->key()] = new ParsedOccurrence($start, $end);
        }

        return array_values($occurrences);
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

            $documents[] = [
                'url' =>
                $url,

                'label' =>
                $label,
            ];
        }

        return $documents;
    }

    /** @return list<string> */
    private function allowedHosts(EventSource $source): array
    {
        $configuration = is_array($source->configuration) ? $source->configuration : [];
        $host = parse_url($source->listing_url, PHP_URL_HOST);

        return array_values(array_filter([
            is_string($host) ? $host : null,
            ...is_array($configuration['allowed_hosts'] ?? null) ? $configuration['allowed_hosts'] : [],
        ], 'is_string'));
    }

    private function backgroundImage(string $html): ?string
    {
        return preg_match('/background-image:\s*url\(([^)]+)\)/i', $html, $match) === 1
            ? trim($match[1], '\'" ')
            : null;
    }

    private function externalId(Crawler $crawler, string $url): ?string
    {
        $id = $crawler->filter('.oa-event[id^="event-"]')->count() > 0
            ? $crawler->filter('.oa-event[id^="event-"]')->first()->attr('id')
            : $url;

        return preg_match('/event-?-?(\d{5,})|\/(\d{5,})_/', $id, $match) === 1
            ? ($match[1] !== '' ? $match[1] : $match[2])
            : null;
    }
}
