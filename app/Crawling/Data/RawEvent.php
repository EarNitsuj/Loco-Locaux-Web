<?php

namespace App\Crawling\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class RawEvent
{
    /**
     * @param  list<ParsedOccurrence>  $occurrences
     * @param  array<string, mixed>  $metadata
     * @param  list<RawStatusEvidence>  $statusEvidence
     */
    public function __construct(
        public string $canonicalUrl,
        public string $rawHtml,
        public ?string $externalId = null,
        public ?string $title = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public bool $isRecurring = false,
        public ?string $recurrenceSummary = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public ?string $sourceLanguage = 'fr',
        public ?CarbonImmutable $sourcePublishedAt = null,
        public array $occurrences = [],
        public array $metadata = [],
        public ?string $canonicalDescriptionHtml = null,
        public ?string $imageValidationReason = null,
        public ?string $sourceStatus = null,
        public ?string $sourceStatusLabel = null,
        public ?string $sourceStatusNote = null,
        public ?string $sourceStatusDetectionMethod = null,
        public array $statusEvidence = [],
    ) {
        if (trim(strip_tags($rawHtml)) === '') {
            throw new InvalidArgumentException('Raw event HTML must contain event-specific content.');
        }
    }
}
