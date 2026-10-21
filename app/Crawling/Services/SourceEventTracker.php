<?php

namespace App\Crawling\Services;

use App\Crawling\Data\EventReference;
use App\Crawling\Data\RawEvent;
use App\Crawling\Data\SourceEventObservation;
use App\Models\EventSource;
use App\Models\SourceEvent;
use JsonException;
use RuntimeException;

class SourceEventTracker
{
    /**
     * Inspect an already-known source event without changing persistent state.
     *
     * This method is intended for read-only operations such as events:count.
     *
     * Unlike observe(), it does NOT:
     *
     * - create SourceEvent records
     * - update last_seen_at
     * - clear missing_since_at
     * - update reference_hash
     * - update canonical_url
     * - change status
     *
     * Returns null when this source event has never been observed before.
     *
     * @throws JsonException
     */
    public function inspect(
        EventSource $source,
        EventReference $reference,
    ): ?SourceEventObservation {
        $sourceEvent = SourceEvent::query()
            ->where(
                'event_source_id',
                $source->id
            )
            ->where(
                'source_key',
                $reference->sourceKey()
            )
            ->first();

        if ($sourceEvent === null) {
            return null;
        }

        $referenceHash = $this->referenceFingerprint(
            $reference
        );

        $previousReferenceHash =
            $sourceEvent->reference_hash;

        $referenceChanged =
            $previousReferenceHash === null
            || ! hash_equals(
                $previousReferenceHash,
                $referenceHash
            );

        return new SourceEventObservation(
            sourceEvent: $sourceEvent,
            wasCreated: false,
            referenceChanged: $referenceChanged,
        );
    }


    /**
     * Observe a source event during a real crawl.
     *
     * In addition to resolving its persistent identity, this tells callers
     * whether the discovery/listing evidence changed since the previous run.
     *
     * Unlike inspect(), this method intentionally updates persistent crawl
     * state such as last_seen_at.
     *
     * @throws JsonException
     */
    public function observe(
        EventSource $source,
        EventReference $reference,
    ): SourceEventObservation {
        $now = now();

        $referenceHash = $this->referenceFingerprint(
            $reference
        );

        $sourceEvent = SourceEvent::query()->firstOrCreate(
            [
                'event_source_id' => $source->id,
                'source_key' => $reference->sourceKey(),
            ],
            [
                'external_id' => $reference->externalId,
                'reference_hash' => $referenceHash,
                'canonical_url' => $reference->url,
                'status' => 'active',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );

        $wasCreated = $sourceEvent->wasRecentlyCreated;

        $referenceChanged =
            $this->referenceChanged(
                $sourceEvent->reference_hash,
                $referenceHash,
                $wasCreated
            );

        $attributes = [
            'external_id' =>
            $reference->externalId
                ?? $sourceEvent->external_id,

            'reference_hash' =>
            $referenceHash,

            'status' =>
            'active',

            'last_seen_at' =>
            $now,

            /*
             * Seeing the event again clears any future missing-state marker.
             */
            'missing_since_at' =>
            null,
        ];

        /*
         * Preserve the canonical URL produced by the last successful fetch.
         *
         * If the discovery URL changed, referenceChanged will force another
         * fetch and markFetched() will update canonical_url with the crawler's
         * actual RawEvent canonical URL.
         */
        if ($sourceEvent->canonical_url === null) {
            $attributes['canonical_url'] =
                $reference->url;
        }

        $sourceEvent->fill(
            $attributes
        );

        if ($sourceEvent->isDirty()) {
            $sourceEvent->save();
        }

        return new SourceEventObservation(
            sourceEvent: $sourceEvent,
            wasCreated: $wasCreated,
            referenceChanged: $referenceChanged,
        );
    }

    /**
     * Backward-compatible helper for any existing callers that only need the
     * persistent SourceEvent.
     */
    public function remember(
        EventSource $source,
        EventReference $reference,
    ): SourceEvent {
        return $this->observe(
            $source,
            $reference
        )->sourceEvent;
    }

    /**
     * Store the latest successfully fetched evidence version.
     *
     * Returns true when the fetched content differs from the previous
     * fetched content.
     */
    public function markFetched(
        SourceEvent $sourceEvent,
        RawEvent $raw,
        string $contentHash,
    ): bool {
        $previousHash =
            $sourceEvent->content_hash;

        $changed =
            $previousHash === null
            || ! hash_equals(
                $previousHash,
                $contentHash
            );

        $now = now();

        $attributes = [
            'external_id' =>
            $raw->externalId
                ?? $sourceEvent->external_id,

            'canonical_url' =>
            $raw->canonicalUrl,

            'content_hash' =>
            $contentHash,

            'last_fetched_at' =>
            $now,
        ];

        if ($changed) {
            $attributes['last_changed_at'] =
                $now;
        }

        $sourceEvent->fill(
            $attributes
        );

        if ($sourceEvent->isDirty()) {
            $sourceEvent->save();
        }

        return $changed;
    }

    public function attachEvent(
        SourceEvent $sourceEvent,
        int $eventId,
    ): void {
        if (
            (int) $sourceEvent->event_id
            === $eventId
        ) {
            return;
        }

        $sourceEvent->update([
            'event_id' =>
            $eventId,
        ]);

        $sourceEvent->unsetRelation(
            'event'
        );
    }

    /**
     * Determine whether the current fetched evidence has been successfully
     * normalized.
     */
    public function needsNormalization(
        SourceEvent $sourceEvent,
        bool $force = false,
    ): bool {
        if ($force) {
            return true;
        }

        if ($sourceEvent->content_hash === null) {
            return true;
        }

        if (
            $sourceEvent->normalized_content_hash
            === null
        ) {
            return true;
        }

        return ! hash_equals(
            $sourceEvent->content_hash,
            $sourceEvent->normalized_content_hash
        );
    }

    /**
     * Mark the current fetched evidence version as successfully normalized.
     */
    public function markNormalized(
        SourceEvent $sourceEvent,
    ): void {
        if ($sourceEvent->content_hash === null) {
            throw new RuntimeException(
                'Cannot mark SourceEvent as normalized without a content hash.'
            );
        }

        if (
            $sourceEvent->normalized_content_hash
            === $sourceEvent->content_hash
        ) {
            return;
        }

        $sourceEvent->update([
            'normalized_content_hash' =>
            $sourceEvent->content_hash,
        ]);
    }

    /**
     * Compare the discovery/reference fingerprint with the value stored for
     * an existing SourceEvent.
     */
    private function referenceChanged(
        ?string $previousReferenceHash,
        string $referenceHash,
        bool $wasCreated,
    ): bool {
        if ($wasCreated) {
            return false;
        }

        return $previousReferenceHash === null
            || ! hash_equals(
                $previousReferenceHash,
                $referenceHash
            );
    }

    /**
     * Fingerprint the useful information exposed during discovery.
     *
     * This is deliberately separate from EventReference::hash(), which is
     * already used by crawl_run_event_results as the run-level reference
     * identity.
     *
     * We intentionally do NOT hash the whole metadata array because source
     * metadata can contain volatile or adapter-specific information.
     *
     * @throws JsonException
     */
    private function referenceFingerprint(
        EventReference $reference,
    ): string {
        $payload = [
            'external_id' =>
            $this->normalizeValue(
                $reference->externalId
            ),

            'url' =>
            $this->normalizeUrl(
                $reference->url
            ),

            'title_hint' =>
            $this->normalizeValue(
                $reference->titleHint
            ),

            'date_text' =>
            $this->normalizeValue(
                $reference->dateText
            ),

            'image_url' =>
            $reference->imageUrl !== null
                ? $this->normalizeUrl(
                    $reference->imageUrl
                )
                : null,
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

    private function normalizeValue(
        ?string $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        ) ?? $value;

        return $value;
    }

    private function normalizeUrl(
        string $url,
    ): string {
        return rtrim(
            trim($url),
            '/'
        );
    }
}
