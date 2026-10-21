<?php

namespace App\Crawling\Services;

use App\Crawling\Data\RawEvent;
use JsonException;

class RawEventFingerprint
{
    /**
     * Calculate the fingerprint of the evidence that affects the persisted
     * and normalized Event.
     *
     * raw_html intentionally participates in the hash because it is the
     * primary evidence envelope supplied to normalization.
     *
     * Only harmless formatting differences are normalized here. Source-
     * specific volatile markup should continue to be cleaned by adapters
     * rather than introducing broad global removal rules.
     *
     * @throws JsonException
     */
    public function hash(
        RawEvent $raw,
        int $communeId,
    ): string {
        $payload = [
            'commune_id' => $communeId,
            'event_url' => trim($raw->canonicalUrl),
            'image_url' => $raw->imageUrl !== null
                ? trim($raw->imageUrl)
                : null,
            'raw_html' => $this->normalizeRawHtml(
                $raw->rawHtml
            ),
        ];

        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR,
            )
        );
    }

    private function normalizeRawHtml(string $html): string
    {
        /*
         * Normalize operating-system line endings.
         */
        $html = str_replace(
            ["\r\n", "\r"],
            "\n",
            $html
        );

        /*
         * Ignore trailing whitespace on individual lines.
         *
         * Do not globally collapse HTML/text whitespace because whitespace
         * may legitimately be part of event evidence.
         */
        $lines = explode("\n", $html);

        $lines = array_map(
            static fn(string $line): string => rtrim($line),
            $lines
        );

        return trim(
            implode("\n", $lines)
        );
    }
}
