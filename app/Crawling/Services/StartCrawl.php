<?php

namespace App\Crawling\Services;

use App\Crawling\Data\CrawlOptions;
use App\Enums\CrawlRunStatus;
use App\Models\CrawlRun;
use App\Models\EventSource;
use Illuminate\Database\Eloquent\Collection;

class StartCrawl
{
    public function __construct(
        private readonly CrawlerAdapterRegistry $registry
    ) {}

    public function start(CrawlOptions $options): CrawlRun
    {
        return $this->createRun($options, $this->sources($options));
    }

    /**
     * Start a test run for exactly ONE source, rather than every source
     * belonging to the selected commune.
     */
    public function startForSource(CrawlOptions $options, EventSource $source): CrawlRun
    {
        if (
            ! $source->is_active || ! is_string($source->adapter)
            || ! $this->registry->hasAdapter($source->adapter)
        ) {
            throw new \InvalidArgumentException('Selected source is inactive or unsupported.');
        }

        if (
            $options->communeSlugs === null
            || $source->commune === null
            || ! in_array($source->commune->slug, $options->communeSlugs, true)
        ) {
            throw new \InvalidArgumentException('Source does not belong to the selected commune.');
        }

        return $this->createRun($options, new Collection([$source]));
    }

    /** @param Collection<int, EventSource> $sources */
    private function createRun(CrawlOptions $options, Collection $sources): CrawlRun
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $sources): CrawlRun {
            $run = CrawlRun::create([
                'status' => CrawlRunStatus::Running,
                'options' => $options->toArray(),
                'sources_total' => $sources->count(),
                'started_at' => now(),
            ]);

            foreach ($sources as $source) {
                $run->sources()->create([
                    'event_source_id' => $source->id,
                    'status' => CrawlRunStatus::Pending,
                    'snapshot_requested' => false,
                    'snapshot_complete' => false,
                    'snapshot_incomplete_reason' => 'limited_or_non_snapshot_run',
                ]);
            }

            if ($sources->isEmpty()) {
                $run->forceFill([
                    'status' => CrawlRunStatus::Completed,
                    'finished_at' => now(),
                ])->save();
            }

            return $run;
        });
    }

    /**
     * @return Collection<int, EventSource>
     */
    /**
     * @return Collection<int, EventSource>
     */
    private function sources(
        CrawlOptions $options
    ): Collection {
        $query = EventSource::query()
            ->with('commune')
            ->where('is_active', true)
            ->whereNotNull('adapter');

        /*
    |--------------------------------------------------------------------------
    | Source selection
    |--------------------------------------------------------------------------
    |
    | No filter:
    |   all active sources
    |
    | --communes=avusy:
    |   Avusy sources only
    |
    | --adapter=geneve-tourism:
    |   Geneva Tourism only
    |
    | Both:
    |   Avusy sources OR Geneva Tourism
    |
    */

        if (
            $options->communeSlugs !== null
            && $options->sourceAdapter !== null
        ) {
            $query->where(
                function ($selection) use ($options): void {
                    $selection
                        ->whereHas(
                            'commune',
                            fn($communeQuery) =>
                            $communeQuery->whereIn(
                                'slug',
                                $options->communeSlugs
                            )
                        )
                        ->orWhere(
                            'adapter',
                            $options->sourceAdapter
                        );
                }
            );
        } elseif (
            $options->communeSlugs !== null
        ) {
            $query->whereHas(
                'commune',
                fn($communeQuery) =>
                $communeQuery->whereIn(
                    'slug',
                    $options->communeSlugs
                )
            );
        } elseif (
            $options->sourceAdapter !== null
        ) {
            $query->where(
                'adapter',
                $options->sourceAdapter
            );
        }

        return $query
            ->get()
            ->filter(
                fn(EventSource $source): bool =>
                $source->adapter !== null
                    && $this->registry->hasAdapter(
                        $source->adapter
                    )
            )
            ->values();
    }
}
