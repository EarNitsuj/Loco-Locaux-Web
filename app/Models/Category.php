<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    protected $fillable = [
        'key',
        'name_en',
        'name_fr',
        'name_it',
        'name_de',
        'name_es',
        'name_pt',
    ];

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class)
            ->withPivot('is_primary');
    }
}
