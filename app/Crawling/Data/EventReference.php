<?php

namespace App\Crawling\Data;

final readonly class EventReference
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $url,
        public ?string $externalId = null,
        public ?string $listingUrl = null,
        public ?string $titleHint = null,
        public ?string $dateText = null,
        public ?string $imageUrl = null,
        public array $metadata = [],
    ) {}

    /**
     * Stable identity of this event inside one EventSource.
     *
     * Prefer the source-provided external ID because the public URL may
     * legitimately change while the underlying source event remains the same.
     *
     * event_source_id is deliberately not part of this value because the
     * database uniqueness constraint already scopes source_key by source.
     */
    public function sourceKey(): string
    {
        $externalId = trim((string) $this->externalId);

        if ($externalId !== '') {
            return hash(
                'sha256',
                'external_id|' . $externalId
            );
        }

        return hash(
            'sha256',
            'url|' . $this->identityUrl()
        );
    }

    /**
     * Existing crawl-reference hash.
     *
     * Keep this behavior unchanged because crawl_run_event_results already
     * uses it to identify the reference processed during a particular run.
     */
    public function hash(): string
    {
        return hash(
            'sha256',
            ($this->externalId ?: '') . '|' . $this->url
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'external_id' => $this->externalId,
            'listing_url' => $this->listingUrl,
            'title_hint' => $this->titleHint,
            'date_text' => $this->dateText,
            'image_url' => $this->imageUrl,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            url: (string) $payload['url'],
            externalId: isset($payload['external_id'])
                ? (string) $payload['external_id']
                : null,
            listingUrl: isset($payload['listing_url'])
                ? (string) $payload['listing_url']
                : null,
            titleHint: isset($payload['title_hint'])
                ? (string) $payload['title_hint']
                : null,
            dateText: isset($payload['date_text'])
                ? (string) $payload['date_text']
                : null,
            imageUrl: isset($payload['image_url'])
                ? (string) $payload['image_url']
                : null,
            metadata: is_array($payload['metadata'] ?? null)
                ? $payload['metadata']
                : [],
        );
    }

    private function identityUrl(): string
    {
        return rtrim(trim($this->url), '/');
    }
}
