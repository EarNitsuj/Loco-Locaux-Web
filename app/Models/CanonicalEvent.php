<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanonicalEvent extends Model
{
    protected $guarded = ['id'];

    public function primaryEvent(): BelongsTo
    {
        return $this->belongsTo(
            Event::class,
            'primary_event_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
