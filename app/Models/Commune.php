<?php

namespace App\Models;

use Database\Factories\CommuneFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// #[UseFactory(CommuneFactory::class)]
class Commune extends Model
{
    // /** @use HasFactory<CommuneFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    public function eventSources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
