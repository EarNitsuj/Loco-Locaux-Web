<?php

namespace App\Crawling\Assets;

use App\Crawling\Support\UrlNormalizer;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

class EventAssetExtractor
{
    public function __construct(
        private readonly UrlNormalizer $urls,
        private readonly EventAssetClassifier $classifier,
    ) {}

    /**
     * Extract and classify every image contained in event-specific HTML.
     *
     * @return list<ClassifiedAsset>
     */
    public function extract(
        Crawler $crawler,
        string $baseUrl,
    ): array {
        $assets = [];
        $seen = [];

        foreach (
            $crawler->filter(
                'img[src],'
                    . 'img[data-src],'
                    . 'img[srcset],'
                    . 'img[data-srcset]'
            )
            as $node
        ) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $src = $this->source(
                $node
            );

            if ($src === null) {
                continue;
            }

            try {
                $url = $this->urls->absolute(
                    $src,
                    $baseUrl
                );
            } catch (\Throwable) {
                continue;
            }

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $assets[] = $this->classifier->classify(
                $node,
                $url
            );
        }

        foreach ($crawler->filter('source[srcset]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $srcset = html_entity_decode(
                trim($node->getAttribute('srcset')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($srcset === '') {
                continue;
            }

            $candidate = $this->bestSrcsetCandidate($srcset);

            if ($candidate === null) {
                continue;
            }

            try {
                $url = $this->urls->absolute(
                    $candidate,
                    $baseUrl
                );
            } catch (\Throwable) {
                continue;
            }

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $assets[] = $this->classifier->classify(
                $node,
                $url
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Images linked as files
        |--------------------------------------------------------------------------
        |
        | Some commune websites do not embed flyers with <img>.
        | Instead they expose them as direct links:
        |
        | <a href="/uploads/event-flyer.png">Flyer</a>
        |
        | These are still event-specific image evidence and should participate in
        | downstream image selection.
        |
        */

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

            /*
            | Only direct image-file links.
            |
            | Query strings and fragments are allowed.
            */

            if (
                preg_match(
                    '/\.(?:jpe?g|png|webp|gif|avif|svg)(?:$|[?#])/iu',
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

            $assets[] = $this->classifier->classify(
                $node,
                $url
            );
        }

        return $assets;
    }

    private function source(
        DOMElement $image
    ): ?string {
        /*
        |--------------------------------------------------------------------------
        | Lazy-loaded direct source
        |--------------------------------------------------------------------------
        |
        | Prefer data-src over src because src is frequently a placeholder.
        |
        */

        $dataSrc = $this->attribute(
            $image,
            'data-src'
        );

        if ($dataSrc !== null) {
            return $dataSrc;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive/lazy responsive source
        |--------------------------------------------------------------------------
        |
        | Prefer the strongest candidate from data-srcset/srcset.
        |
        */

        $dataSrcset = $this->attribute(
            $image,
            'data-srcset'
        );

        if ($dataSrcset !== null) {
            $candidate = $this->bestSrcsetCandidate(
                $dataSrcset
            );

            if ($candidate !== null) {
                return $candidate;
            }
        }

        $srcset = $this->attribute(
            $image,
            'srcset'
        );

        if ($srcset !== null) {
            $candidate = $this->bestSrcsetCandidate(
                $srcset
            );

            if ($candidate !== null) {
                return $candidate;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Normal source
        |--------------------------------------------------------------------------
        */

        return $this->attribute(
            $image,
            'src'
        );
    }

    private function attribute(
        DOMElement $image,
        string $attribute
    ): ?string {
        $value = html_entity_decode(
            trim(
                $image->getAttribute(
                    $attribute
                )
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return $value === ''
            ? null
            : $value;
    }

    private function bestSrcsetCandidate(
        string $srcset
    ): ?string {
        $candidates = [];

        foreach (
            explode(',', $srcset)
            as $entry
        ) {
            $entry = trim(
                $entry
            );

            if ($entry === '') {
                continue;
            }

            /*
            | Example:
            |
            | image-small.webp 400w
            | image-large.webp 1200w
            | image@2x.webp 2x
            |
            */

            $parts = preg_split(
                '/\s+/u',
                $entry
            );

            if (
                ! is_array($parts)
                || ($parts[0] ?? '') === ''
            ) {
                continue;
            }

            $url = $parts[0];

            $descriptor =
                $parts[1]
                ?? null;

            $score = 1.0;

            if (
                is_string($descriptor)
                && preg_match(
                    '/^(\d+(?:\.\d+)?)w$/i',
                    $descriptor,
                    $match
                ) === 1
            ) {
                $score = (float) $match[1];
            } elseif (
                is_string($descriptor)
                && preg_match(
                    '/^(\d+(?:\.\d+)?)x$/i',
                    $descriptor,
                    $match
                ) === 1
            ) {
                /*
                | Give density descriptors a meaningful ordering while
                | keeping normal width descriptors naturally larger.
                */

                $score =
                    (float) $match[1]
                    * 1000;
            }

            $candidates[] = [
                'url' => $url,
                'score' => $score,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn(
                array $a,
                array $b
            ): int =>
            $b['score']
                <=> $a['score']
        );

        return $candidates[0]['url']
            ?? null;
    }
}
