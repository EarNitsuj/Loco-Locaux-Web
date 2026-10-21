<?php

namespace App\Crawling\Services;

use App\Crawling\Data\CrawlOptions;
use App\Crawling\Exceptions\ConcurrentCrawlException;
use App\Enums\CrawlRunStatus;
use App\Jobs\FetchEvent;
use App\Models\CrawlRunSource;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SourceCrawlerRunner
{
    public function __construct(
        private readonly CrawlerAdapterRegistry $registry,
        private readonly CrawlRunFinalizer $finalizer,
    ) {}

    public function run(CrawlRunSource $runSource): void
    {
        /** @var \App\Models\EventSource $source */
        $source = $runSource
            ->eventSource()
            ->with('commune')
            ->firstOrFail();

        $options = CrawlOptions::fromArray(
            $runSource
                ->crawlRun()
                ->firstOrFail()
                ->options
                ?? []
        );

        $lock = Cache::lock(
            "event-source-crawl:{$source->id}",
            (int) config(
                'crawling.source_lock_seconds'
            )
        );

        if (! $lock->get()) {
            throw new ConcurrentCrawlException(
                "Crawl already running for source {$source->id}."
            );
        }

        try {
            $runSource->forceFill([
                'status' =>
                CrawlRunStatus::Running,

                'started_at' =>
                now(),

                'failure_reason' =>
                null,
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Discover source records
            |--------------------------------------------------------------------------
            |
            | Let the adapter perform its complete discovery operation first.
            |
            | This distinction matters because:
            |
            |     references_discovered
            |
            | must represent what the source adapter actually discovered,
            | not what survives a later testing/detail-request limit.
            |
            */

            $discoveredReferences = collect(
                $this->registry
                    ->forSource($source)
                    ->discover(
                        $source,
                        $options
                    )
            )
                ->unique(
                    fn($reference) =>
                    $reference->hash()
                )
                ->values();

            $referencesDiscovered =
                $discoveredReferences->count();

            /*
            |--------------------------------------------------------------------------
            | Select references to process
            |--------------------------------------------------------------------------
            |
            | --all has explicit production/completeness semantics:
            |
            |     process EVERY reference returned by the adapter.
            |
            | Do not silently truncate an --all crawl because of the ordinary
            | maxDetailRequests testing/safety limit.
            |
            | Example:
            |
            |     Corsier discovers 51 records
            |
            | Old:
            |     --all
            |     maxDetailRequests = 40
            |     → only 40 processed
            |
            | New:
            |     --all
            |     → all 51 processed
            |
            | Bounded/manual crawls continue respecting both eventLimit and
            | maxDetailRequests.
            |
            */

            if ($options->all) {
                $references =
                    $discoveredReferences;
            } else {
                $references =
                    $discoveredReferences;

                if ($options->eventLimit !== null) {
                    $references = $references
                        ->take(
                            $options->eventLimit
                        )
                        ->values();
                }

                /*
                | maxDetailRequests remains useful for bounded/manual/test
                | crawls, where intentionally limiting work is expected.
                */

                if ($options->maxDetailRequests > 0) {
                    $references = $references
                        ->take(
                            $options->maxDetailRequests
                        )
                        ->values();
                }
            }

            $referencesQueued =
                $references->count();

            /*
            |--------------------------------------------------------------------------
            | Crawl source statistics
            |--------------------------------------------------------------------------
            */

            $runSource->forceFill([
                'references_discovered' =>
                $referencesDiscovered,

                'references_queued' =>
                $referencesQueued,
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Fetch/process discovered records
            |--------------------------------------------------------------------------
            */

            foreach (
                $references
                as $index => $reference
            ) {
                FetchEvent::dispatchSync(
                    $runSource->id,
                    $reference->toArray()
                );

                if (
                    $options->requestDelayMs > 0
                    && $index
                    < $referencesQueued - 1
                ) {
                    usleep(
                        $options->requestDelayMs
                            * 1000
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Source result
            |--------------------------------------------------------------------------
            */

            $runSource->refresh();

            $status = $runSource
                ->eventResults()
                ->where(
                    'status',
                    'failed'
                )
                ->exists()
                ? CrawlRunStatus::CompletedWithErrors
                : CrawlRunStatus::Completed;

            $runSource->forceFill([
                'status' =>
                $status,

                'finished_at' =>
                now(),
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Missing-source lifecycle
            |--------------------------------------------------------------------------
            |
            | Intentionally disabled.
            |
            | SourceEvent.last_seen_at / missing_since_at own this lifecycle.
            |
            | We must not mark records missing until source discovery can prove
            | that a complete source snapshot was obtained.
            |
            | This will be re-enabled only after adapter-specific pagination /
            | cursor exhaustion semantics are complete.
            |
            */

            $source->forceFill([
                'last_crawled_at' =>
                now(),

                'last_success_at' =>
                $status === CrawlRunStatus::Completed
                    ? now()
                    : $source->last_success_at,

                'consecutive_failures' =>
                $status === CrawlRunStatus::Completed
                    ? 0
                    : $source->consecutive_failures + 1,
            ])->save();
        } catch (Throwable $exception) {
            $runSource->forceFill([
                'status' =>
                CrawlRunStatus::Failed,

                'finished_at' =>
                now(),

                'failure_reason' =>
                $exception->getMessage(),
            ])->save();

            $source->forceFill([
                'last_crawled_at' =>
                now(),

                'consecutive_failures' =>
                $source->consecutive_failures + 1,
            ])->save();

            Log::warning(
                'Crawler source failed',
                [
                    'source_id' =>
                    $source->id,

                    'adapter' =>
                    $source->adapter,

                    'error' =>
                    $exception->getMessage(),
                ]
            );
        } finally {
            $this->release(
                $lock
            );

            $this->finalizer
                ->finalizeSource(
                    $runSource->refresh()
                );
        }
    }

    private function release(
        Lock $lock
    ): void {
        try {
            $lock->release();
        } catch (Throwable) {
            //
        }
    }
}
