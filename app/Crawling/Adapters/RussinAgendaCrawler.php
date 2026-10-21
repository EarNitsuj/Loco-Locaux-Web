<?php

namespace App\Crawling\Adapters;

use App\Crawling\Contracts\CommuneCrawler;
use App\Crawling\Data\CrawlOptions;
use App\Crawling\Data\EventReference;
use App\Crawling\Data\RawEvent;
use App\Crawling\Exceptions\CrawlerParseException;
use App\Models\EventSource;

class RussinAgendaCrawler extends AbstractHtmlCrawler implements CommuneCrawler
{
    public function discover(EventSource $source, CrawlOptions $options): iterable
    {
        $fetched = $this->fetcher->get($source->listing_url, ['www.russin.ch', 'russin.ch']);
        $crawler = $this->crawler($fetched->body, $fetched->finalUrl);
        $references = [];
        foreach ($crawler->filter('article.main-content a[href*="/informations-"]') as $node) {
            $text = trim($node->textContent);
            if ($this->dates->parseDateText($text) === null) {
                continue;
            }
            $url = $this->urls->canonical($this->urls->absolute($node->getAttribute('href'), $fetched->finalUrl));
            $references[] = new EventReference(url: $url, listingUrl: $fetched->finalUrl, titleHint: $text, dateText: $text);
            if (! $options->all && $options->eventLimit !== null && count($references) >= $options->eventLimit) {
                break;
            }
        }

        return $references;
    }

    public function fetch(EventSource $source, EventReference $reference): RawEvent
    {
        $fetched = $this->fetcher->get($reference->url, ['www.russin.ch', 'russin.ch']);
        $crawler = $this->crawler($fetched->body, $fetched->finalUrl);
        $title = $this->text($crawler, 'article.main-content h1');
        $content = $this->cleanBlockText($crawler, 'article.main-content > p');
        $occurrence = $this->dates->parseDateText(($reference->dateText ?? '').' '.($content ?? ''));
        if ($title === null || $occurrence === null) {
            throw new CrawlerParseException("No dated Russin event found for {$reference->url}");
        }

        return new RawEvent(
            canonicalUrl: $this->urls->canonical($fetched->finalUrl),
            rawHtml: $this->rawFragment($crawler, 'article.main-content'),
            title: $title,
            startsAt: $occurrence->startsAt,
            endsAt: $occurrence->endsAt,
            description: $content,
            sourceLanguage: 'fr',
            occurrences: [$occurrence],
            canonicalDescriptionHtml: $this->cleanBlockHtml($crawler, 'article.main-content > p', $fetched->finalUrl),
        );
    }
}
