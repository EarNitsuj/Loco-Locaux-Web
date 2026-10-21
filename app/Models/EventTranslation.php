<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTranslation extends Model
{
    protected $fillable = [
        'event_id',
        'locale',
        'normalized_json',
        'translation_cost_usd',
        'translation_input_tokens',
        'translation_output_tokens',
        'translation_model',
    ];

    protected function casts(): array
    {
        return [
            'normalized_json' => 'array',
            'translation_cost_usd' => 'decimal:6',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
