<?php

namespace App\Crawling\Data;

final readonly class ImageResolutionResult
{
    public function __construct(
        public ?string $resolvedUrl,
        public bool $valid,
        public string $reason,
        public ?string $finalRedirectUrl = null,
        public ?string $contentType = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resolved_url' => $this->resolvedUrl,
            'valid' => $this->valid,
            'reason' => $this->reason,
            'final_redirect_url' => $this->finalRedirectUrl,
            'content_type' => $this->contentType,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            resolvedUrl: $data['resolved_url'] ?? null,
            valid: (bool) ($data['valid'] ?? false),
            reason: (string) ($data['reason'] ?? 'unknown'),
            finalRedirectUrl: $data['final_redirect_url'] ?? null,
            contentType: $data['content_type'] ?? null,
        );
    }
}
