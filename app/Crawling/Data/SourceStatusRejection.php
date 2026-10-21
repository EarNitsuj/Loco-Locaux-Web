<?php

namespace App\Crawling\Data;

final readonly class SourceStatusRejection
{
    public function __construct(
        public string $evidenceText,
        public string $evidenceType,
        public string $reason,
        public ?string $proposedStatus = null,
        public ?string $sourceSelector = null,
    ) {}
}
