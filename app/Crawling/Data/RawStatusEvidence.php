<?php

namespace App\Crawling\Data;

final readonly class RawStatusEvidence
{
    public function __construct(
        public string $text,
        public string $evidenceType,
        public string $detectionMethod,
        public ?string $sourceSelector = null,
    ) {}
}
