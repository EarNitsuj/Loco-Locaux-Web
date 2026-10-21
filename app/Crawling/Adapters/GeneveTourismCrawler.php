<?php

namespace App\Crawling\Adapters;

use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\RawEvent;
use App\Crawling\Documents\PdfEvidenceExtractor;
use App\Crawling\Fetchers\HttpPageFetcher;
use App\Crawling\Fetchers\ImageValidator;
use App\Crawling\Support\UrlNormalizer;
use App\Models\EventSource;
use DOMElement;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use App\Crawling\Assets\AssetRole;
use App\Crawling\Assets\EventAssetExtractor;

class GeneveTourismCrawler implements CommuneCrawler
{
    public function __construct(
        private readonly HttpPageFetcher $fetcher,
        private readonly UrlNormalizer $urls,
        private readonly ImageValidator $images,
        private readonly PdfEvidenceExtractor $pdfs,
        private readonly EventAssetExtractor $assets,
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
        | Geneva Tourism TYPO3/Solr feed
        |--------------------------------------------------------------------------
        |
        | The normal agenda page contains only the search shell.
        |
        | The browser fetches the actual event cards through:
        |
        | ?type=1711534501&tx_solr[q]=*
        |
        | The response advertises itself as text/plain even though the body is
        | an HTML fragment, so we intentionally do not use HttpPageFetcher::get().
        |
        */

        $solrType = (int) (
            $configuration['solr_type']
            ?? 1711534501
        );

        $solrQuery = (string) (
            $configuration['solr_query']
            ?? '*'
        );

        $response = Http::withHeaders([
            'User-Agent' => (string) config(
                'crawling.user_agent',
                'LocoLocauxCrawler/1.0'
            ),

            'Accept-Language' => (string) config(
                'crawling.accept_language',
                'fr-CH,fr;q=0.9,en;q=0.8'
            ),

            'Accept' => 'text/plain,text/html;q=0.9,*/*;q=0.8',
        ])
            ->retry(2, 250)
            ->connectTimeout(10)
            ->timeout(30)
            ->get(
                $source->listing_url,
                [
                    'type' => $solrType,

                    'tx_solr' => [
                        'q' => $solrQuery,
                    ],
                ]
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                sprintf(
                    'Geneva Tourism Solr request failed with HTTP %d.',
                    $response->status()
                )
            );
        }

        $body = $response->body();

        if (trim($body) === '') {
            throw new RuntimeException(
                'Geneva Tourism Solr response was empty.'
            );
        }

        $crawler = new Crawler(
            $body,
            $source->listing_url
        );

        /*
        |--------------------------------------------------------------------------
        | Event links
        |--------------------------------------------------------------------------
        |
        | Each Geneva Tourism card currently exposes the event URL twice:
        |
        | - hidden "En savoir plus" button
        | - stretched-link
        |
        | Deduplicate by canonical URL.
        |
        */

        $references = [];
        $seen = [];

        foreach (
            $crawler->filter('a[href*="/fr/evenements/"]')
            as $node
        ) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = html_entity_decode(
                trim(
                    $node->getAttribute('href')
                ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($href === '') {
                continue;
            }

            try {
                $url = $this->urls->absolute(
                    $href,
                    $source->listing_url
                );

                $url = $this->urls->canonical(
                    $url
                );
            } catch (\Throwable) {
                continue;
            }

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $references[] = new EventReference(
                url: $url,

                externalId: substr(
                    hash('sha256', $url),
                    0,
                    32
                ),

                listingUrl: $source->listing_url,

                titleHint: null,

                metadata: [
                    'listing_url' =>
                    $source->listing_url,

                    'discovery_method' =>
                    'typo3-solr',

                    'solr_type' =>
                    $solrType,
                ],
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
        $configuration = is_array(
            $source->configuration
        )
            ? $source->configuration
            : [];

        /*
        |--------------------------------------------------------------------------
        | Rendered detail page
        |--------------------------------------------------------------------------
        |
        | This is the PRIMARY evidence source.
        |
        */

        $page = $this->fetcher->get(
            $reference->url,
            $this->allowedHosts(
                $source,
                $reference->url
            )
        );

        $crawler = new Crawler(
            $page->body,
            $page->finalUrl
        );

        $eventCrawler = $this->eventCrawler(
            $crawler,
            $configuration
        );

        if ($eventCrawler === null) {
            throw new RuntimeException(
                "Unable to locate Geneva Tourism event content: {$reference->url}"
            );
        }

        $detailHtml = $this->outerHtml(
            $eventCrawler
        );

        if (
            trim(
                strip_tags($detailHtml)
            ) === ''
        ) {
            throw new RuntimeException(
                "Geneva Tourism event content is empty: {$reference->url}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Title
        |--------------------------------------------------------------------------
        |
        | Geneva Tourism currently uses the first h2.element-header as the
        | event title. There is no h1 on the event page.
        |
        */

        $title = $this->title(
            $eventCrawler
        );

        /*
        |--------------------------------------------------------------------------
        | Rendered textual evidence
        |--------------------------------------------------------------------------
        */

        $description = $this->cleanText(
            $eventCrawler->text('')
        );

        /*
        |--------------------------------------------------------------------------
        | Location evidence
        |--------------------------------------------------------------------------
        |
        | Confirmed structure:
        |
        | .content-line-wrapper
        |     h5 = "Nom du lieu"
        |     value column
        |
        | .content-line-wrapper
        |     h5 = "Adresse"
        |     value column
        |
        */

        $venue = $this->labelledValue(
            $eventCrawler,
            'Nom du lieu'
        );

        $address = $this->labelledValue(
            $eventCrawler,
            'Adresse'
        );

        $organizer = $this->labelledValue(
            $eventCrawler,
            'Proposé par'
        );

        $coordinates = $this->coordinates(
            $eventCrawler
        );

        /*
        |--------------------------------------------------------------------------
        | Images
        |--------------------------------------------------------------------------
        |
        | Important:
        |
        | Raw HTML preservation does NOT depend on ImageValidator.
        |
        | The complete event section survives untouched.
        |
        | ImageValidator determines only the convenience imageUrl.
        |
        */

        /*
|--------------------------------------------------------------------------
| Classified event assets
|--------------------------------------------------------------------------
|
| Every image inside the event-specific DOM is preserved and classified by
| the shared asset layer.
|
| Only EventMedia and Unknown assets are allowed to participate in hero
| selection.
|
| Accessibility, practical-information, branding and UI images remain
| evidence but are not hero candidates.
|
*/

        $classifiedAssets = $this->assets->extract(
            $eventCrawler,
            $page->finalUrl
        );

        $imageCandidates = [];

        foreach ($classifiedAssets as $asset) {
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
                'origin' => 'event-detail-page',
            ];
        }

        /*
        | Preserve event-specific OpenGraph image as supplementary evidence.
        */

        $ogImages = $crawler->filter(
            'meta[property="og:image"][content]'
        );

        foreach ($ogImages as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $content = trim(
                $node->getAttribute('content')
            );

            if ($content === '') {
                continue;
            }

            try {
                $url = $this->urls->absolute(
                    $content,
                    $page->finalUrl
                );
            } catch (\Throwable) {
                continue;
            }

            $imageCandidates[] = [
                'url' => $url,
                'origin' => 'open-graph',
            ];
        }

        $imageCandidates = $this->uniqueCandidates(
            $imageCandidates
        );

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
        | Documents
        |--------------------------------------------------------------------------
        */

        $documents = $this->documentsFromCrawler(
            $eventCrawler,
            $page->finalUrl
        );

        $documentEvidence = [];
        $documentExtractionErrors = [];

        foreach ($documents as $document) {
            try {
                $evidence = $this->pdfs->extract(
                    $document['url'],
                    $this->allowedHosts(
                        $source,
                        $document['url']
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
            } catch (\Throwable $exception) {
                $documentExtractionErrors[] = [
                    'url' =>
                    $document['url'],

                    'message' =>
                    $exception->getMessage(),
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Build raw_html
        |--------------------------------------------------------------------------
        */

        $rawHtml =
            '<article'
            . ' data-crawl-adapter="geneve-tourism"'
            . '>';

        /*
        | PRIMARY evidence:
        | complete event-specific Geneva Tourism section.
        */

        $rawHtml .=
            '<section data-crawl-source="event-detail-page">'
            . $detailHtml
            . '</section>';

        /*
        |--------------------------------------------------------------------------
        | Explicit image candidates
        |--------------------------------------------------------------------------
        */

        /*
|--------------------------------------------------------------------------
| Classified image evidence
|--------------------------------------------------------------------------
|
| Preserve every image found in the event-specific DOM, but explicitly tell
| downstream normalization what kind of asset it is.
|
*/

        if (
            $classifiedAssets !== []
            || $imageCandidates !== []
        ) {
            $rawHtml .=
                '<section data-crawl-source="image-evidence">';

            /*
            | Images from the rendered event DOM.
            */

            foreach ($classifiedAssets as $asset) {
                $alt = match ($asset->role) {
                    AssetRole::EventMedia =>
                    $title ?? '',

                    default =>
                    $asset->label ?? '',
                };

                $rawHtml .=
                    '<img'
                    . ' src="'
                    . e($asset->url)
                    . '"'
                    . ' alt="'
                    . e($alt)
                    . '"'
                    . ' data-crawl-role="'
                    . e(
                        $this->crawlRole(
                            $asset->role
                        )
                    )
                    . '"'
                    . ' data-crawl-asset-role="'
                    . e($asset->role->value)
                    . '"'
                    . ' data-crawl-origin="event-detail-page"'
                    . ' data-crawl-classification-reason="'
                    . e($asset->reason ?? '')
                    . '"';

                if (
                    is_string($asset->semanticKey)
                    && $asset->semanticKey !== ''
                ) {
                    $rawHtml .=
                        ' data-crawl-semantic-key="'
                        . e($asset->semanticKey)
                        . '"';
                }

                if (
                    is_string($asset->label)
                    && $asset->label !== ''
                ) {
                    $rawHtml .=
                        ' data-crawl-label="'
                        . e($asset->label)
                        . '"';
                }

                $rawHtml .= '>';
            }

            /*
            |--------------------------------------------------------------------------
            | OpenGraph images
            |--------------------------------------------------------------------------
            |
            | These do not exist as <img> elements inside the event DOM, so they
            | cannot be classified by EventAssetClassifier.
            |
            | Preserve them as supplementary event-image candidates.
            |
            */

            foreach ($imageCandidates as $candidate) {
                if (
                    ($candidate['origin'] ?? null)
                    !== 'open-graph'
                ) {
                    continue;
                }

                $rawHtml .=
                    '<img'
                    . ' src="'
                    . e($candidate['url'])
                    . '"'
                    . ' alt="'
                    . e($title ?? '')
                    . '"'
                    . ' data-crawl-role="event-image-candidate"'
                    . ' data-crawl-asset-role="unknown"'
                    . ' data-crawl-origin="open-graph"'
                    . '>';
            }

            $rawHtml .= '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Document candidates
        |--------------------------------------------------------------------------
        */

        if ($documents !== []) {
            $rawHtml .=
                '<section data-crawl-source="document-candidates">';

            foreach ($documents as $document) {
                $rawHtml .=
                    '<a'
                    . ' href="'
                    . e($document['url'])
                    . '"'
                    . ' data-crawl-role="event-document"'
                    . '>'
                    . e(
                        $document['label']
                            ?: 'Document'
                    )
                    . '</a>';
            }

            $rawHtml .= '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Extracted PDF evidence
        |--------------------------------------------------------------------------
        */

        foreach ($documentEvidence as $evidence) {
            $rawHtml .=
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
                $rawHtml .=
                    '<h3 data-crawl-role="document-label">'
                    . e($evidence['label'])
                    . '</h3>';
            }

            $rawHtml .=
                '<pre data-crawl-role="document-text">'
                . e($evidence['text'])
                . '</pre>';

            $rawHtml .= '</section>';
        }

        $rawHtml .= '</article>';

        /*
        |--------------------------------------------------------------------------
        | RawEvent
        |--------------------------------------------------------------------------
        |
        | Do not guess dates or commune here.
        |
        | The rendered page contains the date evidence.
        |
        | CommuneResolver will later consume venue/address/coordinates.
        |
        */

        return new RawEvent(
            canonicalUrl: $this->urls->canonical(
                $page->finalUrl
            ),

            rawHtml: $rawHtml,

            externalId: $reference->externalId,

            title: $title,

            startsAt: null,

            endsAt: null,

            isRecurring: false,

            recurrenceSummary: null,

            description: $description,

            imageUrl: $image,

            sourceLanguage: (string) (
                $configuration['source_language']
                ?? 'fr'
            ),

            sourcePublishedAt: null,

            occurrences: [],

            metadata: [
                'listing_url' =>
                $reference->listingUrl
                    ?? $source->listing_url,

                /*
                |--------------------------------------------------------------------------
                | Location evidence for future CommuneResolver
                |--------------------------------------------------------------------------
                */

                'venue' =>
                $venue,

                'address' =>
                $address,

                'coordinates' =>
                $coordinates,

                'organizer' =>
                $organizer,

                'image_candidates' =>
                $imageCandidates,

                'assets' =>
                array_map(
                    static fn($asset): array => [
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

                'document_extraction_errors' =>
                $documentExtractionErrors,
            ],

            imageValidationReason: $this->images->lastResult()?->reason,
        );
    }

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

            'main section.venue-detail.event-detail',
            'section.venue-detail.event-detail',
        ];

        foreach ($selectors as $selector) {
            try {
                $nodes = $crawler->filter(
                    $selector
                );
            } catch (\Throwable) {
                continue;
            }

            if ($nodes->count() === 0) {
                continue;
            }

            $node = $nodes->first();

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

    private function title(
        Crawler $eventCrawler
    ): ?string {
        $headings = $eventCrawler->filter(
            'h2.element-header'
        );

        if ($headings->count() === 0) {
            return null;
        }

        return $this->cleanText(
            $headings->first()->text('')
        );
    }

    private function labelledValue(
        Crawler $crawler,
        string $label
    ): ?string {
        foreach (
            $crawler->filter('.content-line-wrapper')
            as $row
        ) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $rowCrawler = new Crawler($row);

            $labels = $rowCrawler->filter('h5');

            if ($labels->count() === 0) {
                continue;
            }

            $currentLabel = $this->cleanText(
                $labels->first()->text('')
            );

            if (
                $currentLabel === null
                || mb_strtolower($currentLabel)
                !== mb_strtolower($label)
            ) {
                continue;
            }

            /*
            | Geneva Tourism uses Bootstrap columns:
            |
            | first column  = label
            | second column = value
            |
            | Use the last element child as the value so the implementation
            | remains tolerant of slightly different column widths.
            */

            $children = [];

            foreach ($row->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $children[] = $child;
                }
            }

            if (count($children) >= 2) {
                $value = $this->cleanText(
                    $children[array_key_last($children)]->textContent
                );

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{url:string,origin:string}>
     */
    private function uniqueCandidates(
        array $candidates
    ): array {
        $result = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $url = $candidate['url'] ?? null;

            if (
                ! is_string($url)
                || trim($url) === ''
            ) {
                continue;
            }

            $url = trim($url);

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $result[] = [
                'url' =>
                $url,

                'origin' =>
                (string) (
                    $candidate['origin']
                    ?? 'unknown'
                ),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{url:string,label:string}>
     */
    private function documentsFromCrawler(
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
                    $node->getAttribute('href')
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

            $documents[] = [
                'url' =>
                $url,

                'label' =>
                $this->cleanText(
                    $node->textContent
                )
                    ?? '',
            ];
        }

        return $documents;
    }

    /**
     * @return array{lat:float,lng:float}|null
     */
    private function coordinates(
        Crawler $crawler
    ): ?array {
        foreach (
            $crawler->filter(
                '[data-lat][data-lng],'
                    . '[data-latitude][data-longitude],'
                    . '[data-latitude][data-long]'
            )
            as $node
        ) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $lat =
                $node->getAttribute('data-lat')
                ?: $node->getAttribute('data-latitude');

            $lng =
                $node->getAttribute('data-lng')
                ?: $node->getAttribute('data-longitude')
                ?: $node->getAttribute('data-long');

            if (
                ! is_numeric($lat)
                || ! is_numeric($lng)
            ) {
                continue;
            }

            return [
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
        }

        return null;
    }

    private function outerHtml(
        Crawler $crawler
    ): string {
        $node = $crawler->getNode(0);

        if ($node === null) {
            return '';
        }

        $document = $node->ownerDocument;

        if ($document === null) {
            return '';
        }

        return $document->saveHTML(
            $node
        ) ?: '';
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

        $value = preg_replace(
            '/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/iu',
            "$0\n",
            $value
        ) ?: $value;

        $value = html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $value = preg_replace(
            '/[ \t]+/u',
            ' ',
            $value
        ) ?: $value;

        $value = preg_replace(
            '/[ \t]*\n[ \t]*/u',
            "\n",
            $value
        ) ?: $value;

        $value = trim(
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

    private function crawlRole(
        AssetRole $role
    ): string {
        return match ($role) {
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
     * @return list<string>
     */
    private function allowedHosts(
        EventSource $source,
        string $requestUrl
    ): array {
        $configuration = is_array(
            $source->configuration
        )
            ? $source->configuration
            : [];

        $configured = is_array(
            $configuration['allowed_hosts']
                ?? null
        )
            ? $configuration['allowed_hosts']
            : [];

        return array_values(
            array_unique(
                array_filter([
                    parse_url(
                        $source->listing_url,
                        PHP_URL_HOST
                    ),

                    parse_url(
                        $requestUrl,
                        PHP_URL_HOST
                    ),

                    ...$configured,
                ], 'is_string')
            )
        );
    }
}
