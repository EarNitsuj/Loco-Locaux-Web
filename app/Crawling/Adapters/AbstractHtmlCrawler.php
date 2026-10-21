<?php

namespace App\Crawling\Adapters;

use App\Content\CanonicalContentNormalizer;
use App\Crawling\Fetchers\HttpPageFetcher;
use App\Crawling\Fetchers\ImageValidator;
use App\Crawling\Services\SourceStatusDetector;
use App\Crawling\Support\FrenchDateParser;
use App\Crawling\Support\UrlNormalizer;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;
use App\Crawling\Assets\EventAssetExtractor;
use App\Crawling\Assets\AssetRole;
use App\Crawling\Assets\ClassifiedAsset;

abstract class AbstractHtmlCrawler
{
    public function __construct(
        protected readonly HttpPageFetcher $fetcher,
        protected readonly UrlNormalizer $urls,
        protected readonly FrenchDateParser $dates,
        protected readonly ImageValidator $images,
        protected readonly CanonicalContentNormalizer $canonicalContent,
        protected readonly SourceStatusDetector $sourceStatuses,
        protected readonly EventAssetExtractor $assets,
    ) {}

    protected function crawler(string $html, string $uri): Crawler
    {
        return new Crawler($html, $uri);
    }

    protected function text(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->first()->text('')) ?: '');

        return $text === '' ? null : html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function attr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $value = trim($node->first()->attr($attribute) ?? '');

        return $value === '' ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function rawFragment(Crawler $crawler, string $selector): string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            $node = $crawler->filter('main article, main .main--content, main .region-content');
        }

        if ($node->count() === 0) {
            return '';
        }

        return '<article>' . $node->first()->html() . '</article>';
    }

    protected function cleanBlockText(Crawler $crawler, string $selector, int $limit = 20): ?string
    {
        $nodes = $crawler->filter($selector);

        if ($nodes->count() === 0) {
            return null;
        }

        $html = implode("\n", $nodes
            ->reduce(fn(Crawler $node, int $index): bool => $index < $limit)
            ->each(fn(Crawler $node): string => $node->html('')));
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/td|\/th|\/tr)\b[^>]*>/iu', "$0\n", $html) ?: $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?: $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?: $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?: $text;
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    protected function cleanBlockHtml(Crawler $crawler, string $selector, string $baseUrl, int $limit = 20): ?string
    {
        $nodes = $crawler->filter($selector);

        if ($nodes->count() === 0) {
            return null;
        }

        $html = implode('', $nodes
            ->reduce(fn(Crawler $node, int $index): bool => $index < $limit)
            ->each(function (Crawler $node): string {
                $dom = $node->getNode(0);

                return $dom?->ownerDocument?->saveHTML($dom) ?: '';
            }));

        return $this->canonicalContent->normalize($html, $baseUrl)->html;
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonLdEvent(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $decoded = json_decode($script->textContent, true);

            foreach ($this->jsonLdCandidates($decoded) as $candidate) {
                $type = $candidate['@type'] ?? null;
                $types = is_array($type) ? $type : [$type];

                if (in_array('Event', $types, true)) {
                    return $candidate;
                }
            }
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLdCandidates(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return collect($decoded)->flatMap(fn(mixed $item): array => $this->jsonLdCandidates($item))->all();
        }

        $candidates = [$decoded];

        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $candidates = array_merge($candidates, $this->jsonLdCandidates($decoded['@graph']));
        }

        return $candidates;
    }

    protected function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Europe/Zurich');
        } catch (\Throwable) {
            return $this->dates->parseDateText($value)?->startsAt;
        }
    }

    /**
     * @param  array<string, mixed>  $jsonLd
     */
    protected function imageFromJsonLd(array $jsonLd): ?string
    {
        $image = $jsonLd['image'] ?? null;

        if (is_string($image)) {
            return $image;
        }

        if (is_array($image)) {
            $first = array_is_list($image) ? ($image[0] ?? null) : ($image['url'] ?? null);

            return is_string($first) ? $first : null;
        }

        return null;
    }

    /**
     * Extract and classify every image contained in event-specific HTML.
     *
     * @return list<ClassifiedAsset>
     */
    protected function classifiedAssets(
        Crawler $crawler,
        string $baseUrl
    ): array {
        return $this->assets->extract(
            $crawler,
            $baseUrl
        );
    }

    /**
     * Return only assets that are allowed to participate in event hero selection.
     *
     * Unknown assets remain candidates deliberately:
     * an image should not disappear simply because we could not classify it.
     *
     * @param  list<ClassifiedAsset>  $assets
     * @return list<array{url:string,origin:string}>
     */
    protected function eventImageCandidates(
        array $assets,
        string $origin = 'event-detail-page'
    ): array {
        $candidates = [];
        $seen = [];

        foreach ($assets as $asset) {
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

            if (isset($seen[$asset->url])) {
                continue;
            }

            $seen[$asset->url] = true;

            $candidates[] = [
                'url' => $asset->url,
                'origin' => $origin,
            ];
        }

        return $candidates;
    }

    /**
     * Render classified image evidence for downstream normalization.
     *
     * Every asset survives, but its semantic role is made explicit.
     *
     * @param  list<ClassifiedAsset>  $assets
     */
    protected function assetEvidenceHtml(
        array $assets,
        ?string $title = null,
        string $origin = 'event-detail-page'
    ): string {
        if ($assets === []) {
            return '';
        }

        $html =
            '<section data-crawl-source="image-evidence">';

        foreach ($assets as $asset) {
            $alt = match ($asset->role) {
                AssetRole::EventMedia =>
                $title ?? '',

                default =>
                $asset->label ?? '',
            };

            $html .=
                '<img'
                . ' src="'
                . e($asset->url)
                . '"'
                . ' alt="'
                . e($alt)
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
                . ' data-crawl-origin="'
                . e($origin)
                . '"'
                . ' data-crawl-classification-reason="'
                . e($asset->reason ?? '')
                . '"';

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

        $html .= '</section>';

        return $html;
    }

    protected function crawlAssetRole(
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
     * Produce structured metadata for debugging and downstream processing.
     *
     * @param  list<ClassifiedAsset>  $assets
     * @return list<array{
     *     url:string,
     *     role:string,
     *     reason:?string,
     *     semantic_key:?string,
     *     label:?string
     * }>
     */
    protected function assetMetadata(
        array $assets
    ): array {
        return array_map(
            static fn(ClassifiedAsset $asset): array => [
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
            $assets
        );
    }
}
