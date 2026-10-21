<?php

namespace App\Console\Commands;

use App\Crawling\Data\CrawlOptions;
use App\Crawling\Services\CrawlerAdapterRegistry;
use App\Crawling\Services\EventCountEvaluator;
use App\Models\Commune;
use App\Models\EventSource;
use Illuminate\Console\Command;
use Throwable;

class CountEventsCommand extends Command
{
    protected $signature = 'events:count
        {--communes= : Comma-separated commune slugs. Omitted means all active sources.}
        {--adapter= : Count only active event sources using this adapter.}';

    protected $description =
    'Discover potential events, estimate AI candidates, and show current crawl workload without fetching or persisting event details.';

    public function handle(
        CrawlerAdapterRegistry $registry,
        EventCountEvaluator $evaluator,
    ): int {
        $startedAt = microtime(true);

        /*
        |--------------------------------------------------------------------------
        | Input validation
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
        | Discovery options
        |--------------------------------------------------------------------------
        |
        | all=true means each adapter attempts natural source exhaustion.
        |
        | This command only performs:
        |
        | - adapter discovery
        | - in-memory reference deduplication
        | - history-independent AI-candidate evaluation
        | - read-only SourceEvent workload evaluation
        |
        | It does NOT:
        |
        | - fetch individual detail pages
        | - create SourceEvent records
        | - update SourceEvent records
        | - update last_seen_at
        | - create Event records
        | - create CrawlRun records
        | - invoke AI
        | - dispatch translations
        |
        */

        $options = CrawlOptions::fromConfig(
            communeSlugs: $communes,
            eventLimit: null,
            all: true,
            force: false,
            withoutEnrichment: true,
            sourceAdapter: $adapter,
            scheduled: false,
        );

        /*
        |--------------------------------------------------------------------------
        | Source selection
        |--------------------------------------------------------------------------
        */

        $query = EventSource::query()
            ->with('commune')
            ->where(
                'is_active',
                true
            )
            ->whereNotNull(
                'adapter'
            );

        /*
         * Preserve the same commune + adapter union semantics used by the
         * real crawl command.
         */
        if (
            $communes !== null
            && $adapter !== null
        ) {
            $query->where(
                function ($selection) use (
                    $communes,
                    $adapter
                ): void {
                    $selection
                        ->whereHas(
                            'commune',
                            fn($communeQuery) =>
                            $communeQuery->whereIn(
                                'slug',
                                $communes
                            )
                        )
                        ->orWhere(
                            'adapter',
                            $adapter
                        );
                }
            );
        } elseif ($communes !== null) {
            $query->whereHas(
                'commune',
                fn($communeQuery) =>
                $communeQuery->whereIn(
                    'slug',
                    $communes
                )
            );
        } elseif ($adapter !== null) {
            $query->where(
                'adapter',
                $adapter
            );
        }

        $sources = $query
            ->orderBy(
                'name'
            )
            ->get()
            ->filter(
                fn(EventSource $source): bool =>
                $source->adapter !== null
                    && $registry->hasAdapter(
                        $source->adapter
                    )
            )
            ->values();

        if ($sources->isEmpty()) {
            $this->warn(
                'No active event sources with registered adapters matched the request.'
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Counters
        |--------------------------------------------------------------------------
        */

        $rows = [];

        $successfulSources = 0;
        $failedSources = 0;

        $totalDiscovered = 0;
        $totalAiCandidates = 0;
        $totalPastExcluded = 0;

        $sourcesWithHistory = 0;
        $sourcesWithoutHistory = 0;

        $knownHistoryWorkload = 0;

        /*
        |--------------------------------------------------------------------------
        | Discover and evaluate each source
        |--------------------------------------------------------------------------
        */

        foreach ($sources as $source) {
            $sourceStartedAt =
                microtime(true);

            try {
                $crawler =
                    $registry->forSource(
                        $source
                    );

                /*
                |--------------------------------------------------------------------------
                | Discovery
                |--------------------------------------------------------------------------
                |
                | Keep uniqueness exactly aligned with SourceCrawlerRunner.
                |
                */

                $references = collect(
                    $crawler->discover(
                        $source,
                        $options
                    )
                )
                    ->unique(
                        fn($reference) =>
                        $reference->hash()
                    )
                    ->values();

                $discoveredCount =
                    $references->count();

                /*
                |--------------------------------------------------------------------------
                | History-independent AI candidates
                |--------------------------------------------------------------------------
                |
                | This does NOT look at SourceEvent.
                |
                | It represents the potential corpus that would be worth
                | normalization from scratch.
                |
                | Only confidently expired references are removed.
                |
                */

                $aiCandidates =
                    $references
                    ->filter(
                        fn($reference): bool =>
                        $evaluator->isAiCandidate(
                            $reference
                        )
                    )
                    ->values();

                $aiCandidateCount =
                    $aiCandidates->count();

                $pastExcluded =
                    $discoveredCount
                    - $aiCandidateCount;

                /*
                |--------------------------------------------------------------------------
                | Current crawl workload
                |--------------------------------------------------------------------------
                |
                | Load SourceEvent history read-only.
                |
                | If this source has never been crawled, we deliberately display
                | N/A rather than pretending the history-based number is useful.
                |
                */

                $history =
                    $evaluator->sourceHistory(
                        $source
                    );

                $hasHistory =
                    $history->isNotEmpty();

                $crawlWorkload = null;

                if ($hasHistory) {
                    $sourcesWithHistory++;

                    $crawlWorkload =
                        $aiCandidates
                        ->filter(
                            fn($reference): bool =>
                            $evaluator->shouldProcessNow(
                                $reference,
                                $history->get(
                                    $reference->sourceKey()
                                )
                            )
                        )
                        ->count();

                    $knownHistoryWorkload +=
                        $crawlWorkload;
                } else {
                    $sourcesWithoutHistory++;
                }

                /*
                |--------------------------------------------------------------------------
                | Totals
                |--------------------------------------------------------------------------
                */

                $totalDiscovered +=
                    $discoveredCount;

                $totalAiCandidates +=
                    $aiCandidateCount;

                $totalPastExcluded +=
                    $pastExcluded;

                $successfulSources++;

                /*
                |--------------------------------------------------------------------------
                | Result row
                |--------------------------------------------------------------------------
                */

                $rows[] = [
                    'commune' =>
                    $source->commune?->name
                        ?? 'Aggregator',

                    'adapter' =>
                    $source->adapter
                        ?? '-',

                    'discovered' =>
                    $discoveredCount,

                    'ai_candidates' =>
                    $aiCandidateCount,

                    'past_excluded' =>
                    $pastExcluded,

                    'crawl_workload' =>
                    $crawlWorkload
                        ?? 'N/A',

                    'listing_url' =>
                    $source->listing_url,

                    'status' =>
                    'OK',

                    'elapsed' =>
                    number_format(
                        microtime(true)
                            - $sourceStartedAt,
                        2
                    )
                        . 's',
                ];
            } catch (Throwable $exception) {
                $failedSources++;

                $rows[] = [
                    'commune' =>
                    $source->commune?->name
                        ?? 'Aggregator',

                    'adapter' =>
                    $source->adapter
                        ?? '-',

                    'discovered' =>
                    '-',

                    'ai_candidates' =>
                    '-',

                    'past_excluded' =>
                    '-',

                    'crawl_workload' =>
                    '-',

                    'listing_url' =>
                    $source->listing_url,

                    'status' =>
                    'FAILED: '
                        . $exception->getMessage(),

                    'elapsed' =>
                    number_format(
                        microtime(true)
                            - $sourceStartedAt,
                        2
                    )
                        . 's',
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Per-source results
        |--------------------------------------------------------------------------
        */

        $this->newLine();

        $this->table(
            [
                'Commune',
                'Adapter',
                'Discovered',
                'AI candidates',
                'Past excluded',
                'Crawl workload',
                'Listing URL',
                'Status',
                'Elapsed',
            ],
            array_map(
                fn(array $row): array => [
                    $row['commune'],
                    $row['adapter'],
                    $row['discovered'],
                    $row['ai_candidates'],
                    $row['past_excluded'],
                    $row['crawl_workload'],
                    $row['listing_url'],
                    $row['status'],
                    $row['elapsed'],
                ],
                $rows
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */

        $this->newLine();

        $this->table(
            [
                'Summary',
                'Count',
            ],
            [
                [
                    'Sources checked',
                    $sources->count(),
                ],
                [
                    'Successful sources',
                    $successfulSources,
                ],
                [
                    'Failed sources',
                    $failedSources,
                ],
                [
                    'Discovered references',
                    $totalDiscovered,
                ],
                [
                    'AI candidates',
                    $totalAiCandidates,
                ],
                [
                    'Definitely past excluded',
                    $totalPastExcluded,
                ],
                [
                    'Sources with crawl history',
                    $sourcesWithHistory,
                ],
                [
                    'Sources without crawl history',
                    $sourcesWithoutHistory,
                ],
                [
                    'Known-history crawl workload',
                    $sourcesWithHistory > 0
                        ? $knownHistoryWorkload
                        : 'N/A',
                ],
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Explanatory notices
        |--------------------------------------------------------------------------
        */

        if ($sourcesWithoutHistory > 0) {
            $this->newLine();

            $this->warn(
                "{$sourcesWithoutHistory} source(s) have no SourceEvent crawl history. "
                    . 'Their Crawl workload value is shown as N/A.'
            );
        }

        $this->newLine();

        $this->line(
            '<info>AI candidates</info> is history-independent and is the '
                . 'recommended number for estimating a from-scratch normalization workload.'
        );

        $this->line(
            '<comment>Past excluded</comment> is intentionally conservative: '
                . 'ambiguous, recurring or non-year-bearing dates remain AI candidates.'
        );

        $this->line(
            '<info>Crawl workload</info> uses existing SourceEvent history and '
                . 'the production fetch policy, but never modifies that history.'
        );

        $this->newLine();

        $this->line(
            'Elapsed: '
                . number_format(
                    microtime(true) - $startedAt,
                    2
                )
                . 's'
        );

        return $failedSources > 0
            ? self::FAILURE
            : self::SUCCESS;
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

        if (
            $raw === ''
            || mb_strtolower($raw) === 'all'
        ) {
            return null;
        }

        $slugs = collect(
            explode(
                ',',
                $raw
            )
        )
            ->map(
                fn(string $slug): string =>
                trim(
                    $slug
                )
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        $known = Commune::query()
            ->pluck(
                'slug'
            )
            ->all();

        $unknown = array_values(
            array_diff(
                $slugs,
                $known
            )
        );

        if ($unknown !== []) {
            foreach ($unknown as $slug) {
                $this->error(
                    "Unknown commune slug [{$slug}]."
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
            ->whereNotNull(
                'adapter'
            )
            ->pluck(
                'adapter'
            )
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

        $this->error(
            "Unknown event source adapter [{$adapter}]."
        );

        return false;
    }
}
