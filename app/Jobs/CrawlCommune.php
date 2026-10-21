<?php

namespace App\Jobs;

use App\Crawling\Services\SourceCrawlerRunner;
use App\Models\CrawlRunSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CrawlCommune implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $crawlRunSourceId) {}

    public function handle(SourceCrawlerRunner $runner): void
    {
        $runner->run(CrawlRunSource::findOrFail($this->crawlRunSourceId));
    }
}
