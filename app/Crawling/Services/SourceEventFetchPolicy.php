<?php

namespace App\Crawling\Services;

use App\Crawling\Data\SourceEventObservation;

class SourceEventFetchPolicy
{
    public function shouldFetch(
        SourceEventObservation $observation,
        bool $force = false,
        bool $normalizationPending = false,
    ): bool {
        $sourceEvent = $observation->sourceEvent;

        /*
         * Manual/forced crawls always bypass fetch avoidance.
         */
        if ($force) {
            return true;
        }

        /*
         * Brand-new source events must be fetched.
         */
        if ($observation->wasCreated) {
            return true;
        }

        /*
         * Something visible during discovery changed.
         */
        if ($observation->referenceChanged) {
            return true;
        }

        /*
         * Defensive cases: the persistent source event has never completed
         * a usable fetch or is not attached to an Event yet.
         */
        if (
            $sourceEvent->last_fetched_at === null
            || $sourceEvent->content_hash === null
            || $sourceEvent->event_id === null
        ) {
            return true;
        }

        /*
         * If the current evidence version still needs successful
         * normalization, fetch again so the existing RawEvent pipeline can
         * retry safely.
         */
        if ($normalizationPending) {
            return true;
        }

        /*
         * Even when discovery looks unchanged, periodically re-fetch the
         * detail evidence because sources can edit detail pages without
         * changing their listing.
         */
        $revalidationHours = max(
            1,
            (int) config(
                'crawling.source_event_revalidation_hours',
                24
            )
        );

        return $sourceEvent->last_fetched_at->lte(
            now()->subHours($revalidationHours)
        );
    }
}
