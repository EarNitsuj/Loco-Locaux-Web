<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceEvent extends Model
{
    protected $fillable = [
        'event_source_id',
        'event_id',
        'external_id',
        'source_key',
        'reference_hash',
        'canonical_url',
        'content_hash',
        'normalized_content_hash',
        'etag',
        'last_modified',
        'status',
        'first_seen_at',
        'last_seen_at',
        'last_fetched_at',
        'last_changed_at',
        'missing_since_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'missing_since_at' => 'datetime',
        ];
    }

    public function eventSource(): BelongsTo
    {
        return $this->belongsTo(EventSource::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
