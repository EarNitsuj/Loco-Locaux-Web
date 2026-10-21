<?php

namespace App\Content;

use App\Content\Data\CanonicalDescription;
use App\Crawling\Support\UrlNormalizer;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Log;

class CanonicalContentNormalizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i',
        'section', 'h2', 'h3', 'blockquote', 'a', 'dl', 'dt', 'dd', 'time',
    ];

    /** @var list<string> */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'form', 'svg', 'nav', 'header', 'footer',
        'noscript', 'template', 'object', 'embed', 'canvas', 'audio', 'video',
    ];

    public function __construct(private readonly UrlNormalizer $urls) {}

    public function normalize(?string $content, ?string $baseUrl = null): CanonicalDescription
    {
        if ($content === null || trim($content) === '') {
            return new CanonicalDescription(null, null, false);
        }

        $source = $this->looksLikeHtml($content) ? $content : $this->paragraphs($content);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="canonical-content">'.$source.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('canonical-content');

        if (! $root instanceof DOMElement) {
            return new CanonicalDescription(null, null, true);
        }

        $this->sanitizeChildren($root, $baseUrl);
        $html = $this->innerHtml($root);
        $html = trim($html);
        $text = $this->plainText($html);

        if ($text === null) {
            return new CanonicalDescription(null, null, trim($content) !== '');
        }

        $description = new CanonicalDescription(
            html: $html,
            text: $text,
            changed: trim($content) !== $html,
        );

        Log::info('Canonical event HTML sanitized.', [
            'operation' => 'canonical_normalization',
            'source_text_length' => mb_strlen($this->plainText($content) ?? ''),
            'canonical_text_length' => mb_strlen($text),
            'paragraph_count' => substr_count($html, '<p'),
            'anchor_count' => substr_count($html, '<a '),
            'button_anchor_count' => substr_count($html, 'data-link-style="button"'),
        ]);

        return $description;
    }

    public function plainText(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?: $html;
        $text = preg_replace('/<\/\s*(p|li|h[1-6]|blockquote|dt|dd|td|th|summary|caption|address|time)\s*>/iu', "\n", $text) ?: $text;
        $text = preg_replace('/<\/\s*(ul|ol|dl|section|tr|table|details|aside)\s*>/iu', "\n", $text) ?: $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?: $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?: $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?: $text;
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /** @return list<string> */
    public function allowedTags(): array
    {
        return self::ALLOWED_TAGS;
    }

    private function sanitizeChildren(DOMNode $parent, ?string $baseUrl): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;

            if ($node instanceof DOMComment) {
                $parent->removeChild($node);
                $node = $next;

                continue;
            }

            if (! $node instanceof DOMElement) {
                $node = $next;

                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                $node = $next;

                continue;
            }

            $this->sanitizeChildren($node, $baseUrl);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrap($node);
                $node = $next;

                continue;
            }

            if ($tag === 'a') {
                $this->sanitizeLink($node, $baseUrl);
            } elseif ($tag === 'time') {
                $datetime = trim($node->getAttribute('datetime'));
                $this->removeAttributes($node);

                if ($datetime !== '') {
                    $node->setAttribute('datetime', $datetime);
                }
            } else {
                $this->removeAttributes($node);
            }

            $node = $next;
        }
    }

    private function sanitizeLink(DOMElement $link, ?string $baseUrl): void
    {
        $href = trim($link->getAttribute('href'));
        $title = trim($link->getAttribute('title'));
        $target = strtolower(trim($link->getAttribute('target')));
        $action = $this->hasButtonIntent($link);

        $this->removeAttributes($link);
        $safeHref = $this->safeHref($href, $baseUrl);

        if ($safeHref !== null) {
            $link->setAttribute('href', $safeHref);

            if ($title !== '') {
                $link->setAttribute('title', $title);
            }

            if ($target === '_blank') {
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener noreferrer');
            }

            if ($action) {
                $link->setAttribute('data-link-style', 'button');
            }
        }
    }

    private function hasButtonIntent(DOMElement $link): bool
    {
        if (strtolower(trim($link->getAttribute('role'))) === 'button') {
            return true;
        }

        $elements = [$link];

        if ($link->parentNode instanceof DOMElement) {
            $elements[] = $link->parentNode;
        }

        foreach ($elements as $element) {
            $tokens = preg_split('/\s+/u', strtolower(trim($element->getAttribute('class')))) ?: [];

            foreach ($tokens as $token) {
                if (preg_match('/(?:^|[-_])(btn|button|cta)(?:$|[-_])/u', $token) === 1
                    || in_array($token, ['call-to-action', 'primary-action'], true)) {
                    return true;
                }
            }

            foreach (['data-link-style', 'data-style', 'data-component'] as $attribute) {
                if (in_array(strtolower(trim($element->getAttribute($attribute))), ['button', 'cta', 'call-to-action'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function safeHref(string $href, ?string $baseUrl): ?string
    {
        if ($href === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if ($scheme !== '' && ! in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return null;
        }

        if ($scheme === 'mailto') {
            return filter_var(mb_substr($href, 7), FILTER_VALIDATE_EMAIL) ? $href : null;
        }

        if ($scheme === 'tel') {
            $number = preg_replace('/[^0-9+]/', '', rawurldecode(mb_substr($href, 4))) ?: '';

            return preg_match('/^\+?[0-9]{5,20}$/', $number) === 1 ? 'tel:'.$number : null;
        }

        if ($scheme === '' && $baseUrl === null) {
            return null;
        }

        $resolved = $scheme === '' || str_starts_with($href, '//')
            ? $this->urls->absolute($href, (string) $baseUrl)
            : $this->urls->withoutFragment($href);

        if (! in_array(strtolower((string) parse_url($resolved, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        if ($resolved !== $href) {
            Log::info('Relative content link resolved.', ['operation' => 'canonical_normalization']);
        }

        return $resolved;
    }

    private function removeAttributes(DOMElement $element): void
    {
        while ($element->attributes->length > 0) {
            $element->removeAttributeNode($element->attributes->item(0));
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function innerHtml(DOMElement $root): string
    {
        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $root->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }

    private function paragraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        return collect(preg_split('/\n{2,}/u', $text) ?: [])
            ->map(fn (string $paragraph): string => '<p>'.nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false).'</p>')
            ->implode('');
    }

    private function looksLikeHtml(string $content): bool
    {
        return preg_match('/<\/?[a-z][^>]*>/iu', $content) === 1;
    }
}
