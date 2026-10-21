<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command(
    'inspire',
    function () {
        $this->comment(
            Inspiring::quote()
        );
    }
)->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Loco Locaux production crawl
|--------------------------------------------------------------------------
|
| Five times per day.
|
| The command dispatches source jobs asynchronously. The cross-process crawl
| lock is retained by CrawlRun and released by CrawlRunFinalizer.
|
*/

Schedule::command(
    'events:crawl --all --scheduled'
)
    ->cron(
        (string) config(
            'crawling.production_cron',
            '0 6,10,14,18,22 * * *'
        )
    )
    ->timezone(
        (string) config(
            'crawling.production_timezone',
            'Europe/Zurich'
        )
    )
    ->withoutOverlapping(10);
