<?php

namespace App\Crawling\Fetchers;

use App\Crawling\Data\ImageResolutionResult;
use App\Crawling\Services\ImageResolver;

class ImageValidator
{
    private ?ImageResolutionResult $lastResult = null;

    public function __construct(private readonly ImageResolver $resolver) {}

    public function validate(?string $url, ?string $eventDetailUrl = null, ?string $sourceBaseUrl = null, array $allowedHosts = []): ?string
    {
        $this->lastResult = $this->resolver->resolve($url, $eventDetailUrl, $sourceBaseUrl, $allowedHosts);

        return $this->lastResult->valid
            ? ($this->lastResult->finalRedirectUrl ?? $this->lastResult->resolvedUrl)
            : null;
    }

    public function lastResult(): ?ImageResolutionResult
    {
        return $this->lastResult;
    }
}
