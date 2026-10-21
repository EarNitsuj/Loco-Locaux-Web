<?php

namespace Database\Seeders;

use App\Models\Commune;
use Illuminate\Database\Seeder;
use App\Models\EventSource;

class EventSourceSeeder extends Seeder
{
    private const OFFICIAL_COMMUNE_AUTHORITY = 100;
    private const AGGREGATOR_AUTHORITY = 50;

    public function run(): void
    {
        $sources = [
            'aire-la-ville' => [
                'Aire-la-Ville',
                'https://www.aire-la-ville.ch/',
                'configured-html-agenda',
                [
                    'listing_item_selector' => 'section.agenda .swiper-slide',
                    'event_link_selector' => 'a[href*="/events/"]',
                    'listing_title_selector' => '.post-title',
                    'listing_date_selector' => '.slide-title',
                    'listing_image_selector' => 'img[src]',

                    'event_path_contains' => [
                        '/events/',
                    ],

                    'title_selector' => '.intro__title',
                    'description_selector' => '.intro__content',
                    'raw_selector' => 'main .intro',

                    'external_id_selector' => 'body[class*="postid-"]',
                    'external_id_attribute' => 'class',
                    'external_id_pattern' => '/postid-(\d+)/',

                    /*
                    |--------------------------------------------------------------------------
                    | Linked PDF evidence
                    |--------------------------------------------------------------------------
                    |
                    | Aire-la-Ville sometimes keeps important event details/dates inside
                    | PDFs linked from the event page.
                    |
                    */

                    'extract_linked_pdfs' => true,
                    'max_linked_pdfs' => 5,
                ],
            ],
            'anieres' => ['Anières', 'https://anieres.ch/agenda/', 'configured-html-agenda', [
                'listing_item_selector' => 'main article.lsvr_event',
                'event_link_selector' => 'a.post__title-link[href]',
                'listing_title_selector' => '.post__title',
                'listing_date_selector' => '.post__info',
                'event_path_contains' => ['/agenda/'],
                'title_selector' => 'main .main__title',
                'date_selector' => 'main .post__info',
                'description_selector' => 'main .post__content',
                'raw_selector' => 'main article.lsvr_event',
                'external_id_selector' => 'main article[class*="post-"]',
                'external_id_attribute' => 'class',
                'external_id_pattern' => '/post-(\d+)/',
            ]],
            'avully' => ['Avully', 'https://avully.ch/agenda/', 'configured-html-agenda', [
                'listing_item_selector' => 'main a.agenda_item[href]',
                'listing_title_selector' => '.agenda_txt',
                'listing_date_selector' => '.agenda_date',
                'listing_image_selector' => 'img[src]',
                'event_path_contains' => ['/agenda/'],
                'title_selector' => 'article .entry-title',
                'date_selector' => 'article .event-date',
                'description_selector' => 'article .entry-content .padded_area',
                'image_selector' => 'article .col_img_single img[src]',
                'raw_selector' => 'article .entry-content .padded_area',
            ]],
            'avusy' => ['Avusy', 'https://www.avusy.ch/manifestations/', 'configured-static-agenda', [
                'item_selector' => '#content p',
                'mode' => 'avusy',
            ]],
            'bardonnex' => ['Bardonnex', 'https://www.bardonnex.ch/wp-json/wp/v2/mec-events?per_page=20&orderby=date&order=desc', 'wordpress-mec', [
                'api_url' => 'https://www.bardonnex.ch/wp-json/wp/v2/mec-events?per_page=20&orderby=date&order=desc',
            ]],
            'bellevue' => ['Bellevue', 'https://www.mairie-bellevue.ch/_rtr/anlaesseaktuelles.list', 'iweb-agenda'],
            'bernex' => ['Bernex', 'https://www.bernex.ch/agenda', 'configured-html-agenda', [
                'listing_item_selector' => '.aio-block-content.aio-block-event',
                'event_link_selector' => '.aio-block-event-txt h2 a[href]',
                'listing_title_selector' => '.aio-block-event-txt h2',
                'listing_date_selector' => '.aio-block-event-txt time',
                'listing_image_selector' => 'img.img-agenda-index[src]',
                'event_path_contains' => ['/agenda/'],
                'title_selector' => '.page-header-title h1',
                'date_selector' => '.page-header-title',
                'description_selector' => 'main .html-content, main .aio-block-info-panel',
                'image_selector' => '.hero-container img[src]',
                'raw_selector' => 'main',
            ]],
            'carouge' => ['Carouge', 'https://www.carouge.ch/agenda', 'carouge'],
            'cartigny' => ['Cartigny', 'https://cartigny.ch/calendrier/', 'configured-static-agenda', [
                'item_selector' => '.entry-content p',
                'mode' => 'cartigny',
            ]],
            'celigny' => ['Céligny', 'https://www.celigny.ch/evenements/', 'configured-html-agenda', [
                'listing_item_selector' => '.agenda-evenements-cel article.et_pb_post',
                'event_link_selector' => 'h2.entry-title a[href]',
                'listing_title_selector' => 'h2.entry-title',
                'listing_date_selector' => '.post-meta .published',
                'prefer_listing_date' => true,
                'event_path_contains' => ['/evenements/'],
                'title_selector' => '.et_pb_text_0_tb_body .et_pb_text_inner',
                'date_selector' => '.et_pb_post_content_0_tb_body',
                'description_selector' => '.et_pb_post_content_0_tb_body',
                'image_selector' => '.et_pb_post_content_0_tb_body img[src]',
                'ignore_meta_image' => true,
                'raw_selector' => '.et_pb_post_content_0_tb_body',
                'external_id_selector' => 'body[class*="postid-"]',
                'external_id_attribute' => 'class',
                'external_id_pattern' => '/postid-(\d+)/',
            ]],
            'chancy' => ['Chancy', 'https://www.chancy.ch/wp-json/tribe/events/v1/events?per_page=50', 'wordpress-tribe-events', [
                'api_url' => 'https://www.chancy.ch/wp-json/tribe/events/v1/events?per_page=50',
            ]],
            'chene-bougeries' => ['Chêne-Bougeries', 'https://chene-bougeries.ch/wp-json/tribe/events/v1/events?per_page=50', 'wordpress-tribe-events', [
                'api_url' => 'https://chene-bougeries.ch/wp-json/tribe/events/v1/events?per_page=50',
            ]],
            'chene-bourg' => ['Chêne-Bourg', 'https://www.chene-bourg.ch/api/contents?type=events&locale=fr&site=1&status[]=2&orderByProperty=dateFrom&orderByDirection=DESC', 'allinone-api', [
                'contents_api_url' => 'https://www.chene-bourg.ch/api/contents?type=events&locale=fr&site=1&status[]=2&orderByProperty=dateFrom&orderByDirection=DESC',
            ]],
            'choulex' => [
                'Choulex',
                'https://choulex.ch/actualites/',
                'choulex-actualites',
                [
                    'source_language' => 'fr',

                    'allowed_hosts' => [
                        'choulex.ch',
                        'www.choulex.ch',
                    ],
                ],
            ],
            'collex-bossy' => ['Collex-Bossy', 'https://www.collex-bossy.ch/agenda/agenda/', 'configured-html-agenda', [
                'listing_item_selector' => '.agenda-item, .event, article',
                'event_link_selector' => 'a[href]',
                'event_path_contains' => ['/agenda/'],
            ]],
            'collonge-bellerive' => ['Collonge-Bellerive', 'https://www.collonge-bellerive.ch/_rtr/anlaesseaktuelles.list', 'iweb-agenda'],
            'cologny' => ['Cologny', 'https://www.cologny.ch/agenda', 'drupal-openagenda'],
            'confignon' => ['Confignon', 'https://www.confignon.ch/_rtr/anlaessaktuelles', 'iweb-agenda'],
            'corsier' => ['Corsier', 'https://www.corsier.ch/_rtr/anlaesseaktuelles.list', 'iweb-agenda'],
            'dardagny' => ['Dardagny', 'https://dardagny.ch/calendrier/', 'configured-html-agenda', [
                'listing_item_selector' => '.archive-events__event',
                'event_link_selector' => 'a.archive-events__event-wrapper[href]',
                'listing_title_selector' => '.archive-events__event-title',
                'listing_date_selector' => '.archive-events__event-main',
                'event_path_contains' => ['/calendrier/'],
                'title_selector' => '.single-event__title',
                'date_selector' => '.single-event__date',
                'description_selector' => '.single-event-main .gutenberg-block.text .text__wrapper',
                'raw_selector' => '.single-event-main',
                'ignore_images' => true,
                'external_id_selector' => 'body[class*="postid-"]',
                'external_id_attribute' => 'class',
                'external_id_pattern' => '/postid-(\d+)/',
            ]],
            'geneve' => ['Genève', 'https://www.geneve.ch/agenda', 'configured-html-agenda', [
                'listing_item_selector' => 'main article.event.teaser',
                'event_link_selector' => '.boite > a[href]',
                'listing_title_selector' => '.field--name-field-teaser-title',
                'listing_date_selector' => '.date small',
                'listing_image_selector' => 'img[src]',
                'event_path_contains' => ['/agenda/'],
                'next_page_selector' => '.pager__item--next a[href]',
                'title_selector' => 'main h1',
                'date_selector' => '.field--name-field-times',
                'description_selector' => 'main .main-content p, main .region-content p',
                'raw_selector' => 'main .main-content, main .region-content',
            ]],
            'genthod' => ['Genthod', 'https://www.genthod.ch/_rtr/anlaesseaktuelles.list', 'iweb-agenda'],
            'grand-saconnex' => ['Grand-Saconnex', 'https://www.grand-saconnex.ch/agenda', 'drupal-smart-date'],
            'gy' => ['Gy', 'https://www.mairie-gy.ch/_rtr/anlaesseaktuelles', 'iweb-agenda'],
            'hermance' => ['Hermance', 'https://www.hermance.ch/_rtr/anlaesseaktuelles.list', 'iweb-agenda'],
            'jussy' => ['Jussy', 'https://www.jussy.ch/_rtr/anlaesseaktuelles.list', 'iweb-agenda'],
            'laconnex' => ['Laconnex', 'https://www.laconnex.ch/wp-json/tribe/events/v1/events?per_page=50', 'wordpress-tribe-events', [
                'api_url' => 'https://www.laconnex.ch/wp-json/tribe/events/v1/events?per_page=50',
            ]],
            'lancy' => ['Lancy', 'https://www.lancy.ch/agenda', 'drupal-openagenda'],
            'meinier' => ['Meinier', 'https://www.meinier.ch/evenements', 'drupal-openagenda'],
            'meyrin' => ['Meyrin', 'https://www.meyrin.ch/fr/agenda', 'meyrin'],
            'onex' => ['Onex', 'https://www.onex.ch/mes-loisirs/agenda/', 'onex-api', [
                'contents_api_url' => 'https://www.onex.ch/api/contents?type=events&locale=fr&site=1',
                'calendar_api_url' => 'https://www.onex.ch/api/calendar/list?content={id}',
            ]],
            'perly-certoux' => ['Perly-Certoux', 'https://www.perly-certoux.ch/fr/agenda/', 'configured-html-agenda', [
                'listing_item_selector' => 'section.index-list',
                'event_link_selector' => 'article h2 a[href]',
                'listing_title_selector' => 'article h2',
                'listing_date_selector' => 'article time',
                'listing_image_selector' => 'figure img[src]',
                'event_path_contains' => ['/fr/agenda/'],
                'next_page_selector' => '#load-more .load-more[data-url]',
                'next_page_attribute' => 'data-url',
                'title_selector' => '.agenda-detail h1',
                'date_selector' => '.agenda-detail table.table',
                'description_selector' => '.agenda-detail table.table',
                'raw_selector' => '.agenda-detail table.table',
                'ignore_meta_image' => true,
            ]],
            'plan-les-ouates' => ['Plan-les-Ouates', 'https://acg-plan-les-ouates.oa.events', 'configured-html-agenda', [
                'allowed_hosts' => ['acg-plan-les-ouates.oa.events'],
                'listing_item_selector' => '.events .event-list-item',
                'event_link_selector' => 'a.event-item[href]',
                'listing_title_selector' => '.event-title',
                'listing_image_selector' => 'img[src]',
                'event_path_contains' => ['/events/'],
                'title_selector' => 'main h1, .event-details h1, h1',
                'description_selector' => '.event-details .event-text, main article',
                'raw_selector' => '.event-details, main article',
            ]],
            'pregny-chambesy' => ['Pregny-Chambésy', 'https://www.pregny-chambesy.ch/_rtr/anlaesseaktuelles.list', 'iweb-agenda'],
            'presinge' => ['Presinge', 'https://openagenda.com/fr/presinge', 'openagenda-public'],
            'puplinge' => ['Puplinge', 'https://www.puplinge.ch/api/contents?type=events&locale=fr&site=1&status[]=2&orderByProperty=dateFrom&orderByDirection=ASC', 'allinone-api', [
                'contents_api_url' => 'https://www.puplinge.ch/api/contents?type=events&locale=fr&site=1&status[]=2&orderByProperty=dateFrom&orderByDirection=ASC',
            ]],
            'russin' => ['Russin', 'https://www.russin.ch/evenements/', 'russin-agenda'],
            'satigny' => ['Satigny', 'https://www.satigny.ch/agenda/', 'configured-html-agenda', [
                'listing_item_selector' => '.row.block-actus',
                'event_link_selector' => 'a[href*="/agenda/"]',
                'listing_title_selector' => '.actus-title',
                'listing_date_selector' => '.red.date',
                'listing_image_selector' => '.actus-vignette img[src]',
                'event_path_contains' => ['/agenda/'],
                'prefer_listing_title' => true,
                'title_selector' => '.row.actualites .title-news',
                'date_selector' => '.row.actualites .pictoContainer .p-3',
                'description_selector' => '.row.actualites .content p, .row.actualites .content ul',
                'image_selector' => '.row.actualites .detail-slider img[src]',
                'raw_selector' => '.row.actualites .content',
                'ignore_meta_image' => true,
            ]],
            'soral' => ['Soral', 'https://www.soral.ch/wp-json/tribe/events/v1/events?per_page=50', 'wordpress-tribe-events', [
                'api_url' => 'https://www.soral.ch/wp-json/tribe/events/v1/events?per_page=50',
            ]],
            'thonex' => ['Thônex', 'https://www.thonex.ch/wp-json/wp/v2/mec-events?per_page=20&orderby=date&order=desc', 'wordpress-mec', [
                'api_url' => 'https://www.thonex.ch/wp-json/wp/v2/mec-events?per_page=20&orderby=date&order=desc',
            ]],
            'troinex' => ['Troinex', 'https://troinex.ch/evenements/', 'configured-html-agenda', [
                'listing_item_selector' => 'main .col-12.col-md-6.col-lg-4',
                'event_link_selector' => 'h3.card-title a[href]',
                'listing_title_selector' => 'h3.card-title',
                'listing_date_selector' => '.subtitle-agenda',
                'event_path_contains' => ['/evenements/'],
                'next_page_selector' => 'link[rel="next"]',
                'title_selector' => 'main article h1',
                'date_selector' => 'main article .colonne.my-3',
                'description_selector' => 'main article .colonne.my-3',
                'raw_selector' => 'main article',
                'external_id_selector' => 'main article[id^="post-"]',
                'external_id_attribute' => 'id',
                'external_id_pattern' => '/post-(\d+)/',
            ]],
            'vandoeuvres' => ['Vandoeuvres', 'https://www.vandoeuvres.ch/actualites/agenda/', 'configured-html-agenda', [
                'listing_item_selector' => 'a.agenda-link[href]',
                'listing_title_selector' => '.agenda-front-title',
                'listing_date_selector' => '.agenda-front-date',
                'event_path_contains' => ['/evenement/'],
                'external_id_query_parameter' => 'id',
                'external_id_query_base64' => true,
                'external_id_query_pattern' => '/^v2@(\d+)@/',
                'prefer_reference_url_canonical' => true,
                'title_selector' => '.content-row > h1',
                'date_selector' => '.content-row > .date',
                'description_selector' => '.content-row .the-content',
                'image_selector' => '.content-row .page-thumbnail img[src]',
                'image_allowed_hosts' => ['communeapp.ch'],
                'raw_selector' => '.content-row',
            ]],
            'vernier' => ['Vernier', 'https://www.vernier.ch/evenements', 'vernier'],
            'versoix' => ['Versoix', 'https://www.versoix.ch/agenda/', 'configured-html-agenda', [
                'listing_item_selector' => '.row-no-padding',
                'event_link_selector' => '.block-card-txt h2 a[href]',
                'listing_title_selector' => '.block-card-txt h2',
                'listing_date_selector' => '.block-card-txt time',
                'listing_image_selector' => 'img[srcset]',
                'listing_image_attribute' => 'srcset',
                'event_path_contains' => ['/agenda/'],
                'next_page_selector' => '#load-more a[data-url]',
                'next_page_attribute' => 'data-url',
                'title_selector' => '.main-content-txt h1',
                'date_selector' => '.main-content-txt time',
                'description_selector' => '.main-content-txt > p',
                'image_selector' => '.main-content .detail-slider img[srcset]',
                'raw_selector' => '.main-content',
            ]],
            'veyrier' => ['Veyrier', 'https://acg-veyrier.oa.events/', 'configured-html-agenda', [
                'listing_item_selector' => '.events .event-list-item',
                'event_link_selector' => 'a.event-item[href]',
                'listing_title_selector' => '.event-title',
                'listing_image_selector' => 'img[src]',
                'image_allowed_hosts' => [
                    'cdn.openagenda.com',
                    'img.openagenda.com',
                ],
                'event_path_contains' => ['/events/'],
                'title_selector' => 'main h1, .event-details h1, h1',
                'description_selector' => '.event-details .event-text, main article',
                'raw_selector' => '.event-details, main article',
            ]],
        ];

        foreach ($sources as $communeSlug => $source) {
            [$name, $url, $adapter] = $source;

            $overrides = $source[3] ?? [];

            $isVerified = in_array($communeSlug, [
                'aire-la-ville',
                'anieres',
                'avusy',
                'avully',
                'bellevue',
                'carouge',
                'cartigny',
                'celigny',
                'bardonnex',
                'chene-bougeries',
                'chene-bourg',
                'choulex',
                'bernex',
                'chancy',
                'collex-bossy',
                'collonge-bellerive',
                'cologny',
                'confignon',
                'corsier',
                'dardagny',
                'genthod',
                'geneve',
                'grand-saconnex',
                'gy',
                'hermance',
                'jussy',
                'lancy',
                'laconnex',
                'meinier',
                'meyrin',
                'onex',
                'perly-certoux',
                'plan-les-ouates',
                'pregny-chambesy',
                'presinge',
                'puplinge',
                'russin',
                'satigny',
                'soral',
                'thonex',
                'troinex',
                'vandoeuvres',
                'vernier',
                'versoix',
                'veyrier',
            ], true);

            $commune = Commune::where(
                'slug',
                $communeSlug
            )->firstOrFail();

            $commune->eventSources()->updateOrCreate(
                [
                    'name' => $name,
                ],
                [
                    'name' => $name,
                    'listing_url' => $url,
                    'adapter' => $adapter,

                    'configuration' =>
                    $adapter === 'configured-html-agenda'
                        ? array_merge([
                            'source_language' => 'fr',
                            'listing_item_selector' =>
                            'main article, main .event, main .views-row, main .card',
                            'event_link_selector' =>
                            'a[href]',
                            'event_path_contains' => [
                                'agenda',
                                'evenement',
                                'evenements',
                                'event',
                            ],
                        ], $overrides)
                        : (
                            $overrides === []
                            ? null
                            : $overrides
                        ),

                    /*
                    * Official commune agendas are the authoritative source when
                    * the same real-world event is also published by an aggregator.
                    */
                    'authority_rank' =>
                    self::OFFICIAL_COMMUNE_AUTHORITY,

                    'is_active' => $isVerified,
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Canton-wide / aggregator event sources
        |--------------------------------------------------------------------------
        |
        | Geneva Tourism covers events throughout the canton rather than a single
        | commune. Its commune_id therefore remains null. Each fetched event will
        | later be assigned to its actual commune from its venue/address evidence.
        |
        */

        EventSource::updateOrCreate(
            [
                'name' => 'Geneva Tourism',
            ],
            [
                'commune_id' => null,

                'name' => 'Geneva Tourism',

                'listing_url' =>
                'https://www.geneve.com/fr/agenda-culturel',

                'adapter' =>
                'geneve-tourism',

                'configuration' => [
                    'source_language' => 'fr',

                    'allowed_hosts' => [
                        'geneve.com',
                        'www.geneve.com',
                    ],

                    'solr_type' =>
                    1711534501,

                    'solr_query' =>
                    '*',

                    'detail_selector' =>
                    'main section.venue-detail.event-detail',
                ],

                /*
                * Geneva Tourism is a canton-wide aggregator.
                *
                * Its events remain valid and publishable, but when the same event
                * is also available from the relevant commune's official agenda,
                * the official source becomes the canonical primary Event.
                */
                'authority_rank' =>
                self::AGGREGATOR_AUTHORITY,

                'is_active' => true,
            ],
        );
    }
}
