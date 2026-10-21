<?php

namespace App\Crawling\Data;

use App\Enums\CrawlEventStatus;
use App\Models\Event;

final readonly class PersistedEventResult
{
    public function __construct(
        public CrawlEventStatus $status,
        public ?Event $event = null,
        public ?string $errorMessage = null,
    ) {}
}
