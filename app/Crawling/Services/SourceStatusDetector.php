<?php

namespace App\Crawling\Services;

use App\Crawling\Data\RawEvent;
use App\Crawling\Data\RawStatusEvidence;
use App\Crawling\Data\SourceStatusCandidate;
use App\Crawling\Data\SourceStatusDetection;
use App\Crawling\Data\SourceStatusRejection;
use App\Enums\SourceEventStatus;
use App\Enums\SupportedLocale;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class SourceStatusDetector
{
    /** @var array<string, string|null> */
    private const SCHEMA_STATUSES = [
        'eventcancelled' => 'cancelled',
        'eventpostponed' => 'postponed',
        'eventrescheduled' => 'rescheduled',
        'eventmovedonline' => 'moved-online',
        'eventscheduled' => null,
    ];

    /** @var array<string, int> */
    private const PRIORITY = [
        'structured_metadata' => 10,
        'dedicated_status_element' => 20,
        'title_marker' => 30,
        'local_sentence' => 40,
        'local_paragraph' => 50,
        'ai_fallback' => 60,
    ];

    /**
     * @param  array<string, mixed>  $jsonLd
     * @param  list<RawStatusEvidence>  $evidence
     */
    public function detect(
        array $jsonLd = [],
        ?string $dedicatedLabel = null,
        ?string $title = null,
        ?string $introduction = null,
        ?string $description = null,
        ?string $contentHtml = null,
        string|SupportedLocale|null $sourceLanguage = 'fr',
        array $evidence = [],
        ?int $eventId = null,
        ?string $sourceUrl = null,
    ): SourceStatusDetection {
        $language = $sourceLanguage instanceof SupportedLocale ? $sourceLanguage->value : $sourceLanguage;
        $units = $this->normalizeEvidence($evidence);

        foreach ($this->schemaEvidence($jsonLd) as $unit) {
            $units[] = $unit;
        }

        if ($dedicatedLabel !== null) {
            $units[] = new RawStatusEvidence($dedicatedLabel, 'dedicated_status_element', 'source_adapter');
        }

        if ($title !== null) {
            $units[] = new RawStatusEvidence($title, 'title_marker', 'deterministic_phrase', 'title');
        }

        if ($introduction !== null) {
            array_push($units, ...$this->textEvidence($introduction));
        }

        if ($contentHtml !== null && trim($contentHtml) !== '') {
            array_push($units, ...$this->htmlEvidence($contentHtml));
        } elseif ($description !== null) {
            array_push($units, ...$this->textEvidence($description));
        }

        $units = $this->uniqueEvidence($units);
        usort($units, fn (RawStatusEvidence $left, RawStatusEvidence $right): int => (self::PRIORITY[$left->evidenceType] ?? 100) <=> (self::PRIORITY[$right->evidenceType] ?? 100));

        $rejections = [];
        $selected = null;
        $structuredNormal = false;

        foreach ($units as $unit) {
            if ($unit->evidenceType === 'structured_metadata') {
                $schema = $this->fromSchema($unit->text);

                if ($schema === null) {
                    if ($this->isKnownSchemaStatus($unit->text)) {
                        $structuredNormal = true;
                        break;
                    }

                    continue;
                }

                $selected = new SourceStatusCandidate(
                    normalizedStatus: $schema,
                    originalLabel: $this->schemaLabel($unit->text),
                    note: null,
                    evidenceText: $unit->text,
                    evidenceType: $unit->evidenceType,
                    detectionMethod: $unit->detectionMethod,
                    sourceSelector: $unit->sourceSelector,
                    confidence: 1,
                );
                break;
            }

            if (! in_array($language, ['fr', 'en', 'de'], true)
                && ! in_array($unit->evidenceType, ['dedicated_status_element', 'title_marker'], true)) {
                continue;
            }

            [$candidates, $unitRejections] = $this->classify($unit, $language ?? '');
            array_push($rejections, ...$unitRejections);

            if (count($candidates) > 1) {
                foreach ($candidates as $candidate) {
                    $rejections[] = new SourceStatusRejection(
                        $unit->text,
                        $unit->evidenceType,
                        'contradictory_evidence',
                        $candidate->normalizedStatus,
                        $unit->sourceSelector,
                    );
                }

                continue;
            }

            if ($candidates !== []) {
                $selected = $candidates[0];
                break;
            }
        }

        $successful = $selected !== null || $structuredNormal || in_array($language, ['fr', 'en', 'de'], true);
        $result = new SourceStatusDetection($selected, $units, $rejections, $successful);
        $this->logScan($result, $eventId, $sourceUrl, $language);

        return $result;
    }

    public function detectRawEvent(RawEvent $raw, ?int $eventId = null): SourceStatusDetection
    {
        $jsonLd = $this->eventJsonLd($raw->rawHtml);
        $evidence = array_merge($raw->statusEvidence, $this->dedicatedHtmlEvidence($raw->rawHtml));

        return $this->detect(
            jsonLd: $jsonLd,
            title: $raw->title,
            description: $raw->description,
            contentHtml: $raw->canonicalDescriptionHtml,
            sourceLanguage: $raw->sourceLanguage,
            evidence: $evidence,
            eventId: $eventId,
            sourceUrl: $raw->canonicalUrl,
        );
    }

    /**
     * @return list<RawStatusEvidence>
     */
    public function evidenceForContent(?string $title, ?string $html, ?string $text): array
    {
        $units = [];

        if ($title !== null) {
            $units[] = new RawStatusEvidence($title, 'title_marker', 'deterministic_phrase', 'title');
        }

        if ($html !== null && trim($html) !== '') {
            array_push($units, ...$this->htmlEvidence($html));
        } elseif ($text !== null) {
            array_push($units, ...$this->textEvidence($text));
        }

        return $this->uniqueEvidence($units);
    }

    /**
     * Validate AI evidence against exact bounded source units and the deterministic semantic rules.
     *
     * @param  list<RawStatusEvidence>  $sourceUnits
     */
    public function validateAiCandidate(
        string $status,
        ?string $label,
        ?string $evidenceText,
        ?string $evidenceType,
        float $confidence,
        string|SupportedLocale|null $sourceLanguage,
        array $sourceUnits,
    ): SourceStatusDetection {
        if (! in_array($status, array_map(fn (SourceEventStatus $case): string => $case->value, SourceEventStatus::cases()), true)) {
            return $this->rejectedAi($evidenceText, $evidenceType, 'unsupported_status', $status);
        }

        if ($confidence < (float) config('ai.source_status_confidence_threshold', 0.75)) {
            return $this->rejectedAi($evidenceText, $evidenceType, 'low_confidence', $status);
        }

        $needle = $this->normalizeText($evidenceText);
        $matched = collect($sourceUnits)->first(fn (RawStatusEvidence $unit): bool => $needle !== '' && $this->normalizeText($unit->text) === $needle);

        if (! $matched instanceof RawStatusEvidence) {
            return $this->rejectedAi($evidenceText, $evidenceType, 'evidence_not_found', $status);
        }

        if ($evidenceType !== null && $evidenceType !== $matched->evidenceType) {
            return $this->rejectedAi($evidenceText, $evidenceType, 'evidence_not_found', $status);
        }

        $aiEvidence = new RawStatusEvidence(
            $matched->text,
            'ai_fallback',
            'ai_validated',
            $matched->sourceSelector,
        );
        [$candidates, $rejections] = $this->classify($aiEvidence, $sourceLanguage instanceof SupportedLocale ? $sourceLanguage->value : (string) $sourceLanguage);
        $candidate = collect($candidates)->first(fn (SourceStatusCandidate $candidate): bool => $candidate->normalizedStatus === $status);

        if (! $candidate instanceof SourceStatusCandidate) {
            $reason = $rejections[0]->reason ?? 'unrelated_subject';

            return new SourceStatusDetection(null, [$aiEvidence], [
                new SourceStatusRejection($matched->text, 'ai_fallback', $reason, $status, $matched->sourceSelector),
            ]);
        }

        return new SourceStatusDetection(new SourceStatusCandidate(
            normalizedStatus: $candidate->normalizedStatus,
            originalLabel: trim((string) $label) !== '' ? trim((string) $label) : $candidate->originalLabel,
            note: $matched->text,
            evidenceText: $matched->text,
            evidenceType: 'ai_fallback',
            detectionMethod: 'ai_validated',
            sourceSelector: $matched->sourceSelector,
            confidence: $confidence,
        ), [$aiEvidence]);
    }

    /** @return array{list<SourceStatusCandidate>, list<SourceStatusRejection>} */
    private function classify(RawStatusEvidence $evidence, string $language): array
    {
        $lexicon = $this->lexicon($language);
        $text = $this->normalizeText($evidence->text);
        $candidates = [];
        $rejections = [];

        foreach ($lexicon as $status => $rules) {
            $label = $this->firstMatch($rules['keywords'] ?? [], trim($evidence->text));

            if ($label === null) {
                continue;
            }

            $reason = match (true) {
                $this->matchesAny($rules['negations'] ?? [], $text) => 'negated_status',
                $this->matchesAny($rules['contradictions'] ?? [], $text) => 'contradictory_evidence',
                $this->matchesAny($rules['exclusions'] ?? [], $text) => 'informational_context',
                default => null,
            };

            if ($reason !== null) {
                $rejections[] = new SourceStatusRejection($evidence->text, $evidence->evidenceType, $reason, $status, $evidence->sourceSelector);

                continue;
            }

            $strong = $this->matchesAny($rules['strong_phrases'] ?? [], $text);
            $hasSubject = $this->matchesAny($rules['subjects'] ?? [], $text);
            $structurallyStrong = in_array($evidence->evidenceType, ['dedicated_status_element', 'title_marker'], true);

            if (! $strong && ! $hasSubject && ! $structurallyStrong) {
                $rejections[] = new SourceStatusRejection($evidence->text, $evidence->evidenceType, 'unrelated_subject', $status, $evidence->sourceSelector);

                continue;
            }

            $candidates[] = new SourceStatusCandidate(
                normalizedStatus: $status,
                originalLabel: $label,
                note: mb_substr(trim($evidence->text), 0, 500),
                evidenceText: trim($evidence->text),
                evidenceType: $evidence->evidenceType,
                detectionMethod: $evidence->detectionMethod,
                sourceSelector: $evidence->sourceSelector,
                confidence: $structurallyStrong || $strong ? 1 : 0.9,
            );
        }

        return [$candidates, $rejections];
    }

    /** @return array<string, array<string, list<string>>> */
    private function lexicon(string $language): array
    {
        if (! in_array($language, ['fr', 'en', 'de'], true)) {
            return [];
        }

        /** @var array<string, array<string, list<string>>> $lexicon */
        $lexicon = require app_path("Content/StatusLexicons/{$language}.php");

        return $lexicon;
    }

    /** @param list<string> $patterns */
    private function matchesAny(array $patterns, string $text): bool
    {
        return $this->firstMatch($patterns, $text) !== null;
    }

    /** @param list<string> $patterns */
    private function firstMatch(array $patterns, string $text): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match('/\b(?:'.$pattern.')\b/iu', $text, $match) === 1) {
                return trim($match[0]);
            }
        }

        return null;
    }

    /** @return list<RawStatusEvidence> */
    private function htmlEvidence(string $html): array
    {
        try {
            $crawler = new Crawler($html);
        } catch (\Throwable) {
            return [];
        }

        $units = [];

        foreach ($crawler->filter('h2, h3, h4, h5, h6, p, li, blockquote, [role="alert"], .alert, .notice') as $index => $node) {
            $text = $this->cleanText($node->textContent);

            if ($text === '' || mb_strlen($text) > 1000) {
                continue;
            }

            $tag = strtolower($node->nodeName);
            $type = str_starts_with($tag, 'h') ? 'local_sentence' : 'local_paragraph';
            $units[] = new RawStatusEvidence($text, $type, 'deterministic_phrase', $tag.':nth('.($index + 1).')');
        }

        return $units;
    }

    /** @return list<RawStatusEvidence> */
    private function textEvidence(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}|\R/u', strip_tags($text)) ?: [];
        $units = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = $this->cleanText($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= 1000) {
                $units[] = new RawStatusEvidence($paragraph, 'local_paragraph', 'deterministic_phrase');

                continue;
            }

            foreach (preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [] as $sentence) {
                $sentence = $this->cleanText($sentence);

                if ($sentence !== '' && mb_strlen($sentence) <= 1000) {
                    $units[] = new RawStatusEvidence($sentence, 'local_sentence', 'deterministic_phrase');
                }
            }
        }

        return $units;
    }

    /** @return list<RawStatusEvidence> */
    private function dedicatedHtmlEvidence(string $html): array
    {
        try {
            $crawler = new Crawler($html);
        } catch (\Throwable) {
            return [];
        }

        $selectors = ['[itemprop="eventStatus"]', '[data-event-status]', '.event-status', '.oa-event-status', '[class~="status-badge"]'];
        $units = [];

        foreach ($selectors as $selector) {
            foreach ($crawler->filter($selector) as $node) {
                $text = $selector === '[itemprop="eventStatus"]'
                    ? $this->cleanText($node->attributes?->getNamedItem('content')?->nodeValue
                        ?? $node->attributes?->getNamedItem('href')?->nodeValue
                        ?? $node->textContent)
                    : $this->cleanText($node->textContent);

                if ($text !== '' && mb_strlen($text) <= 500) {
                    $units[] = new RawStatusEvidence(
                        $text,
                        $selector === '[itemprop="eventStatus"]' ? 'structured_metadata' : 'dedicated_status_element',
                        $selector === '[itemprop="eventStatus"]' ? 'schema_org' : 'source_adapter',
                        $selector,
                    );
                }
            }
        }

        return $units;
    }

    /** @param array<string, mixed> $jsonLd
     * @return list<RawStatusEvidence>
     */
    private function schemaEvidence(array $jsonLd): array
    {
        $status = $jsonLd['eventStatus'] ?? null;

        return is_string($status)
            ? [new RawStatusEvidence($status, 'structured_metadata', 'schema_org', 'eventStatus')]
            : [];
    }

    /** @return array<string, mixed> */
    private function eventJsonLd(string $html): array
    {
        try {
            $crawler = new Crawler($html);
        } catch (\Throwable) {
            return [];
        }

        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $decoded = json_decode($script->textContent, true);

            foreach ($this->jsonLdCandidates($decoded) as $candidate) {
                $types = (array) ($candidate['@type'] ?? []);

                if (in_array('Event', $types, true)) {
                    return $candidate;
                }
            }
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private function jsonLdCandidates(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return collect($decoded)->flatMap(fn (mixed $item): array => $this->jsonLdCandidates($item))->all();
        }

        $candidates = [$decoded];

        if (isset($decoded['@graph'])) {
            array_push($candidates, ...$this->jsonLdCandidates($decoded['@graph']));
        }

        return $candidates;
    }

    private function fromSchema(string $value): ?string
    {
        $key = strtolower((string) preg_replace('/^.*[\/#]/', '', trim($value)));

        return array_key_exists($key, self::SCHEMA_STATUSES) ? self::SCHEMA_STATUSES[$key] : null;
    }

    private function isKnownSchemaStatus(string $value): bool
    {
        $key = strtolower((string) preg_replace('/^.*[\/#]/', '', trim($value)));

        return array_key_exists($key, self::SCHEMA_STATUSES);
    }

    private function schemaLabel(string $value): string
    {
        return (string) preg_replace('/^.*[\/#]/', '', trim($value));
    }

    /** @param list<RawStatusEvidence> $evidence
     * @return list<RawStatusEvidence>
     */
    private function normalizeEvidence(array $evidence): array
    {
        return array_values(array_filter($evidence, fn (mixed $item): bool => $item instanceof RawStatusEvidence && $this->normalizeText($item->text) !== ''));
    }

    /** @param list<RawStatusEvidence> $evidence
     * @return list<RawStatusEvidence>
     */
    private function uniqueEvidence(array $evidence): array
    {
        $seen = [];

        return array_values(array_filter($evidence, function (RawStatusEvidence $item) use (&$seen): bool {
            $key = $item->evidenceType.'|'.$this->normalizeText($item->text);

            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }

    private function normalizeText(?string $text): string
    {
        return mb_strtolower($this->cleanText($text));
    }

    private function cleanText(?string $text): string
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

        return trim($text);
    }

    private function rejectedAi(?string $evidenceText, ?string $evidenceType, string $reason, string $status): SourceStatusDetection
    {
        return new SourceStatusDetection(null, [], [new SourceStatusRejection(
            trim((string) $evidenceText),
            $evidenceType ?? 'ai_fallback',
            $reason,
            $status,
        )]);
    }

    private function logScan(SourceStatusDetection $result, ?int $eventId, ?string $sourceUrl, ?string $language): void
    {
        Log::info('Exceptional source event status scan completed.', [
            'operation' => 'source_status_detection',
            'event_id' => $eventId,
            'source_url' => $sourceUrl,
            'source_language' => $language,
            'candidate_count' => count($result->evidence),
            'selected_evidence_type' => $result->candidate?->evidenceType,
            'selected_evidence_text_length' => $result->candidate === null ? null : mb_strlen($result->candidate->evidenceText),
            'proposed_status' => $result->candidate?->normalizedStatus ?? ($result->rejections[0]->proposedStatus ?? null),
            'accepted_status' => $result->status,
            'detection_method' => $result->method,
            'rejection_reason' => $result->candidate === null ? ($result->rejections[0]->reason ?? null) : null,
            'status_was_cleared' => false,
        ]);
    }
}
