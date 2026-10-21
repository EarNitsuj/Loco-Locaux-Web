<?php

namespace App\Crawling\Adapters;

use App\Crawling\Assets\AssetRole;
use App\Crawling\Assets\ClassifiedAsset;
use App\Crawling\Assets\EventAssetExtractor;
use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\RawEvent;
use App\Crawling\Fetchers\HttpPageFetcher;
use App\Crawling\Support\UrlNormalizer;
use App\Models\EventSource;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

class ChoulexActualitesCrawler implements CommuneCrawler
{
    public function __construct(
        private readonly HttpPageFetcher $fetcher,
        private readonly UrlNormalizer $urls,
        private readonly EventAssetExtractor $assets,
    ) {}

    public function discover(
        EventSource $source,
        CrawlOptions $options
    ): iterable {
        $page = $this->fetcher->get(
            $source->listing_url,
            $this->allowedHosts(
                $source,
                $source->listing_url
            )
        );

        $crawler = new Crawler(
            $page->body,
            $page->finalUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Choulex Actualités cards
        |--------------------------------------------------------------------------
        |
        | Choulex does not expose a conventional event agenda.
        |
        | The Actualités grid contains a mixture of:
        |
        | - events;
        | - activities;
        | - announcements;
        | - municipal information;
        | - roadworks;
        | - public notices;
        | - other commune updates.
        |
        | The crawler must NOT decide which cards are events.
        |
        | Every Actualités card containing a usable title is preserved as a
        | source record. Classification belongs to the later normalization /
        | enrichment layer.
        |
        */

        $cards = $crawler->filter(
            '#features .h-column.h-col-lg-6.h-col-md-6.h-col-12'
        );

        $references = [];
        $seen = [];

        foreach ($cards as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $card = new Crawler(
                $node,
                $page->finalUrl
            );

            $title = $this->title(
                $card
            );

            if ($title === null) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Card evidence
            |--------------------------------------------------------------------------
            |
            | Choulex cards normally already contain:
            |
            | - title;
            | - meaningful description;
            | - dates/times when relevant;
            | - an image;
            | - a PDF or external webpage link when additional material exists.
            |
            | Therefore discovery does not need to open the linked resource.
            |
            */

            $description = $this->description(
                $card
            );

            $cardText = $this->cleanText(
                $card->text('')
            );

            $primaryUrl = $this->primaryUrl(
                $card,
                $page->finalUrl
            );

            /*
            | Many Choulex cards link directly to:
            |
            | - PDF flyers;
            | - external webpages;
            | - campaign / information pages.
            |
            | These URLs remain valid source destinations. They are preserved
            | without being fetched during this adapter's crawl.
            */

            $canonicalUrl =
                $primaryUrl
                ?? $page->finalUrl;

            /*
            |--------------------------------------------------------------------------
            | Stable source identity
            |--------------------------------------------------------------------------
            |
            | Keep the existing Choulex identity algorithm so previously
            | persisted SourceEvents continue matching future observations.
            |
            */

            $externalId = substr(
                hash(
                    'sha256',
                    mb_strtolower($title)
                        . '|'
                        . $canonicalUrl
                ),
                0,
                32
            );

            if (isset($seen[$externalId])) {
                continue;
            }

            $seen[$externalId] = true;

            $references[] = new EventReference(
                url: $this->urls->canonical(
                    $canonicalUrl
                ),

                externalId: $externalId,

                listingUrl: $page->finalUrl,

                titleHint: $title,

                metadata: [
                    'card_html' =>
                    $this->outerHtml(
                        $card
                    ),

                    'card_text' =>
                    $cardText,

                    'card_description' =>
                    $description,

                    'primary_url' =>
                    $primaryUrl,

                    'listing_url' =>
                    $page->finalUrl,
                ],
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
        $configuration = is_array(
            $source->configuration
        )
            ? $source->configuration
            : [];

        $cardHtml = is_string(
            $reference->metadata['card_html']
                ?? null
        )
            ? $reference->metadata['card_html']
            : '';

        $cardText = is_string(
            $reference->metadata['card_text']
                ?? null
        )
            ? $reference->metadata['card_text']
            : null;

        $cardDescription = is_string(
            $reference->metadata['card_description']
                ?? null
        )
            ? $reference->metadata['card_description']
            : null;

        $primaryUrl = is_string(
            $reference->metadata['primary_url']
                ?? null
        )
            ? $reference->metadata['primary_url']
            : null;

        $listingUrl = is_string(
            $reference->metadata['listing_url']
                ?? null
        )
            ? $reference->metadata['listing_url']
            : $source->listing_url;

        $title = $reference->titleHint;

        $card = new Crawler(
            $cardHtml,
            $listingUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Classified image evidence
        |--------------------------------------------------------------------------
        |
        | Preserve every image discovered inside the actual Choulex card.
        |
        | IMPORTANT:
        |
        | Do not make additional HTTP requests here merely to validate the
        | card's image. The URL came directly from the successfully fetched
        | Choulex source page and remains preserved as evidence.
        |
        | EventMedia + Unknown are eligible for the convenience imageUrl.
        |
        */

        $classifiedAssets = $this->assets->extract(
            $card,
            $listingUrl
        );

        $imageCandidates = $this->eventImageCandidates(
            $classifiedAssets
        );

        /*
        |--------------------------------------------------------------------------
        | Convenience image
        |--------------------------------------------------------------------------
        |
        | Choulex already exposes the card image directly in the listing.
        |
        | Avoid ImageValidator here because that would create another remote
        | request per record and unnecessarily slow a listing-card crawler.
        |
        */

        $image = $imageCandidates[0]['url']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | PDF/document links
        |--------------------------------------------------------------------------
        |
        | Preserve the PDF URL only.
        |
        | DO NOT download it.
        | DO NOT run pdftotext.
        | DO NOT perform visual/AI analysis here.
        |
        | The card itself already contains sufficient source evidence.
        |
        */

        $documents = $this->documentCandidates(
            $card,
            $listingUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Other related links
        |--------------------------------------------------------------------------
        */

        $links = $this->linkCandidates(
            $card,
            $listingUrl
        );

        /*
        |--------------------------------------------------------------------------
        | raw_html
        |--------------------------------------------------------------------------
        |
        | The complete Choulex card is primary evidence.
        |
        | Additional sections expose normalized evidence references without
        | triggering additional network requests.
        |
        */

        $rawHtml =
            '<article'
            . ' data-crawl-adapter="choulex-actualites"'
            . '>';

        /*
        | PRIMARY:
        | Complete original Actualités card.
        */

        $rawHtml .=
            '<section data-crawl-source="event-content">'
            . $cardHtml
            . '</section>';

        /*
        |--------------------------------------------------------------------------
        | Classified image evidence
        |--------------------------------------------------------------------------
        */

        if ($classifiedAssets !== []) {
            $rawHtml .=
                '<section data-crawl-source="image-evidence">'
                . $this->assetEvidenceHtml(
                    $classifiedAssets,
                    $title
                )
                . '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Document references
        |--------------------------------------------------------------------------
        |
        | Only preserve links.
        |
        | No linked-document / document-text section is generated here.
        |
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
                    . ' data-crawl-document-type="pdf"'
                    . '>'
                    . e(
                        $document['label']
                            ?: $title
                            ?: 'Document'
                    )
                    . '</a>';
            }

            $rawHtml .= '</section>';
        }

        /*
        |--------------------------------------------------------------------------
        | Other related links
        |--------------------------------------------------------------------------
        */

        if ($links !== []) {
            $rawHtml .=
                '<section data-crawl-source="related-links">';

            foreach ($links as $link) {
                $rawHtml .=
                    '<a'
                    . ' href="'
                    . e($link['url'])
                    . '"'
                    . ' data-crawl-role="event-related-link"'
                    . '>'
                    . e(
                        $link['label']
                            ?: $link['url']
                    )
                    . '</a>';
            }

            $rawHtml .= '</section>';
        }

        $rawHtml .= '</article>';

        /*
        |--------------------------------------------------------------------------
        | Do not invent structured dates or content classification
        |--------------------------------------------------------------------------
        |
        | These source records can represent events or non-event commune
        | updates.
        |
        | The complete source evidence is preserved for later classification.
        |
        */

        return new RawEvent(
            canonicalUrl: $reference->url,

            rawHtml: $rawHtml,

            externalId: $reference->externalId,

            title: $title,

            startsAt: null,

            endsAt: null,

            isRecurring: false,

            recurrenceSummary: null,

            description: $cardDescription
                ?? $cardText,

            imageUrl: $image,

            sourceLanguage: (string) (
                $configuration['source_language']
                ?? 'fr'
            ),

            sourcePublishedAt: null,

            occurrences: [],

            metadata: [
                'listing_url' =>
                $listingUrl,

                'primary_url' =>
                $primaryUrl,

                /*
                | Choulex records are intentionally not classified here.
                */

                'record_classification' =>
                'unclassified',

                'source_record_type' =>
                'actualite',

                'image_candidates' =>
                $imageCandidates,

                'assets' =>
                $this->assetMetadata(
                    $classifiedAssets
                ),

                'documents' =>
                $documents,

                /*
                | Explicitly record that document contents were not fetched.
                */

                'document_evidence' =>
                array_map(
                    static fn(
                        array $document
                    ): array => [
                        'url' =>
                        $document['url'],

                        'label' =>
                        $document['label'],

                        'content_fetched' =>
                        false,

                        'analysis_status' =>
                        'not_requested',
                    ],
                    $documents
                ),

                'related_links' =>
                $links,
            ],

            imageValidationReason: null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Card title
    |--------------------------------------------------------------------------
    */

    private function title(
        Crawler $card
    ): ?string {
        /*
        | Current Choulex cards use h5, but allowing other heading levels makes
        | discovery resilient to minor presentation/template changes.
        */

        foreach (
            [
                'h5',
                'h4',
                'h3',
                'h2',
                'h1',
                'h6',
            ]
            as $selector
        ) {
            $headings = $card->filter(
                $selector
            );

            if ($headings->count() === 0) {
                continue;
            }

            $title = $this->cleanText(
                $headings->first()->text('')
            );

            if ($title !== null) {
                return $title;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Card description
    |--------------------------------------------------------------------------
    |
    | Prefer the dedicated text block rather than storing the full card text
    | as RawEvent.description.
    |
    */

    private function description(
        Crawler $card
    ): ?string {
        $selectors = [
            '.h-text.h-text-component',
            '.h-text-component',
            '.h-text',
        ];

        foreach ($selectors as $selector) {
            $nodes = $card->filter(
                $selector
            );

            if ($nodes->count() === 0) {
                continue;
            }

            $parts = [];

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $text = $this->cleanText(
                    $node->textContent
                );

                if ($text === null) {
                    continue;
                }

                $parts[] = $text;
            }

            $parts = array_values(
                array_unique(
                    $parts
                )
            );

            if ($parts !== []) {
                return implode(
                    "\n\n",
                    $parts
                );
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Canonical/source URL
    |--------------------------------------------------------------------------
    */

    private function primaryUrl(
        Crawler $card,
        string $baseUrl
    ): ?string {
        foreach (
            $card->filter('a[href]')
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

            $lower = strtolower(
                $href
            );

            if (
                str_starts_with(
                    $lower,
                    'mailto:'
                )
                || str_starts_with(
                    $lower,
                    'tel:'
                )
                || str_starts_with(
                    $lower,
                    'javascript:'
                )
                || str_starts_with(
                    $lower,
                    '#'
                )
            ) {
                continue;
            }

            /*
            | A directly linked image is evidence, not the source record's
            | primary destination.
            |
            | PDFs remain valid because Choulex frequently uses a flyer PDF as
            | the card's primary destination.
            */

            if (
                preg_match(
                    '/\.(?:jpe?g|png|webp|gif|avif|svg)(?:$|[?#])/iu',
                    $href
                ) === 1
            ) {
                continue;
            }

            try {
                return $this->urls->absolute(
                    $href,
                    $baseUrl
                );
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Shared asset classification
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<ClassifiedAsset>  $assets
     * @return list<array{url:string,origin:string}>
     */
    private function eventImageCandidates(
        array $assets
    ): array {
        $result = [];
        $seen = [];

        foreach ($assets as $asset) {
            if (! $asset instanceof ClassifiedAsset) {
                continue;
            }

            if (
                $asset->role !== AssetRole::EventMedia
                && $asset->role !== AssetRole::Unknown
            ) {
                continue;
            }

            if (isset($seen[$asset->url])) {
                continue;
            }

            $seen[$asset->url] = true;

            $result[] = [
                'url' =>
                $asset->url,

                'origin' =>
                'choulex-actualite-card',
            ];
        }

        return $result;
    }

    /**
     * @param  list<ClassifiedAsset>  $assets
     */
    private function assetEvidenceHtml(
        array $assets,
        ?string $title
    ): string {
        $html = '';

        foreach ($assets as $asset) {
            if (! $asset instanceof ClassifiedAsset) {
                continue;
            }

            $html .=
                '<img'
                . ' src="'
                . e($asset->url)
                . '"'
                . ' alt="'
                . e(
                    $asset->label
                        ?? $title
                        ?? ''
                )
                . '"'
                . ' data-crawl-role="'
                . e(
                    $this->crawlAssetRole(
                        $asset->role
                    )
                )
                . '"'
                . ' data-crawl-asset-role="'
                . e($asset->role->value)
                . '"'
                . ' data-crawl-origin="choulex-actualite-card"';

            if (
                is_string($asset->reason)
                && trim($asset->reason) !== ''
            ) {
                $html .=
                    ' data-crawl-classification-reason="'
                    . e($asset->reason)
                    . '"';
            }

            if (
                is_string($asset->semanticKey)
                && trim($asset->semanticKey) !== ''
            ) {
                $html .=
                    ' data-crawl-semantic-key="'
                    . e($asset->semanticKey)
                    . '"';
            }

            if (
                is_string($asset->label)
                && trim($asset->label) !== ''
            ) {
                $html .=
                    ' data-crawl-label="'
                    . e($asset->label)
                    . '"';
            }

            $html .= '>';
        }

        return $html;
    }

    /**
     * @param  list<ClassifiedAsset>  $assets
     * @return list<array{
     *     url:string,
     *     role:string,
     *     reason:?string,
     *     semantic_key:?string,
     *     label:?string
     * }>
     */
    private function assetMetadata(
        array $assets
    ): array {
        $result = [];

        foreach ($assets as $asset) {
            if (! $asset instanceof ClassifiedAsset) {
                continue;
            }

            $result[] = [
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
            ];
        }

        return $result;
    }

    private function crawlAssetRole(
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

    /*
    |--------------------------------------------------------------------------
    | PDF documents
    |--------------------------------------------------------------------------
    |
    | Discovery only.
    |
    | No document body is downloaded by this adapter.
    |
    */

    /**
     * @return list<array{
     *     url:string,
     *     label:string
     * }>
     */
    private function documentCandidates(
        Crawler $card,
        string $baseUrl
    ): array {
        $documents = [];
        $indexes = [];

        foreach (
            $card->filter('a[href]')
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

            $label =
                $this->cleanText(
                    $node->textContent
                )
                ?? '';

            if (isset($indexes[$url])) {
                $index = $indexes[$url];

                if (
                    $documents[$index]['label'] === ''
                    && $label !== ''
                ) {
                    $documents[$index]['label'] =
                        $label;
                }

                continue;
            }

            $indexes[$url] =
                count($documents);

            $documents[] = [
                'url' =>
                $url,

                'label' =>
                $label,
            ];
        }

        return $documents;
    }

    /*
    |--------------------------------------------------------------------------
    | Other links
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array{
     *     url:string,
     *     label:string
     * }>
     */
    private function linkCandidates(
        Crawler $card,
        string $baseUrl
    ): array {
        $links = [];
        $seen = [];

        foreach (
            $card->filter('a[href]')
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

            $lower = strtolower(
                $href
            );

            if (
                str_starts_with(
                    $lower,
                    'tel:'
                )
            ) {
                continue;
            }

            /*
            | mailto links remain useful source evidence.
            */

            if (
                str_starts_with(
                    $lower,
                    'mailto:'
                )
            ) {
                $url = $href;
            } else {
                if (
                    str_starts_with(
                        $lower,
                        'javascript:'
                    )
                    || str_starts_with(
                        $lower,
                        '#'
                    )
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
            }

            /*
            | PDFs already have a dedicated evidence section.
            */

            if (
                preg_match(
                    '/\.pdf(?:$|[?#])/iu',
                    $url
                ) === 1
            ) {
                continue;
            }

            /*
            | Direct image assets are preserved by EventAssetExtractor.
            */

            if (
                preg_match(
                    '/\.(?:jpe?g|png|webp|gif|avif|svg)(?:$|[?#])/iu',
                    $url
                ) === 1
            ) {
                continue;
            }

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $links[] = [
                'url' =>
                $url,

                'label' =>
                $this->cleanText(
                    $node->textContent
                )
                    ?? '',
            ];
        }

        return $links;
    }

    /*
    |--------------------------------------------------------------------------
    | HTML
    |--------------------------------------------------------------------------
    */

    private function outerHtml(
        Crawler $crawler
    ): string {
        $node = $crawler->getNode(0);

        if ($node === null) {
            return '';
        }

        $document =
            $node->ownerDocument;

        if ($document === null) {
            return '';
        }

        return $document->saveHTML(
            $node
        ) ?: '';
    }

    /*
    |--------------------------------------------------------------------------
    | Text
    |--------------------------------------------------------------------------
    */

    private function cleanText(
        ?string $value
    ): ?string {
        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        $value = html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        /*
        | Normalize non-breaking spaces.
        */

        $value = str_replace(
            "\u{00A0}",
            ' ',
            $value
        );

        /*
        | Preserve meaningful line boundaries while cleaning horizontal
        | whitespace.
        */

        $value = preg_replace(
            '/[ \t]+/u',
            ' ',
            $value
        ) ?: $value;

        $value = preg_replace(
            "/[ \t]*\r?\n[ \t]*/u",
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

    /*
    |--------------------------------------------------------------------------
    | Allowed hosts
    |--------------------------------------------------------------------------
    */

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
