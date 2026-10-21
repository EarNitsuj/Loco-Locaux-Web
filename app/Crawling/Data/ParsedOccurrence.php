<?php

namespace App\Crawling\Data;

use Carbon\CarbonImmutable;

final readonly class ParsedOccurrence
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt = null,
    ) {}

    public function key(): string
    {
        return $this->startsAt->toIso8601String().'|'.$this->endsAt?->toIso8601String();
    }
}
