<?php

namespace App\Crawling\Support;

use App\Crawling\Data\ParsedOccurrence;
use Carbon\CarbonImmutable;

class FrenchDateParser
{
    private const MONTHS = [
        'janvier' => 1,
        'fevrier' => 2,
        'février' => 2,
        'mars' => 3,
        'avril' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7,
        'aout' => 8,
        'août' => 8,
        'septembre' => 9,
        'octobre' => 10,
        'novembre' => 11,
        'decembre' => 12,
        'décembre' => 12,
    ];

    public function parseDateText(?string $text, ?CarbonImmutable $now = null): ?ParsedOccurrence
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $now ??= CarbonImmutable::now('Europe/Zurich');
        $text = $this->clean($text);

        if (preg_match('/du\s+(?:(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(\d{1,2})(?:er)?\s+(janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[ée]cembre)\s+(\d{4})\s+au\s+(?:(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(\d{1,2})(?:er)?\s+(janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[ée]cembre)\s+(\d{4})/iu', $text, $match) === 1) {
            return new ParsedOccurrence(
                $this->date((int) $match[1], $this->month($match[2]), (int) $match[3]),
                $this->date((int) $match[4], $this->month($match[5]), (int) $match[6])->endOfDay(),
            );
        }

        if (preg_match('/(\d{1,2})(?:er)?\s+([[:alpha:]éûôîàèùç]+)\s+(\d{4})\s*-\s*(\d{1,2})(?:er)?\s+([[:alpha:]éûôîàèùç]+)\s+(\d{4})/iu', $text, $match) === 1) {
            return new ParsedOccurrence(
                $this->date((int) $match[1], $this->month($match[2]), (int) $match[3]),
                $this->date((int) $match[4], $this->month($match[5]), (int) $match[6])->endOfDay(),
            );
        }

        if (preg_match('/du\s+(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})(?:\s+(\d{1,2})[:h](\d{2}))?\s+au\s+(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})(?:\s+(\d{1,2})[:h](\d{2}))?/iu', $text, $match) === 1) {
            return new ParsedOccurrence(
                $this->date(
                    (int) $match[1],
                    (int) $match[2],
                    $this->year((int) $match[3]),
                    isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0,
                    isset($match[5]) && $match[5] !== '' ? (int) $match[5] : 0,
                ),
                $this->date(
                    (int) $match[6],
                    (int) $match[7],
                    $this->year((int) $match[8]),
                    isset($match[9]) && $match[9] !== '' ? (int) $match[9] : 23,
                    isset($match[10]) && $match[10] !== '' ? (int) $match[10] : 59,
                ),
            );
        }

        if (preg_match('/(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})(?:\s+(\d{1,2})[:h](\d{2}))?\s*-\s*(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})(?:\s+(\d{1,2})[:h](\d{2}))?/iu', $text, $match) === 1) {
            return new ParsedOccurrence(
                $this->date(
                    (int) $match[1],
                    (int) $match[2],
                    $this->year((int) $match[3]),
                    isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0,
                    isset($match[5]) && $match[5] !== '' ? (int) $match[5] : 0,
                ),
                $this->date(
                    (int) $match[6],
                    (int) $match[7],
                    $this->year((int) $match[8]),
                    isset($match[9]) && $match[9] !== '' ? (int) $match[9] : 23,
                    isset($match[10]) && $match[10] !== '' ? (int) $match[10] : 59,
                ),
            );
        }

        if (preg_match('/(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})(?:\s*[,-]?\s*(\d{1,2})[:h](?:(\d{2}))?)?/iu', $text, $match) === 1) {
            return new ParsedOccurrence($this->date(
                (int) $match[1],
                (int) $match[2],
                $this->year((int) $match[3]),
                isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0,
                isset($match[5]) && $match[5] !== '' ? (int) $match[5] : 0,
            ));
        }

        if (preg_match('/(\d{1,2})\s*-\s*(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})/u', $text, $match) === 1) {
            $year = $this->year((int) $match[4]);

            return new ParsedOccurrence(
                $this->date((int) $match[1], (int) $match[3], $year),
                $this->date((int) $match[2], (int) $match[3], $year)->endOfDay(),
            );
        }

        if (preg_match('/(\d{1,2})[.\/](\d{1,2})\s+(\d{4})(?:\s*[,-]?\s*(\d{1,2})[:h](?:(\d{2}))?)?/iu', $text, $match) === 1) {
            return new ParsedOccurrence($this->date(
                (int) $match[1],
                (int) $match[2],
                (int) $match[3],
                isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0,
                isset($match[5]) && $match[5] !== '' ? (int) $match[5] : 0,
            ));
        }

        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})(?:\s*[,-]?\s*(\d{1,2})[:h](?:(\d{2}))?)?$/u', $text, $match) === 1) {
            $date = $this->date(
                (int) $match[1],
                (int) $match[2],
                $now->year,
                isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0,
                isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0,
            );

            return new ParsedOccurrence($date->endOfDay()->lessThan($now) ? $date->addYear() : $date);
        }

        if (preg_match('/(\d{1,2})(?:er)?\s+([[:alpha:]éûôîàèùç]+)\s*-\s*(\d{1,2})(?:er)?\s+([[:alpha:]éûôîàèùç]+)(?:\s+(\d{4}))?/iu', $text, $match) === 1) {
            $year = isset($match[5]) && $match[5] !== '' ? (int) $match[5] : $now->year;

            return new ParsedOccurrence(
                $this->date((int) $match[1], $this->month($match[2]), $year),
                $this->date((int) $match[3], $this->month($match[4]), $year)->endOfDay(),
            );
        }

        if (preg_match('/du\s+(?:(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(\d{1,2})(?:er)?\s+au\s+(?:(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(\d{1,2})(?:er)?\s+(janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[ée]cembre)(?:\s+(\d{4}))?/iu', $text, $match) === 1) {
            $year = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : $now->year;
            $month = $this->month($match[3]);

            return new ParsedOccurrence(
                $this->date((int) $match[1], $month, $year),
                $this->date((int) $match[2], $month, $year)->endOfDay(),
            );
        }

        if (preg_match('/du\s+(?:(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(\d{1,2})(?:er)?\s+(janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[ée]cembre)\s+au\s+(?:(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(\d{1,2})(?:er)?\s+(janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[ée]cembre)(?:\s+(\d{4}))?/iu', $text, $match) === 1) {
            $year = isset($match[5]) && $match[5] !== '' ? (int) $match[5] : $now->year;

            return new ParsedOccurrence(
                $this->date((int) $match[1], $this->month($match[2]), $year),
                $this->date((int) $match[3], $this->month($match[4]), $year)->endOfDay(),
            );
        }

        if (preg_match('/(\d{1,2})(?:er)?\s+([[:alpha:]éûôîàèùç]+)(?:\s+(\d{4}))?/iu', $text, $match) === 1) {
            preg_match_all('/(\d{1,2})(?:h|:)(?:(\d{2}))?/iu', $text, $timeMatches, PREG_SET_ORDER);
            $firstTime = $timeMatches[0] ?? [];
            $secondTime = $timeMatches[1] ?? [];
            $date = $this->date(
                (int) $match[1],
                $this->month($match[2]),
                isset($match[3]) && $match[3] !== '' ? (int) $match[3] : $now->year,
                isset($firstTime[1]) ? (int) $firstTime[1] : 0,
                isset($firstTime[2]) && $firstTime[2] !== '' ? (int) $firstTime[2] : 0,
            );
            $end = isset($secondTime[1])
                ? $date->setTime((int) $secondTime[1], isset($secondTime[2]) && $secondTime[2] !== '' ? (int) $secondTime[2] : 0)
                : null;

            if ($end?->lessThan($date)) {
                $end = $end->addDay();
            }

            return new ParsedOccurrence($date, $end);
        }

        return null;
    }

    /**
     * @param  list<string>  $texts
     * @return list<ParsedOccurrence>
     */
    public function parseMany(array $texts, ?CarbonImmutable $now = null): array
    {
        return collect($texts)
            ->map(fn (string $text): ?ParsedOccurrence => $this->parseDateText($text, $now))
            ->filter()
            ->unique(fn (ParsedOccurrence $occurrence): string => $occurrence->key())
            ->values()
            ->all();
    }

    private function clean(string $text): string
    {
        $text = str_replace(['–', '—'], '-', $text);

        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?: trim($text);
    }

    private function month(string $month): int
    {
        return self::MONTHS[$this->clean($month)] ?? 1;
    }

    private function year(int $year): int
    {
        return $year < 100 ? 2000 + $year : $year;
    }

    private function date(int $day, int $month, int $year, int $hour = 0, int $minute = 0): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, $hour, $minute, 0, 'Europe/Zurich');
    }
}
