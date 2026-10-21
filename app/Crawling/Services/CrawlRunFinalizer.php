<?php

namespace App\Crawling\Services;

use App\Enums\CrawlEventStatus;
use App\Enums\CrawlRunStatus;
use App\Models\CrawlRun;
use App\Models\CrawlRunSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CrawlRunFinalizer
{
    public function finalizeSource(
        CrawlRunSource $source
    ): void {
        $counts = $source
            ->eventResults()
            ->selectRaw(
                'status, count(*) as aggregate'
            )
            ->groupBy('status')
            ->pluck(
                'aggregate',
                'status'
            );

        $source->forceFill([
            'events_created' => (int) (
                $counts[CrawlEventStatus::Created->value] ?? 0
            ),

            'events_updated' => (int) (
                $counts[CrawlEventStatus::Updated->value] ?? 0
            ),

            'events_unchanged' => (int) (
                $counts[CrawlEventStatus::Unchanged->value] ?? 0
            ),

            'events_skipped' => (int) (
                $counts[CrawlEventStatus::Skipped->value] ?? 0
            ),

            'events_failed' => (int) (
                $counts[CrawlEventStatus::Failed->value] ?? 0
            ),
        ])->save();

        $this->finalizeRun(
            $source->crawlRun()
                ->firstOrFail()
        );
    }

    public function finalizeRun(
        CrawlRun $run
    ): void {
        $sources = $run
            ->sources()
            ->get();

        $completed = $sources
            ->whereIn(
                'status',
                [
                    CrawlRunStatus::Completed,
                    CrawlRunStatus::CompletedWithErrors,
                    CrawlRunStatus::Failed,
                ]
            )
            ->count();

        $failed = $sources
            ->where(
                'status',
                CrawlRunStatus::Failed
            )
            ->count();

        $withErrors = $sources
            ->where(
                'status',
                CrawlRunStatus::CompletedWithErrors
            )
            ->count();

        $run->forceFill([
            'sources_completed' =>
            $completed,

            'events_created' =>
            $sources->sum(
                'events_created'
            ),

            'events_updated' =>
            $sources->sum(
                'events_updated'
            ),

            'events_unchanged' =>
            $sources->sum(
                'events_unchanged'
            ),

            'events_skipped' =>
            $sources->sum(
                'events_skipped'
            ),

            'events_failed' =>
            $sources->sum(
                'events_failed'
            ),
        ]);

        $finished = false;

        if (
            $completed
            >= $run->sources_total
        ) {
            $run->status =
                $failed > 0
                ? CrawlRunStatus::Failed
                : (
                    $withErrors > 0
                    || $run->events_failed > 0
                    ? CrawlRunStatus::CompletedWithErrors
                    : CrawlRunStatus::Completed
                );

            $run->finished_at =
                Carbon::now();

            $finished = true;
        }

        $run->save();

        /*
         * The production lock belongs to the complete CrawlRun, not the
         * Artisan process that dispatched it.
         */
        if ($finished) {
            $this->releaseProductionLock(
                $run
            );
        }
    }

    private function releaseProductionLock(
        CrawlRun $run
    ): void {
        $owner = $run->lock_owner;

        if (
            ! is_string($owner)
            || trim($owner) === ''
        ) {
            return;
        }

        try {
            Cache::restoreLock(
                (string) config(
                    'crawling.production_lock_name',
                    'loco-locaux:production-crawl'
                ),
                $owner,
            )->release();
        } catch (Throwable $exception) {
            /*
             * The CrawlRun itself is already safely finalized.
             * Lock-release failure must not turn a successful crawl into a
             * failed crawl.
             */
            report($exception);
        }

        $run->forceFill([
            'lock_owner' => null,
        ])->save();
    }
}
