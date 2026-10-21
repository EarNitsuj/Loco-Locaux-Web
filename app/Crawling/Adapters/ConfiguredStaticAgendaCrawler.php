<?php

namespace App\Crawling\Adapters;

use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\ParsedOccurrence;
use App\Crawling\Data\RawEvent;
use App\Crawling\Exceptions\CrawlerParseException;
use App\Models\EventSource;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;

class ConfiguredStaticAgendaCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    public function discover(EventSource $source, CrawlOptions $options): iterable
    {
        $configuration = is_array($source->configuration) ? $source->configuration : [];
        $host = parse_url($source->listing_url, PHP_URL_HOST);
        $fetched = $this->fetcher->get($source->listing_url, is_string($host) ? [$host] : []);
        $crawler = $this->crawler($fetched->body, $fetched->finalUrl);
        $selector = (string) ($configuration['item_selector'] ?? 'main p');
        $mode = (string) ($configuration['mode'] ?? 'title-date');
        $references = [];

        foreach ($crawler->filter($selector) as $node) {
            $item = new Crawler($node, $fetched->finalUrl);
            $text = trim(preg_replace('/\s+/u', ' ', $item->text('')) ?: '');
            [$title, $dateText] = $this->fields($item, $text, $mode);
            $occurrence = $this->dates->parseDateText($dateText);

            if ($title === null || $occurrence === null || $this->isPast($occurrence)) {
                continue;
            }

            $identity = substr(hash('sha256', mb_strtolower($title.'|'.$dateText)), 0, 20);
            $references[] = new EventReference(
                url: $this->urls->canonical($fetched->finalUrl).'#event-'.$identity,
                externalId: 'static-'.$identity,
                listingUrl: $fetched->finalUrl,
                titleHint: $title,
                dateText: $dateText,
                metadata: [
                    'description' => $text,
                    'raw_html' => '<article>'.$item->html('').'</article>',
                ],
            );

            if (! $options->all && $options->eventLimit !== null && count($references) >= $options->eventLimit) {
                break;
            }
        }

        return $references;
    }

    public function fetch(EventSource $source, EventReference $reference): RawEvent
    {
        $occurrence = $this->dates->parseDateText($reference->dateText);

        if ($occurrence === null || $reference->titleHint === null) {
            throw new CrawlerParseException("Invalid static event record at {$reference->url}");
        }

        $description = is_string($reference->metadata['description'] ?? null)
            ? $reference->metadata['description']
            : $reference->titleHint;
        $rawHtml = is_string($reference->metadata['raw_html'] ?? null)
            ? $reference->metadata['raw_html']
            : '<article><p>'.e($description).'</p></article>';

        return new RawEvent(
            canonicalUrl: $reference->url,
            rawHtml: $rawHtml,
            externalId: $reference->externalId,
            title: $reference->titleHint,
            startsAt: $occurrence->startsAt,
            endsAt: $occurrence->endsAt,
            description: $description,
            sourceLanguage: 'fr',
            occurrences: [$occurrence],
        );
    }

    /** @return array{string|null, string|null} */
    private function fields(Crawler $item, string $text, string $mode): array
    {
        if ($mode === 'avusy') {
            $title = $this->text($item, 'strong');
            preg_match('/(\d{1,2}(?:\s*-\s*\d{1,2})?[.\/]\d{1,2}[.\/]\d{2,4})/u', $text, $date);

            return [$title === null ? null : rtrim($title, ', '), $date[1] ?? null];
        }

        if ($mode === 'cartigny' && preg_match('/^(\d{1,2}(?:er)?\s+\p{L}+)\s*:\s*(.+)$/u', $text, $match) === 1) {
            return [trim($match[2]), $match[1]];
        }

        if ($mode === 'council' && preg_match('/(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+\d{1,2}\s+\p{L}+\s+\d{4}/iu', $text, $match) === 1) {
            return ['Séance du Conseil municipal – '.$match[0], $match[0]];
        }

        return [null, null];
    }

    private function isPast(ParsedOccurrence $occurrence): bool
    {
        return ($occurrence->endsAt ?? $occurrence->startsAt)->endOfDay()->lessThan(CarbonImmutable::now('Europe/Zurich'));
    }
}
