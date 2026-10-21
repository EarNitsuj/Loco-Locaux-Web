<?php

namespace App\Models;

use Database\Factories\EventSourceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// #[UseFactory(EventSourceFactory::class)]
class EventSource extends Model
{
    /** @use HasFactory<EventSourceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'is_active' => 'boolean',
            'last_crawled_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function crawlRunSources(): HasMany
    {
        return $this->hasMany(CrawlRunSource::class);
    }

    public function sourceEvents(): HasMany
    {
        return $this->hasMany(SourceEvent::class);
    }
}
