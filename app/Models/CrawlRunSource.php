<?php

namespace App\Models;

use App\Enums\CrawlRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlRunSource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CrawlRunStatus::class,

            'snapshot_requested' => 'boolean',
            'snapshot_complete' => 'boolean',

            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }

    public function eventSource(): BelongsTo
    {
        return $this->belongsTo(EventSource::class);
    }

    public function eventResults(): HasMany
    {
        return $this->hasMany(
            CrawlRunEventResult::class
        );
    }

    /**
     * Missing-event reconciliation is allowed only after the crawler has
     * positively proved this run observed a complete source snapshot.
     */
    public function canEvaluateMissingEvents(): bool
    {
        return $this->snapshot_requested
            && $this->snapshot_complete === true
            && $this->status
            === CrawlRunStatus::Completed;
    }
}
