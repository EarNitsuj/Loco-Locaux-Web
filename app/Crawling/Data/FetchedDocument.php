<?php

namespace App\Crawling\Data;

class FetchedDocument
{
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly string $body,
        public readonly int $status,
        public readonly ?string $contentType,
    ) {}
}
