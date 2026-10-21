<?php

namespace App\Crawling\Data;

final readonly class FetchedPage
{
    public function __construct(
        public string $url,
        public string $finalUrl,
        public string $body,
        public int $status,
        public ?string $contentType,
    ) {}
}
