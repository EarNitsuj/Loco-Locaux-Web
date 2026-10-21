<?php

namespace App\Crawling\Data;

use App\Models\SourceEvent;

final readonly class SourceEventObservation
{
    public function __construct(
        public SourceEvent $sourceEvent,
        public bool $wasCreated,
        public bool $referenceChanged,
    ) {}
}
