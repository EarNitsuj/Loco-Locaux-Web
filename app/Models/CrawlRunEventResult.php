<?php

namespace App\Models;

use App\Enums\CrawlEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlRunEventResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CrawlEventStatus::class,
        ];
    }

    public function crawlRunSource(): BelongsTo
    {
        return $this->belongsTo(CrawlRunSource::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(SourceEvent::class);
    }
}
