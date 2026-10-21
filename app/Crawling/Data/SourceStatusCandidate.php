<?php

namespace App\Crawling\Data;

final readonly class SourceStatusCandidate
{
    public function __construct(
        public string $normalizedStatus,
        public string $originalLabel,
        public ?string $note,
        public string $evidenceText,
        public string $evidenceType,
        public string $detectionMethod,
        public ?string $sourceSelector = null,
        public ?float $confidence = null,
    ) {}
}
