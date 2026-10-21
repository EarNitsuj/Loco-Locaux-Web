<?php

namespace App\Crawling\Services;

use App\Crawling\Data\EventReference;
use App\Crawling\Data\SourceEventObservation;
use App\Models\EventSource;
use App\Models\SourceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use JsonException;

class EventCountEvaluator
{
    public function __construct(
        private readonly SourceEventFetchPolicy $fetchPolicy,
    ) {}

    /**
     * Determine whether a discovered reference should be considered a
     * potential AI-normalization candidate.
     *
     * This decision is deliberately independent of SourceEvent history.
     *
     * We only exclude a reference when the discovery evidence lets us
     * confidently prove that the event has already ended.
     *
     * Ambiguous, recurring, undated or partially dated references remain
     * eligible so the estimate errs on the safe/high side rather than
     * underestimating AI cost.
     */
    public function isAiCandidate(
        EventReference $reference
    ): bool {
        return ! $this->isDefinitelyPast(
            $reference
        );
    }

    /**
     * Load persistent crawl history for one source.
     *
     * Read-only.
     *
     * @return Collection<string, SourceEvent>
     */
    public function sourceHistory(
        EventSource $source
    ): Collection {
        /** @var Collection<string, SourceEvent> $history */
        $history = SourceEvent::query()
            ->where(
                'event_source_id',
                $source->id
            )
            ->get()
            ->keyBy(
                fn(SourceEvent $sourceEvent): string =>
                $sourceEvent->source_key
            );

        return $history;
    }

    /**
     * Determine whether an AI candidate would pass the existing real-crawl
     * first-layer fetch policy right now.
     *
     * Passing null means this reference has never been observed before and
     * therefore requires work.
     *
     * This method performs no INSERT or UPDATE.
     *
     * @throws JsonException
     */
    public function shouldProcessNow(
        EventReference $reference,
        ?SourceEvent $sourceEvent
    ): bool {
        /*
         * A reference not found in this source's persistent history is new.
         */
        if ($sourceEvent === null) {
            return true;
        }

        $referenceHash =
            $this->referenceFingerprint(
                $reference
            );

        $referenceChanged =
            $sourceEvent->reference_hash === null
            || ! hash_equals(
                $sourceEvent->reference_hash,
                $referenceHash
            );

        $normalizationPending =
            $this->needsNormalization(
                $sourceEvent
            );

        $observation =
            new SourceEventObservation(
                sourceEvent: $sourceEvent,
                wasCreated: false,
                referenceChanged: $referenceChanged,
            );

        /*
         * Reuse the actual crawl policy for:
         *
         * - reference changes
         * - never-fetched records
         * - missing content hashes
         * - unattached Event records
         * - incomplete normalization
         * - periodic revalidation
         */
        return $this->fetchPolicy->shouldFetch(
            observation: $observation,
            force: false,
            normalizationPending: $normalizationPending,
        );
    }

    /**
     * Safely identify references that are definitely expired from their
     * lightweight discovery evidence.
     *
     * This intentionally does NOT attempt to understand every possible
     * natural-language date expression.
     *
     * If there is uncertainty, return false and retain the reference.
     */
    private function isDefinitelyPast(
        EventReference $reference
    ): bool {
        $dateText = trim(
            (string) $reference->dateText
        );

        if ($dateText === '') {
            return false;
        }

        /*
         * Recurring expressions are intentionally retained.
         *
         * A listing might show one representative date while the actual event
         * continues according to a recurrence rule.
         */
        if (
            $this->containsRecurrenceHint(
                $dateText
            )
        ) {
            return false;
        }

        $dates = $this->explicitDates(
            $dateText
        );

        /*
         * No confidently parsed, year-bearing date means we cannot safely
         * declare this reference expired.
         */
        if ($dates === []) {
            return false;
        }

        /*
         * For ranges / multiple explicit dates, the latest explicit date is
         * the important one.
         *
         * Example:
         *
         * 09.05.2026 - 13.09.2026
         *
         * remains eligible until after 13 September.
         */
        $latestDate = collect(
            $dates
        )->sortDesc()->first();

        if (! $latestDate instanceof CarbonImmutable) {
            return false;
        }

        $today = CarbonImmutable::now(
            'Europe/Zurich'
        )->startOfDay();

        return $latestDate->lt(
            $today
        );
    }

    private function containsRecurrenceHint(
        string $value
    ): bool {
        $normalized = Str::lower(
            Str::ascii(
                $value
            )
        );

        $hints = [
            /*
             * French
             */
            'tous les',
            'toutes les',
            'chaque ',
            'hebdomadaire',
            'mensuel',
            'mensuelle',
            'recurr',

            /*
             * English
             */
            'every ',
            'weekly',
            'monthly',
            'recurring',

            /*
             * German
             */
            'jeden ',
            'jede ',
            'jedes ',
            'wochentlich',
            'monatlich',
            'wiederkehr',

            /*
             * Italian
             */
            'ogni ',
            'settimanale',
            'mensile',
            'ricorr',

            /*
             * Spanish
             */
            'cada ',
            'semanal',
            'mensual',
            'recurrent',

            /*
             * Portuguese
             */
            'todas as ',
            'todos os ',
            'semanal',
            'mensal',
            'recorrent',
        ];

        foreach ($hints as $hint) {
            if (
                str_contains(
                    $normalized,
                    $hint
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract only explicit dates which include a year.
     *
     * Supported examples include:
     *
     * 2026-09-13
     * 13.09.2026
     * 13/09/2026
     * 13-09-2026
     * 13 septembre 2026
     * September 13, 2026
     *
     * @return list<CarbonImmutable>
     */
    private function explicitDates(
        string $value
    ): array {
        $dates = [];

        /*
        |--------------------------------------------------------------------------
        | ISO dates: YYYY-MM-DD
        |--------------------------------------------------------------------------
        */

        if (
            preg_match_all(
                '/\b(20\d{2})-(\d{1,2})-(\d{1,2})\b/u',
                $value,
                $matches,
                PREG_SET_ORDER
            ) !== false
        ) {
            foreach ($matches as $match) {
                $this->addDate(
                    $dates,
                    (int) $match[1],
                    (int) $match[2],
                    (int) $match[3],
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Numeric European dates: DD.MM.YYYY / DD/MM/YYYY / DD-MM-YYYY
        |--------------------------------------------------------------------------
        */

        if (
            preg_match_all(
                '/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](20\d{2})\b/u',
                $value,
                $matches,
                PREG_SET_ORDER
            ) !== false
        ) {
            foreach ($matches as $match) {
                $this->addDate(
                    $dates,
                    (int) $match[3],
                    (int) $match[2],
                    (int) $match[1],
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Day + named month + year
        |--------------------------------------------------------------------------
        |
        | Examples:
        |
        | 13 septembre 2026
        | 13 September 2026
        | 13. September 2026
        |
        */

        if (
            preg_match_all(
                '/\b(\d{1,2})(?:er|st|nd|rd|th)?\.?\s+([\p{L}.]+)\s+(20\d{2})\b/iu',
                $value,
                $matches,
                PREG_SET_ORDER
            ) !== false
        ) {
            foreach ($matches as $match) {
                $month =
                    $this->monthNumber(
                        $match[2]
                    );

                if ($month === null) {
                    continue;
                }

                $this->addDate(
                    $dates,
                    (int) $match[3],
                    $month,
                    (int) $match[1],
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Named month + day + year
        |--------------------------------------------------------------------------
        |
        | Examples:
        |
        | September 13, 2026
        | September 13 2026
        |
        */

        if (
            preg_match_all(
                '/\b([\p{L}.]+)\s+(\d{1,2})(?:st|nd|rd|th)?[,]?\s+(20\d{2})\b/iu',
                $value,
                $matches,
                PREG_SET_ORDER
            ) !== false
        ) {
            foreach ($matches as $match) {
                $month =
                    $this->monthNumber(
                        $match[1]
                    );

                if ($month === null) {
                    continue;
                }

                $this->addDate(
                    $dates,
                    (int) $match[3],
                    $month,
                    (int) $match[2],
                );
            }
        }

        return array_values(
            $dates
        );
    }

    /**
     * @param  array<string, CarbonImmutable>  $dates
     */
    private function addDate(
        array &$dates,
        int $year,
        int $month,
        int $day,
    ): void {
        if (
            ! checkdate(
                $month,
                $day,
                $year
            )
        ) {
            return;
        }

        $date = CarbonImmutable::create(
            $year,
            $month,
            $day,
            0,
            0,
            0,
            'Europe/Zurich'
        );

        $dates[$date->format('Y-m-d')] = $date;
    }

    private function monthNumber(
        string $month
    ): ?int {
        $month = Str::lower(
            Str::ascii(
                trim(
                    $month,
                    " \t\n\r\0\x0B."
                )
            )
        );

        return match ($month) {
            /*
             * January
             */
            'jan',
            'janvier',
            'january',
            'januar',
            'gennaio',
            'enero',
            'janeiro' => 1,

            /*
             * February
             */
            'fev',
            'fevrier',
            'feb',
            'february',
            'februar',
            'febbraio',
            'febrero',
            'fevereiro' => 2,

            /*
             * March
             */
            'mars',
            'march',
            'marz',
            'marzo',
            'marco' => 3,

            /*
             * April
             */
            'avr',
            'avril',
            'apr',
            'april',
            'aprile',
            'abril' => 4,

            /*
             * May
             */
            'mai',
            'may',
            'maggio',
            'mayo',
            'maio' => 5,

            /*
             * June
             */
            'juin',
            'june',
            'juni',
            'giugno',
            'junio',
            'junho' => 6,

            /*
             * July
             */
            'juil',
            'juillet',
            'july',
            'juli',
            'luglio',
            'julio',
            'julho' => 7,

            /*
             * August
             */
            'aout',
            'aug',
            'august',
            'agosto' => 8,

            /*
             * September
             */
            'sept',
            'septembre',
            'sep',
            'september',
            'settembre',
            'septiembre',
            'setembro' => 9,

            /*
             * October
             */
            'oct',
            'octobre',
            'october',
            'oktober',
            'ottobre',
            'octubre',
            'outubro' => 10,

            /*
             * November
             */
            'nov',
            'novembre',
            'november',
            'noviembre',
            'novembro' => 11,

            /*
             * December
             */
            'dec',
            'decembre',
            'december',
            'dezember',
            'dicembre',
            'diciembre',
            'dezembro' => 12,

            default => null,
        };
    }

    /**
     * Determine whether the currently fetched evidence version still needs
     * successful normalization.
     */
    private function needsNormalization(
        SourceEvent $sourceEvent
    ): bool {
        if (
            $sourceEvent->content_hash
            === null
        ) {
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
     * Reproduce the discovery/reference fingerprint used by
     * SourceEventTracker.
     *
     * This class remains read-only and therefore deliberately does not call
     * SourceEventTracker::observe().
     *
     * @throws JsonException
     */
    private function referenceFingerprint(
        EventReference $reference
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
        ?string $value
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

        return preg_replace(
            '/\s+/u',
            ' ',
            $value
        ) ?? $value;
    }

    private function normalizeUrl(
        string $url
    ): string {
        return rtrim(
            trim(
                $url
            ),
            '/'
        );
    }
}
