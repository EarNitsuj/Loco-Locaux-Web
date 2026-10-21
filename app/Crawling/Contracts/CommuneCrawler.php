<?php

namespace App\Crawling\Contracts;

use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\RawEvent;
use App\Models\EventSource;

interface CommuneCrawler
{
    /**
     * @return iterable<EventReference>
     */
    public function discover(EventSource $source, CrawlOptions $options): iterable;

    public function fetch(EventSource $source, EventReference $reference): RawEvent;
}
