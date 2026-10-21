<?php

namespace App\Crawling\Support;

use App\Crawling\Data\RawEvent;
use Carbon\CarbonImmutable;

class EventQualifier
{
    public function qualifies(RawEvent $event, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now('Europe/Zurich');

        foreach ($event->occurrences as $occurrence) {
            if (($occurrence->endsAt ?? $occurrence->startsAt)->greaterThanOrEqualTo($now)) {
                return true;
            }
        }

        if ($event->endsAt?->greaterThanOrEqualTo($now)) {
            return true;
        }

        if ($event->startsAt?->greaterThanOrEqualTo($now)) {
            return true;
        }

        return $event->startsAt === null && $event->endsAt === null;
    }
}
