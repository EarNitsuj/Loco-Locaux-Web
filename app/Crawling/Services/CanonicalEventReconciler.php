<?php

namespace App\Crawling\Services;

use App\Models\CanonicalEvent;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class CanonicalEventReconciler
{
    public function __construct(
        private readonly CanonicalEventMatcher $matcher,
    ) {}

    public function reconcile(
        Event $event
    ): CanonicalEvent {
        return DB::transaction(
            function () use ($event): CanonicalEvent {
                $event->loadMissing([
                    'eventSource',
                    'canonicalEvent',
                ]);

                $ownCanonical =
                    $this->ensureCanonicalEvent(
                        $event
                    );

                $match = $this->matcher->findMatch(
                    $event
                );

                if ($match === null) {
                    $this->selectPrimary(
                        $ownCanonical
                    );

                    return $ownCanonical->fresh([
                        'primaryEvent',
                    ]);
                }

                /** @var Event $matchedEvent */
                $matchedEvent = $match['event'];

                $matchedEvent->loadMissing([
                    'eventSource',
                    'canonicalEvent',
                ]);

                $matchedCanonical =
                    $this->ensureCanonicalEvent(
                        $matchedEvent
                    );

                /*
                 * Matcher can theoretically discover another Event already in
                 * this same group after relationships changed in the meantime.
                 */
                if (
                    $ownCanonical->id
                    === $matchedCanonical->id
                ) {
                    return $ownCanonical;
                }

                /*
                 * Preserve the older canonical group ID. This keeps grouping
                 * identifiers stable instead of changing with crawl order.
                 */
                if (
                    $ownCanonical->id
                    < $matchedCanonical->id
                ) {
                    $survivor = $ownCanonical;
                    $loser = $matchedCanonical;
                } else {
                    $survivor = $matchedCanonical;
                    $loser = $ownCanonical;
                }

                Event::query()
                    ->where(
                        'canonical_event_id',
                        $loser->id
                    )
                    ->update([
                        'canonical_event_id' =>
                        $survivor->id,
                    ]);

                /*
                 * Record why this incoming source Event joined a different
                 * canonical group.
                 */
                $event->update([
                    'canonical_event_id' =>
                    $survivor->id,

                    'canonical_match_method' =>
                    'deterministic',

                    'canonical_match_score' =>
                    $match['score'],
                ]);

                /*
                 * Delete only the now-empty grouping record.
                 *
                 * No Event or raw source evidence is deleted.
                 */
                $loser->delete();

                $this->selectPrimary(
                    $survivor
                );

                return $survivor->fresh([
                    'primaryEvent',
                ]);
            }
        );
    }

    private function ensureCanonicalEvent(
        Event $event
    ): CanonicalEvent {
        if ($event->canonicalEvent !== null) {
            return $event->canonicalEvent;
        }

        $canonical = CanonicalEvent::create([
            'primary_event_id' => $event->id,
        ]);

        $event->update([
            'canonical_event_id' =>
            $canonical->id,

            'canonical_match_method' =>
            'self',

            'canonical_match_score' =>
            1.0000,
        ]);

        $event->setRelation(
            'canonicalEvent',
            $canonical
        );

        return $canonical;
    }

    private function selectPrimary(
        CanonicalEvent $canonical
    ): void {
        $events = Event::query()
            ->with('eventSource')
            ->where(
                'canonical_event_id',
                $canonical->id
            )
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $currentPrimary = $canonical->primary_event_id
            ? $events->firstWhere(
                'id',
                $canonical->primary_event_id
            )
            : null;

        $highestAuthority = $events->max(
            fn(Event $event): int =>
            (int) (
                $event->eventSource
                ?->authority_rank
                ?? 0
            )
        );

        /*
         * Avoid unnecessary primary churn when the currently selected Event
         * already has the highest authority.
         */
        if (
            $currentPrimary !== null
            && (int) (
                $currentPrimary
                ->eventSource
                ?->authority_rank
                ?? 0
            ) === $highestAuthority
        ) {
            return;
        }

        $primary = $events
            ->filter(
                fn(Event $event): bool =>
                (int) (
                    $event->eventSource
                    ?->authority_rank
                    ?? 0
                ) === $highestAuthority
            )
            ->sortBy('id')
            ->first();

        if (
            $primary !== null
            && (int) $canonical->primary_event_id
            !== (int) $primary->id
        ) {
            $canonical->update([
                'primary_event_id' =>
                $primary->id,
            ]);
        }
    }
}
