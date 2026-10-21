<?php

namespace App\Models;

use App\Enums\CrawlRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CrawlRunStatus::class,
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(CrawlRunSource::class);
    }
}
