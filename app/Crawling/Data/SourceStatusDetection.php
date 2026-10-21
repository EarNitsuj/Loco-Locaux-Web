<?php

namespace App\Crawling\Data;

final readonly class SourceStatusDetection
{
    public ?string $status;

    public ?string $label;

    public ?string $note;

    public ?string $method;

    /**
     * @param  list<RawStatusEvidence>  $evidence
     * @param  list<SourceStatusRejection>  $rejections
     */
    public function __construct(
        public ?SourceStatusCandidate $candidate = null,
        public array $evidence = [],
        public array $rejections = [],
        public bool $successful = true,
    ) {
        $this->status = $candidate?->normalizedStatus;
        $this->label = $candidate?->originalLabel;
        $this->note = $candidate?->note;
        $this->method = $candidate?->detectionMethod;
    }
}
