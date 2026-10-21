<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\CanonicalEvent;
use Illuminate\Database\Eloquent\Builder;

class Event extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'normalized_json' => 'array',
        ];
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function eventSource(): BelongsTo
    {
        return $this->belongsTo(EventSource::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withPivot('is_primary');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(EventTranslation::class);
    }

    public function sourceEvents(): HasMany
    {
        return $this->hasMany(SourceEvent::class);
    }

    public function canonicalEvent(): BelongsTo
    {
        return $this->belongsTo(CanonicalEvent::class);
    }

    public function scopeCanonicalPrimary(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            /*
            * Legacy/unprocessed Events remain visible during migration.
            */
            $query->whereNull('canonical_event_id')
                ->orWhereIn(
                    'events.id',
                    CanonicalEvent::query()
                        ->select('primary_event_id')
                        ->whereNotNull('primary_event_id')
                );
        });
    }
}
