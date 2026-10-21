<?php

namespace App\Crawling\Assets;

use DOMElement;
use Illuminate\Support\Str;

class EventAssetClassifier
{
    public function classify(
        DOMElement $image,
        string $url,
    ): ClassifiedAsset {
        /*
        |--------------------------------------------------------------------------
        | Nearest context wins
        |--------------------------------------------------------------------------
        |
        | We inspect:
        |
        | 1. the element itself
        | 2. its immediate parent
        | 3. progressively higher ancestors
        |
        | This prevents a poster inside an event section from being classified
        | as "accessibility" merely because another part of the same section
        | contains an accessibility heading.
        |
        | The element may also be an <a> linking directly to an image file.
        |
        */

        foreach ($this->contexts($image) as $context) {
            $role = $this->roleFromContext($context);

            if ($role !== null) {
                return new ClassifiedAsset(
                    url: $url,
                    role: $role['role'],
                    reason: $role['reason'],
                );
            }
        }

        return new ClassifiedAsset(
            url: $url,
            role: AssetRole::Unknown,
            reason: 'no_deterministic_role',
        );
    }

    /**
     * @return list<string>
     */
    private function contexts(
        DOMElement $image
    ): array {
        $contexts = [];

        /*
        |--------------------------------------------------------------------------
        | Element itself
        |--------------------------------------------------------------------------
        |
        | href + textContent are included because EventAssetExtractor can also
        | classify <a href="...image.png"> links, not only <img> elements.
        |
        */

        $contexts[] = $this->normalize(
            implode(' ', [
                $image->getAttribute('class'),
                $image->getAttribute('id'),
                $image->getAttribute('alt'),
                $image->getAttribute('title'),
                $image->getAttribute('src'),
                $image->getAttribute('href'),
                $image->textContent,
            ])
        );

        /*
        |--------------------------------------------------------------------------
        | Walk outwards
        |--------------------------------------------------------------------------
        */

        $parent = $image->parentNode;
        $depth = 0;

        while (
            $parent instanceof DOMElement
            && $depth < 6
        ) {
            $parts = [
                $parent->tagName,
                $parent->getAttribute('class'),
                $parent->getAttribute('id'),
                $parent->getAttribute('role'),
                $parent->getAttribute('aria-label'),
            ];

            /*
            | Include text only for small/local containers.
            |
            | Do not include the full text of a large event section.
            */

            $text = $this->normalize(
                $parent->textContent
            );

            if (
                $text !== ''
                && mb_strlen($text) <= 180
            ) {
                $parts[] = $text;
            }

            $contexts[] = $this->normalize(
                implode(' ', $parts)
            );

            $parent = $parent->parentNode;
            $depth++;
        }

        return array_values(
            array_filter(
                $contexts,
                fn(string $value): bool =>
                $value !== ''
            )
        );
    }

    /**
     * @return array{
     *     role: AssetRole,
     *     reason: string
     * }|null
     */
    private function roleFromContext(
        string $context
    ): ?array {
        /*
        |--------------------------------------------------------------------------
        | Accessibility
        |--------------------------------------------------------------------------
        */

        if (
            $this->containsAny(
                $context,
                [
                    'accessibility',
                    'accessibilite',
                    'accessible',
                    'handicap',
                    'wheelchair',
                    'fauteuil roulant',
                    'pmr',
                    'mobilite reduite',
                ]
            )
        ) {
            return [
                'role' => AssetRole::Accessibility,
                'reason' => 'accessibility_context',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Event media
        |--------------------------------------------------------------------------
        */

        if (
            $this->containsAny(
                $context,
                [
                    'event-detail-image',
                    'event-image',
                    'event-media',
                    'event-banner',
                    'event-poster',
                    'content header image',

                    /*
                    | Observed official-source patterns.
                    |
                    | Normalization converts:
                    |
                    | page_banner      -> page banner
                    | oa-event-picture -> oa event picture
                    */

                    'page banner',
                    'oa event picture',

                    'hero',
                    'lightgallery',
                    'gallery',
                    'poster',
                    'affiche',

                    'icms picture container',

                    'main content image',
                    'ssr carousel',
                    'h image frame container',
                ]
            )
        ) {
            return [
                'role' => AssetRole::EventMedia,
                'reason' => 'event_media_context',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Practical information
        |--------------------------------------------------------------------------
        */

        if (
            $this->containsAny(
                $context,
                [
                    'transport',
                    'parking',
                    'bus',
                    'tram',
                    'train',
                    'practical',
                    'pratique',
                    'informations pratiques',
                ]
            )
        ) {
            return [
                'role' => AssetRole::PracticalInfo,
                'reason' => 'practical_info_context',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Organizer / partner branding
        |--------------------------------------------------------------------------
        */

        if (
            $this->containsAny(
                $context,
                [
                    'organizer',
                    'organiser',
                    'organisateur',
                    'organisator',
                    'branding',
                    'brand',
                    'partner',
                    'partenaire',
                    'sponsor',
                    'logo',
                ]
            )
        ) {
            return [
                'role' => AssetRole::OrganizerBranding,
                'reason' => 'branding_context',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | UI chrome
        |--------------------------------------------------------------------------
        |
        | spamspan is used by Drupal to replace the @ character in obfuscated
        | email addresses with a tiny GIF. It is page/UI infrastructure, never
        | an event hero image.
        |
        */

        if (
            $this->containsAny(
                $context,
                [
                    'social',
                    'share',
                    'sharing',
                    'facebook',
                    'instagram',
                    'linkedin',
                    'twitter',
                    'whatsapp',
                    'messenger',
                    'chevron',
                    'arrow',
                    'spinner',
                    'loader',
                    'navigation',
                    'spamspan',
                    'qr code',
                    'qrcode',
                    'mec qrcode',
                    'ico camera',
                ]
            )
        ) {
            return [
                'role' => AssetRole::UiChrome,
                'reason' => 'ui_context',
            ];
        }

        return null;
    }

    private function normalize(
        ?string $value
    ): string {
        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return '';
        }

        $value = Str::ascii(
            mb_strtolower($value)
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize punctuation / separators to spaces
        |--------------------------------------------------------------------------
        |
        | Examples:
        |
        | event-detail-image  -> event detail image
        | page_banner         -> page banner
        | oa-event-picture    -> oa event picture
        |
        | This also gives containsAny() reliable word/phrase boundaries.
        |
        */

        $value = preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            $value
        ) ?? $value;

        return trim(
            preg_replace(
                '/\s+/',
                ' ',
                $value
            ) ?? $value
        );
    }

    /**
     * Match complete normalized words or phrases.
     *
     * This intentionally avoids substring matching.
     *
     * Examples:
     *
     * - "bus" matches "arret de bus"
     * - "bus" does NOT match "bibliobus"
     * - "train" does NOT match "training"
     * - "pmr" matches "acces pmr"
     * - "event-detail-image" is normalized to
     *   "event detail image" before matching
     *
     * @param list<string> $needles
     */
    private function containsAny(
        string $haystack,
        array $needles
    ): bool {
        if ($haystack === '') {
            return false;
        }

        foreach ($needles as $needle) {
            $needle = $this->normalize(
                $needle
            );

            if ($needle === '') {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Complete word / phrase matching
            |--------------------------------------------------------------------------
            |
            | Both haystack and needle have already been normalized so their
            | only separators are single spaces.
            |
            | Adding spaces around the strings gives us deterministic token
            | boundaries without relying on regex \b behaviour.
            |
            | For example:
            |
            | haystack: "puplinge bibliobus"
            | needle:   "bus"
            |
            | " bus " is NOT present.
            |
            | But:
            |
            | haystack: "arret de bus ligne 33"
            |
            | " bus " IS present.
            |
            */

            if (
                str_contains(
                    ' ' . $haystack . ' ',
                    ' ' . $needle . ' '
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
