<?php

namespace App\Crawling\Services;

use App\Models\Event;
use Illuminate\Support\Str;

class CanonicalEventMatcher
{
    public function findMatch(
        Event $event
    ): ?array {
        $profile = $this->profile($event);

        /*
         * Without a usable title + occurrence date we do not auto-deduplicate.
         *
         * False negatives are safer than false positives here.
         */
        if (
            $profile['title'] === null
            || $profile['dates'] === []
        ) {
            return null;
        }

        $candidates = Event::query()
            ->with('eventSource')
            ->where('commune_id', $event->commune_id)
            ->where('id', '!=', $event->id)
            ->where(
                'event_source_id',
                '!=',
                $event->event_source_id
            )
            ->whereNotNull('normalized_json')
            ->get();

        $best = null;

        foreach ($candidates as $candidate) {
            /*
             * Already in the same canonical group.
             */
            if (
                $event->canonical_event_id !== null
                && (int) $candidate->canonical_event_id
                === (int) $event->canonical_event_id
            ) {
                continue;
            }

            $candidateProfile =
                $this->profile($candidate);

            if (
                $candidateProfile['title'] === null
                || $candidateProfile['dates'] === []
            ) {
                continue;
            }

            /*
             * Hard requirement:
             * at least one explicit occurrence calendar date must match.
             *
             * We intentionally do not use ±1 day tolerance because source
             * contradictions should not silently become duplicate matches.
             */
            if (
                array_intersect(
                    $profile['dates'],
                    $candidateProfile['dates']
                ) === []
            ) {
                continue;
            }

            $titleScore = $this->similarity(
                $profile['title'],
                $candidateProfile['title']
            );

            /*
             * Require strong title similarity before considering anything
             * else.
             */
            if ($titleScore < 0.90) {
                continue;
            }

            $locationScore = $this->locationSimilarity(
                $profile,
                $candidateProfile
            );

            /*
             * If both sources provide location information and those
             * locations materially disagree, do not auto-match.
             */
            if (
                $locationScore !== null
                && $locationScore < 0.60
            ) {
                continue;
            }

            /*
             * Missing location requires an exceptionally strong title match.
             */
            if (
                $locationScore === null
                && $titleScore < 0.97
            ) {
                continue;
            }

            $score =
                ($titleScore * 0.65)
                + 0.25
                + (
                    ($locationScore ?? 1.0)
                    * 0.10
                );

            if ($score < 0.92) {
                continue;
            }

            if (
                $best === null
                || $score > $best['score']
            ) {
                $best = [
                    'event' => $candidate,
                    'score' => round($score, 4),
                ];
            }
        }

        return $best;
    }

    /**
     * @return array{
     *     title:?string,
     *     dates:list<string>,
     *     location_name:?string,
     *     location_address:?string
     * }
     */
    private function profile(Event $event): array
    {
        $data = $event->normalized_json;

        if (! is_array($data)) {
            return [
                'title' => null,
                'dates' => [],
                'location_name' => null,
                'location_address' => null,
            ];
        }

        $eventData = is_array(
            $data['event'] ?? null
        )
            ? $data['event']
            : [];

        $identity = is_array(
            $eventData['identity'] ?? null
        )
            ? $eventData['identity']
            : [];

        $location = is_array(
            $eventData['location'] ?? null
        )
            ? $eventData['location']
            : [];

        $occurrences = is_array(
            $data['occurrences'] ?? null
        )
            ? $data['occurrences']
            : [];

        $dates = [];

        foreach ($occurrences as $occurrence) {
            if (! is_array($occurrence)) {
                continue;
            }

            $startsAt = $occurrence['starts_at']
                ?? null;

            if (
                ! is_string($startsAt)
                || trim($startsAt) === ''
            ) {
                continue;
            }

            /*
             * Canonical schema uses YYYY-MM-DDTHH:MM:SS.
             */
            $date = substr($startsAt, 0, 10);

            if (
                preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $date
                ) === 1
            ) {
                $dates[] = $date;
            }
        }

        return [
            'title' => $this->normalize(
                $identity['title'] ?? null
            ),

            'dates' => array_values(
                array_unique($dates)
            ),

            'location_name' => $this->normalize(
                $location['name'] ?? null
            ),

            'location_address' => $this->normalize(
                $location['address'] ?? null
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function locationSimilarity(
        array $left,
        array $right,
    ): ?float {
        $leftLocation =
            $left['location_address']
            ?? $left['location_name'];

        $rightLocation =
            $right['location_address']
            ?? $right['location_name'];

        if (
            ! is_string($leftLocation)
            || ! is_string($rightLocation)
            || $leftLocation === ''
            || $rightLocation === ''
        ) {
            return null;
        }

        return $this->similarity(
            $leftLocation,
            $rightLocation
        );
    }

    private function similarity(
        string $left,
        string $right,
    ): float {
        if ($left === $right) {
            return 1.0;
        }

        similar_text(
            $left,
            $right,
            $percentage
        );

        return max(
            0.0,
            min(
                1.0,
                $percentage / 100
            )
        );
    }

    private function normalize(
        mixed $value,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::ascii(
            mb_strtolower(
                trim($value)
            )
        );

        $value = preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            $value
        ) ?? $value;

        $value = trim(
            preg_replace(
                '/\s+/',
                ' ',
                $value
            ) ?? $value
        );

        return $value !== ''
            ? $value
            : null;
    }
}
