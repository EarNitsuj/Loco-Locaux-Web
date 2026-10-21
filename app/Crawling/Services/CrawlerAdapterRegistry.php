<?php

namespace App\Crawling\Services;

use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Exceptions\UnsupportedAdapterException;
use App\Models\EventSource;

class CrawlerAdapterRegistry
{
    public function forSource(EventSource $source): CommuneCrawler
    {
        if (! $source->is_active) {
            throw new UnsupportedAdapterException("Event source {$source->id} is inactive.");
        }

        if ($source->adapter === null || trim($source->adapter) === '') {
            throw new UnsupportedAdapterException("Event source {$source->id} has no crawler adapter.");
        }

        $class = config("crawling.adapters.{$source->adapter}");

        if (! is_string($class) || $class === '') {
            throw new UnsupportedAdapterException("Unknown crawler adapter [{$source->adapter}].");
        }

        $adapter = app($class);

        if (! $adapter instanceof CommuneCrawler) {
            throw new UnsupportedAdapterException("Crawler adapter [{$source->adapter}] does not implement CommuneCrawler.");
        }

        return $adapter;
    }

    public function hasAdapter(string $adapter): bool
    {
        return is_string(config("crawling.adapters.{$adapter}"));
    }
}
