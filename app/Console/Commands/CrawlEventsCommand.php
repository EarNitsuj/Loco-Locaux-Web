<?php

namespace App\Console\Commands;

use App\Crawling\Data\CrawlOptions;
use App\Crawling\Services\StartCrawl;
use App\Enums\CrawlRunStatus;
use App\Jobs\CrawlCommune;
use App\Models\Commune;
use App\Models\EventSource;
use Illuminate\Console\Command;
use App\Models\CrawlRun;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CrawlEventsCommand extends Command
{
    protected $signature = 'events:crawl
        {--communes= : Comma-separated commune slugs. Omitted means active sources with registered adapters.}
        {--adapter= : Crawl only active event sources using this adapter.}
        {--num-events= : Maximum number of event details per source.}
        {--all : Crawl all discovered events within configured page/request limits.}
        {--force : Rewrite events even when raw content hash is unchanged.}
        {--without-enrichment : Crawl and persist without dispatching the configured AI pipeline.}
        {--scheduled : Mark this as an automated production crawl.}
        {--sync : Execute source jobs synchronously.}';

    protected $description = 'Crawl configured event sources into Loco-Locaux events.';

    public function handle(StartCrawl $starter): int
    {
        $startedAt = microtime(true);

        $numEvents = $this->option('num-events');
        $all = (bool) $this->option('all');

        /*
        |--------------------------------------------------------------------------
        | Crawl size validation
        |--------------------------------------------------------------------------
        */

        if ($all && $numEvents !== null) {
            $this->error(
                'The --all option conflicts with --num-events. Choose one crawl size.'
            );

            return self::FAILURE;
        }

        $eventLimit = null;

        if ($numEvents !== null) {
            if (
                ! ctype_digit((string) $numEvents)
                || (int) $numEvents < 1
            ) {
                $this->error(
                    '--num-events must be a positive integer.'
                );

                return self::FAILURE;
            }

            $eventLimit = (int) $numEvents;
        }

        /*
        |--------------------------------------------------------------------------
        | Source selection
        |--------------------------------------------------------------------------
        */

        $communes = $this->communes();

        if ($communes === false) {
            return self::FAILURE;
        }

        $adapter = $this->adapter();

        if ($adapter === false) {
            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Crawl options
        |--------------------------------------------------------------------------
        |
        | NOTE:
        | CrawlOptions::fromConfig() needs the sourceAdapter argument added.
        | We will update that immediately after this command.
        |
        */

        $options = CrawlOptions::fromConfig(
            communeSlugs: $communes,
            eventLimit: $eventLimit,
            all: $all,
            force: (bool) $this->option('force'),
            withoutEnrichment: (bool) $this->option('without-enrichment'),
            sourceAdapter: $adapter,
            scheduled: (bool) $this->option('scheduled'),
        );

        /*
|--------------------------------------------------------------------------
| Production crawl lock
|--------------------------------------------------------------------------
|
| --all represents a potentially production-sized crawl.
| Scheduled runs always require the same lock.
|
| Limited development crawls such as --num-events=1 remain unaffected.
|
*/

        $requiresProductionLock =
            $options->scheduled
            || $options->all;

        $productionLock = null;

        if ($requiresProductionLock) {
            $productionLock = Cache::lock(
                (string) config(
                    'crawling.production_lock_name',
                    'loco-locaux:production-crawl'
                ),
                (int) config(
                    'crawling.production_lock_seconds',
                    21600
                ),
            );

            if (! $productionLock->get()) {
                $this->warn(
                    'Another production crawl is still active. '
                        . 'This crawl was skipped.'
                );

                return self::SUCCESS;
            }
        }

        try {
            $run = $starter->start(
                $options
            );
        } catch (Throwable $exception) {
            $productionLock?->release();

            report($exception);

            $this->error(
                'Unable to start crawl: '
                    . $exception->getMessage()
            );

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Run execution metadata
        |--------------------------------------------------------------------------
        */

        $run->forceFill([
            'trigger' =>
            $options->scheduled
                ? 'scheduled'
                : 'manual',
            /*
            * The owner token allows CrawlRunFinalizer to release this exact lock
            * from another queue-worker process.
            */
            'lock_owner' =>
            $productionLock?->owner(),
        ])->save();

        /*
        |--------------------------------------------------------------------------
        | Snapshot intent
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | --all only means "try to enumerate everything".
        |
        | It does NOT prove the adapter exhausted the remote source because listing
        | pages, detail requests and duration are still bounded.
        |
        */

        $snapshotRequested =
            $options->requestsCompleteSnapshot();

        $run->sources()->update([
            'snapshot_requested' =>
            $snapshotRequested,

            'snapshot_complete' =>
            $snapshotRequested
                ? null
                : false,

            'snapshot_incomplete_reason' =>
            $snapshotRequested
                ? null
                : 'limited_or_non_snapshot_run',
        ]);

        $run = $starter->start($options);

        $run->load(
            'sources.eventSource.commune'
        );

        /*
        |--------------------------------------------------------------------------
        | Coverage / selection summary
        |--------------------------------------------------------------------------
        */

        if ($adapter !== null) {
            /*
            | Adapter-based sources may intentionally have no commune.
            |
            | Example:
            | Geneva Tourism -> commune_id = null
            */

            $attemptedSources = $run->sources
                ->map(
                    fn($sourceRun): string =>
                    $sourceRun->eventSource->name
                )
                ->unique()
                ->values()
                ->all();

            $this->line(
                'Adapter requested: ' . $adapter
            );

            $this->line(
                'Supported and attempted sources: '
                    . count($attemptedSources)
            );

            $this->table(
                [
                    'Selection',
                    'Count',
                    'Sources',
                ],
                [
                    [
                        'Adapter',
                        1,
                        $adapter,
                    ],
                    [
                        'Supported and attempted',
                        count($attemptedSources),
                        implode(', ', $attemptedSources)
                            ?: 'None',
                    ],
                ],
            );
        } else {
            /*
            |--------------------------------------------------------------------------
            | Existing commune coverage reporting
            |--------------------------------------------------------------------------
            */

            $requestedSlugs = $communes
                ?? Commune::query()
                ->orderBy('slug')
                ->pluck('slug')
                ->all();

            $supportedSlugs = $run->sources
                ->pluck('eventSource.commune.slug')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $unsupportedSlugs = array_values(
                array_diff(
                    $requestedSlugs,
                    $supportedSlugs
                )
            );

            $this->line(
                'Supported and attempted: '
                    . count($supportedSlugs)
            );

            $this->line(
                'Unsupported or inactive: '
                    . count($unsupportedSlugs)
                    . (
                        $unsupportedSlugs === []
                        ? ''
                        : ' ('
                        . implode(', ', $unsupportedSlugs)
                        . ')'
                    )
            );

            $this->table(
                [
                    'Coverage',
                    'Count',
                    'Communes',
                ],
                [
                    [
                        'Configured/requested',
                        count($requestedSlugs),
                        implode(', ', $requestedSlugs),
                    ],
                    [
                        'Supported and attempted',
                        count($supportedSlugs),
                        implode(', ', $supportedSlugs)
                            ?: 'None',
                    ],
                    [
                        'Unsupported or inactive',
                        count($unsupportedSlugs),
                        implode(', ', $unsupportedSlugs)
                            ?: 'None',
                    ],
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | No matching sources
        |--------------------------------------------------------------------------
        */

        if ($run->sources_total === 0) {
            /*
            * No queued source job will ever reach CrawlRunFinalizer, therefore this
            * command itself must release the production lock.
            */
            if ($productionLock !== null) {
                $productionLock->release();

                $run->forceFill([
                    'lock_owner' => null,
                ])->save();
            }

            $this->warn(
                'No active event sources with registered adapters matched the request.'
            );

            $this->line(
                "Crawl run {$run->id} completed without work."
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Sources selected for this run
        |--------------------------------------------------------------------------
        */

        $this->line(
            "Started crawl run {$run->id} for {$run->sources_total} source(s)."
        );

        $this->table(
            [
                'Commune',
                'Source',
                'Adapter',
                'Listing URL',
            ],
            $run->sources
                ->map(
                    fn($sourceRun): array => [
                        /*
                        | Aggregator sources such as Geneva Tourism
                        | deliberately have commune_id = null.
                        */

                        $sourceRun->eventSource
                            ->commune
                            ?->slug
                            ?? 'Aggregator',

                        $sourceRun->eventSource->name,

                        $sourceRun->eventSource->adapter,

                        $sourceRun->eventSource->listing_url,
                    ]
                )
                ->all(),
        );

        /*
        |--------------------------------------------------------------------------
        | Execute source jobs
        |--------------------------------------------------------------------------
        */

        foreach ($run->sources as $sourceRun) {
            if ($this->option('sync')) {
                CrawlCommune::dispatchSync(
                    $sourceRun->id
                );
            } else {
                CrawlCommune::dispatch(
                    $sourceRun->id
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Reload completed run
        |--------------------------------------------------------------------------
        */

        $run->refresh();

        $run->load(
            'sources.eventSource.commune'
        );

        /*
        |--------------------------------------------------------------------------
        | Per-source results
        |--------------------------------------------------------------------------
        */

        $this->table(
            [
                'Commune',
                'Source',
                'Status',
                'Created',
                'Updated',
                'Unchanged',
                'Skipped',
                'Failed',
            ],
            $run->sources
                ->map(
                    fn($sourceRun): array => [
                        $sourceRun->eventSource
                            ->commune
                            ?->slug
                            ?? 'Aggregator',

                        $sourceRun->eventSource->name,

                        $this->status(
                            $sourceRun
                        ),

                        $sourceRun->events_created,

                        $sourceRun->events_updated,

                        $sourceRun->events_unchanged,

                        $sourceRun->events_skipped,

                        $sourceRun->events_failed,
                    ]
                )
                ->all(),
        );

        /*
        |--------------------------------------------------------------------------
        | Run totals
        |--------------------------------------------------------------------------
        */

        $this->table(
            [
                'Run',
                'Status',
                'Created',
                'Updated',
                'Unchanged',
                'Skipped',
                'Failed',
            ],
            [
                [
                    $run->id,

                    $this->status(
                        $run
                    ),

                    $run->events_created,

                    $run->events_updated,

                    $run->events_unchanged,

                    $run->events_skipped,

                    $run->events_failed,
                ],
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Source outcomes
        |--------------------------------------------------------------------------
        */

        $this->table(
            [
                'Source outcome',
                'Count',
            ],
            [
                [
                    'Returned event references',

                    $run->sources
                        ->where(
                            'references_discovered',
                            '>',
                            0
                        )
                        ->count(),
                ],

                [
                    'Empty result',

                    $run->sources
                        ->where(
                            'references_discovered',
                            0
                        )
                        ->where(
                            'status',
                            CrawlRunStatus::Completed
                        )
                        ->count(),
                ],

                [
                    'Completed with errors',

                    $run->sources
                        ->where(
                            'status',
                            CrawlRunStatus::CompletedWithErrors
                        )
                        ->count(),
                ],

                [
                    'Failed',

                    $run->sources
                        ->where(
                            'status',
                            CrawlRunStatus::Failed
                        )
                        ->count(),
                ],
            ],
        );

        $this->line(
            'Elapsed: '
                . number_format(
                    microtime(true) - $startedAt,
                    2
                )
                . 's'
        );

        /*
        |--------------------------------------------------------------------------
        | Synchronous command exit status
        |--------------------------------------------------------------------------
        */

        if (
            $this->option('sync')
            && in_array(
                $run->status,
                [
                    CrawlRunStatus::Failed,
                    CrawlRunStatus::CompletedWithErrors,
                ],
                true
            )
        ) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>|false|null
     */
    private function communes(): array|false|null
    {
        $raw = trim(
            (string) (
                $this->option('communes')
                ?? ''
            )
        );

        if ($raw === '') {
            return null;
        }

        if (
            mb_strtolower($raw)
            === 'all'
        ) {
            return null;
        }

        $slugs = collect(
            explode(',', $raw)
        )
            ->map(
                fn(string $slug): string =>
                trim($slug)
            )
            ->filter()
            ->values()
            ->all();

        $known = Commune::query()
            ->pluck('slug')
            ->all();

        $unknown = array_values(
            array_diff(
                $slugs,
                $known
            )
        );

        if ($unknown !== []) {
            foreach ($unknown as $slug) {
                $suggestion =
                    $this->suggestion(
                        $slug,
                        $known
                    );

                $message =
                    "Unknown commune slug [{$slug}].";

                if ($suggestion !== null) {
                    $message .=
                        " Did you mean [{$suggestion}]?";
                }

                $this->error(
                    $message
                );
            }

            return false;
        }

        return $slugs;
    }

    /**
     * @return string|false|null
     */
    private function adapter(): string|false|null
    {
        $adapter = trim(
            (string) (
                $this->option('adapter')
                ?? ''
            )
        );

        if ($adapter === '') {
            return null;
        }

        $known = EventSource::query()
            ->whereNotNull('adapter')
            ->pluck('adapter')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (
            in_array(
                $adapter,
                $known,
                true
            )
        ) {
            return $adapter;
        }

        $suggestion = $this->suggestion(
            $adapter,
            $known
        );

        $message =
            "Unknown event source adapter [{$adapter}].";

        if ($suggestion !== null) {
            $message .=
                " Did you mean [{$suggestion}]?";
        }

        $this->error(
            $message
        );

        return false;
    }

    /**
     * @param  list<string>  $known
     */
    private function suggestion(
        string $value,
        array $known
    ): ?string {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($known as $candidate) {
            $candidateDistance =
                levenshtein(
                    $value,
                    $candidate
                );

            if (
                $candidateDistance
                < $distance
            ) {
                $distance =
                    $candidateDistance;

                $best =
                    $candidate;
            }
        }

        return $distance <= 2
            ? $best
            : null;
    }

    private function status(
        object $run
    ): string {
        return is_string($run->status)
            ? $run->status
            : $run->status->value;
    }
}
