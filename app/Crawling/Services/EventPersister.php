<?php

namespace App\Crawling\Services;

use App\Crawling\Data\PersistedEventResult;
use App\Crawling\Data\RawEvent;
use App\Enums\CrawlEventStatus;
use App\Models\Event;
use App\Models\EventSource;
use RuntimeException;

class EventPersister
{
    public function persist(
        EventSource $source,
        RawEvent $raw,
        bool $force = false,
        ?int $communeId = null,
        ?Event $existingEvent = null,
    ): PersistedEventResult {
        $communeId ??= $source->commune_id;

        if ($communeId === null) {
            throw new RuntimeException(
                "Unable to determine commune for event: {$raw->canonicalUrl}"
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SourceEvent-owned Event
        |--------------------------------------------------------------------------
        |
        | SourceEvent is the authoritative persistent identity for a record
        | discovered from a particular EventSource.
        |
        | If the SourceEvent already points to an Event, reuse that Event.
        |
        | Never allow a SourceEvent from one EventSource to overwrite an Event
        | belonging to another EventSource. Cross-source duplicates are handled
        | through CanonicalEvent grouping, not by sharing the same Event row.
        |
        */

        $event = $existingEvent;

        if (
            $event !== null
            && (int) $event->event_source_id !== (int) $source->id
        ) {
            $event = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy Event lookup
        |--------------------------------------------------------------------------
        |
        | Events may exist from before SourceEvent persistence was introduced.
        |
        | In that case we may safely recover the old Event using:
        |
        |     event_source_id + event_url
        |
        | BUT ONLY when that Event has no SourceEvent attached yet.
        |
        | This condition is critical.
        |
        | Multiple legitimate source records can share exactly the same URL.
        | Choulex is one example because several Actualités cards may have:
        |
        |     https://choulex.ch/actualites/
        |
        | as their canonical/source URL.
        |
        | Once an Event has been claimed by a SourceEvent, URL equality alone
        | must never cause another SourceEvent to reuse that Event.
        |
        */

        if ($event === null) {
            $event = Event::query()
                ->where(
                    'event_source_id',
                    $source->id
                )
                ->where(
                    'event_url',
                    $raw->canonicalUrl
                )
                ->whereDoesntHave(
                    'sourceEvents'
                )
                ->first();
        }

        /*
        |--------------------------------------------------------------------------
        | Create new source-specific Event
        |--------------------------------------------------------------------------
        */

        if ($event === null) {
            $event = Event::create([
                'commune_id' =>
                $communeId,

                'event_source_id' =>
                $source->id,

                'event_url' =>
                $raw->canonicalUrl,

                'image_url' =>
                $raw->imageUrl,

                'raw_html' =>
                $raw->rawHtml,
            ]);

            return new PersistedEventResult(
                CrawlEventStatus::Created,
                $event,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Detect source evidence changes
        |--------------------------------------------------------------------------
        */

        $changed =
            (int) $event->commune_id !== $communeId
            || $event->event_url !== $raw->canonicalUrl
            || $event->image_url !== $raw->imageUrl
            || $event->raw_html !== $raw->rawHtml;

        if (
            ! $changed
            && ! $force
        ) {
            return new PersistedEventResult(
                CrawlEventStatus::Unchanged,
                $event,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update existing source-specific Event
        |--------------------------------------------------------------------------
        */

        $event->update([
            'commune_id' =>
            $communeId,

            'event_url' =>
            $raw->canonicalUrl,

            'image_url' =>
            $raw->imageUrl,

            'raw_html' =>
            $raw->rawHtml,
        ]);

        return new PersistedEventResult(
            CrawlEventStatus::Updated,
            $event,
        );
    }
}
