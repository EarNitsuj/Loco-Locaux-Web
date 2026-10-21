<?php

use App\Crawling\Adapters\CarougeCrawler;
use App\Crawling\Adapters\ConfiguredHtmlAgendaCrawler;
use App\Crawling\Adapters\ConfiguredStaticAgendaCrawler;
use App\Crawling\Adapters\DrupalOpenAgendaCrawler;
use App\Crawling\Adapters\DrupalSmartDateCrawler;
use App\Crawling\Adapters\IwebAgendaCrawler;
use App\Crawling\Adapters\MeyrinCrawler;
use App\Crawling\Adapters\OnexApiCrawler;
use App\Crawling\Adapters\OpenAgendaPublicCrawler;
use App\Crawling\Adapters\RussinAgendaCrawler;
use App\Crawling\Adapters\VernierCrawler;
use App\Crawling\Adapters\WordPressMecCrawler;
use App\Crawling\Adapters\WordPressTribeEventsCrawler;
use App\Crawling\Adapters\AllinOneApiCrawler;
use App\Crawling\Adapters\ChoulexActualitesCrawler;
use App\Crawling\Adapters\GeneveTourismCrawler;

return [
    'user_agent' => env('LOCO_CRAWLER_USER_AGENT', 'LocoLocauxBot/0.2 (+https://locolocaux.example)'),
    'accept_language' => env('LOCO_CRAWLER_ACCEPT_LANGUAGE', 'fr-CH,fr;q=0.9,en;q=0.5'),
    'timeout_seconds' => (int) env('LOCO_CRAWLER_TIMEOUT_SECONDS', 20),
    'connect_timeout_seconds' => (int) env('LOCO_CRAWLER_CONNECT_TIMEOUT_SECONDS', 8),
    'retries' => (int) env('LOCO_CRAWLER_RETRIES', 2),
    'retry_delay_ms' => (int) env('LOCO_CRAWLER_RETRY_DELAY_MS', 750),
    'request_delay_ms' => (int) env('LOCO_CRAWLER_REQUEST_DELAY_MS', 500),
    'default_event_limit' => (int) env('LOCO_CRAWLER_DEFAULT_EVENT_LIMIT', 25),
    'max_listing_pages' => (int) env('LOCO_CRAWLER_MAX_LISTING_PAGES', 4),
    'max_empty_pages' => (int) env('LOCO_CRAWLER_MAX_EMPTY_PAGES', 1),
    'max_detail_requests' => (int) env('LOCO_CRAWLER_MAX_DETAIL_REQUESTS', 40),
    'max_duration_seconds' => (int) env('LOCO_CRAWLER_MAX_DURATION_SECONDS', 120),
    'max_response_bytes' => (int) env('LOCO_CRAWLER_MAX_RESPONSE_BYTES', 3000000),
    'image_timeout_seconds' => (int) env('LOCO_CRAWLER_IMAGE_TIMEOUT_SECONDS', 8),
    'image_connect_timeout_seconds' => (int) env('LOCO_CRAWLER_IMAGE_CONNECT_TIMEOUT_SECONDS', 4),
    'image_max_response_bytes' => (int) env('LOCO_CRAWLER_IMAGE_MAX_RESPONSE_BYTES', 1048576),
    'image_validation_cache_hours' => (int) env('LOCO_CRAWLER_IMAGE_VALIDATION_CACHE_HOURS', 12),
    'source_lock_seconds' => (int) env('LOCO_CRAWLER_SOURCE_LOCK_SECONDS', 900),

    'max_document_response_bytes' => 15 * 1024 * 1024,

    'schedule' => [
        'enabled' => (bool) env('LOCO_CRAWLER_SCHEDULE_ENABLED', false),
        'time' => env('LOCO_CRAWLER_SCHEDULE_TIME', '03:15'),
        'communes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LOCO_CRAWLER_SCHEDULE_COMMUNES', 'meyrin,vernier')),
        ))),
    ],

    'adapters' => [
        'geneve-tourism' => GeneveTourismCrawler::class,
        'carouge' => CarougeCrawler::class,
        'choulex-actualites' => ChoulexActualitesCrawler::class,
        'configured-html-agenda' => ConfiguredHtmlAgendaCrawler::class,
        'configured-static-agenda' => ConfiguredStaticAgendaCrawler::class,
        'drupal-openagenda' => DrupalOpenAgendaCrawler::class,
        'drupal-smart-date' => DrupalSmartDateCrawler::class,
        'iweb-agenda' => IwebAgendaCrawler::class,
        'meyrin' => MeyrinCrawler::class,
        'allinone-api' => AllInOneApiCrawler::class,
        'onex-api' => OnexApiCrawler::class,
        'openagenda-public' => OpenAgendaPublicCrawler::class,
        'russin-agenda' => RussinAgendaCrawler::class,
        'vernier' => VernierCrawler::class,
        'wordpress-tribe-events' => WordPressTribeEventsCrawler::class,
        'wordpress-mec' => WordPressMecCrawler::class,
    ],

    'source_event_revalidation_hours' => (int) env(
        'CRAWL_SOURCE_EVENT_REVALIDATION_HOURS',
        24
    ),

    /*
    |--------------------------------------------------------------------------
    | Production crawl scheduling
    |--------------------------------------------------------------------------
    */

    'production_cron' => env(
        'CRAWL_PRODUCTION_CRON',
        '0 6,10,14,18,22 * * *'
    ),

    'production_timezone' => env(
        'CRAWL_PRODUCTION_TIMEZONE',
        'Europe/Zurich'
    ),

    /*
    |--------------------------------------------------------------------------
    | Production crawl lock
    |--------------------------------------------------------------------------
    |
    | The Artisan command finishes shortly after dispatching source jobs, so the
    | scheduler's normal withoutOverlapping lock alone is not sufficient.
    |
    | This lock remains owned by the CrawlRun until CrawlRunFinalizer releases it.
    |
    */

    'production_lock_name' =>
    'loco-locaux:production-crawl',

    'production_lock_seconds' => (int) env(
        'CRAWL_PRODUCTION_LOCK_SECONDS',
        21600
    ),
];
