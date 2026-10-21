<?php

namespace App\Jobs;

use App\Crawling\Data\EventReference;
use App\Crawling\Data\PersistedEventResult;
use App\Crawling\Location\CommuneResolver;
use App\Crawling\Services\CanonicalEventReconciler;
use App\Crawling\Services\CrawlerAdapterRegistry;
use App\Crawling\Services\EventPersister;
use App\Crawling\Services\RawEventFingerprint;
use App\Crawling\Services\SourceEventFetchPolicy;
use App\Crawling\Services\SourceEventTracker;
use App\Enums\CrawlEventStatus;
use App\Models\Category;
use App\Models\CrawlRunSource;
use App\Models\SourceEvent;
use App\Services\OpenAIEventNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class FetchEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $reference
     */
    public function __construct(
        public int $crawlRunSourceId,
        public array $reference,
    ) {}

    public function handle(
        CrawlerAdapterRegistry $registry,
        EventPersister $persister,
        OpenAIEventNormalizer $normalizer,
        CommuneResolver $communeResolver,
        RawEventFingerprint $fingerprint,
        SourceEventTracker $sourceEvents,
        SourceEventFetchPolicy $fetchPolicy,
        CanonicalEventReconciler $canonicalEvents,
    ): void {
        $runSource = CrawlRunSource::with([
            'eventSource',
            'crawlRun',
        ])->findOrFail(
            $this->crawlRunSourceId
        );

        $source = $runSource->eventSource;

        $reference = EventReference::fromArray(
            $this->reference
        );

        $force = (bool) (
            $runSource->crawlRun->options['force']
            ?? false
        );

        $withoutEnrichment = (bool) (
            $runSource->crawlRun->options['without_enrichment'] ?? false
        );

        $sourceEvent = null;
        $event = null;

        try {
            /*
             * 1. Observe the persistent source-level identity.
             *
             * SourceEvent survives across crawl runs and tells us:
             *
             * - whether this source event is new;
             * - whether its discovery/reference evidence changed;
             * - when it was last seen/fetched;
             * - which source-specific Event it belongs to.
             */
            $observation = $sourceEvents->observe(
                $source,
                $reference,
            );

            $sourceEvent = $observation->sourceEvent;

            /*
             * 2. Resolve the source-specific Event already associated with
             * this SourceEvent.
             *
             * Event remains source-specific because it owns:
             *
             * - raw_html
             * - event_url
             * - image_url
             * - event_source_id
             * - normalized_json
             *
             * Cross-source deduplication happens through CanonicalEvent,
             * not by making multiple SourceEvents overwrite one Event.
             */
            $existingEvent = $sourceEvent->event;

            if (
                $existingEvent !== null
                && (int) $existingEvent->event_source_id
                !== (int) $source->id
            ) {
                /*
                 * Defensive protection against an invalid SourceEvent → Event
                 * association.
                 */
                $existingEvent = null;
            }

            /*
             * 3. Determine whether normalization is still pending for the
             * evidence version already stored on SourceEvent.
             *
             * This is important after a previous normalization failure.
             */
            $normalizationPending =
                ! $withoutEnrichment
                && (
                    $sourceEvents->needsNormalization(
                        $sourceEvent
                    )
                    || $existingEvent?->normalized_json === null
                );

            /*
             * 4. Decide whether a full detail fetch is necessary.
             *
             * Fetch occurs when, for example:
             *
             * - SourceEvent is new;
             * - discovery/reference evidence changed;
             * - it has never been fetched;
             * - normalization is pending;
             * - periodic deep revalidation is due;
             * - --force was supplied.
             */
            $shouldFetch = $fetchPolicy->shouldFetch(
                observation: $observation,
                force: $force,
                normalizationPending: $normalizationPending,
            );

            /*
             * 5. Recently verified + discovery unchanged.
             *
             * No detail/API/document fetch is needed.
             *
             * last_seen_at has already been updated by observe().
             */
            if (
                ! $shouldFetch
                && $existingEvent !== null
            ) {
                $event = $existingEvent;

                /*
                 * Existing historical Events may pre-date CanonicalEvent
                 * grouping.
                 *
                 * Reconcile an ungrouped normalized Event once, even though
                 * its source does not need another network fetch.
                 */
                if (
                    $event->normalized_json !== null
                    && $event->canonical_event_id === null
                ) {
                    $canonicalEvents->reconcile(
                        $event
                    );
                }

                $this->record(
                    $runSource,
                    $reference,
                    new PersistedEventResult(
                        CrawlEventStatus::Unchanged,
                        $existingEvent,
                    ),
                    $sourceEvent,
                );

                return;
            }

            /*
             * 6. Perform the existing source-specific fetch.
             *
             * Adapters remain responsible for gathering their own:
             *
             * - HTML
             * - API data
             * - image evidence
             * - documents / PDFs
             * - occurrences
             * - structured metadata
             */
            $raw = $registry
                ->forSource($source)
                ->fetch(
                    $source,
                    $reference
                );

            /*
             * 7. Determine the actual event commune.
             *
             * Commune-specific EventSources already have commune_id.
             *
             * Canton-wide aggregators such as Geneva Tourism require
             * event-level commune resolution.
             */
            $communeId = $source->commune_id;

            if ($communeId === null) {
                $resolvedCommune =
                    $communeResolver->resolve(
                        $raw
                    );

                if ($resolvedCommune === null) {
                    throw new RuntimeException(
                        "Unable to determine commune for event: {$raw->canonicalUrl}"
                    );
                }

                $communeId =
                    $resolvedCommune->id;
            }

            /*
             * 8. Calculate the fingerprint of the fetched event evidence.
             *
             * content_hash answers:
             *
             * "Has this source event's meaningful fetched evidence changed?"
             */
            $contentHash = $fingerprint->hash(
                $raw,
                $communeId,
            );

            /*
             * Save the latest fetched evidence version to SourceEvent.
             *
             * normalized_content_hash is NOT changed here.
             *
             * Therefore:
             *
             * content_hash != normalized_content_hash
             *
             * means normalization still needs to succeed.
             */
            $contentChanged =
                $sourceEvents->markFetched(
                    $sourceEvent,
                    $raw,
                    $contentHash,
                );

            /*
             * 9. Persist source-specific Event evidence only when needed.
             *
             * If the content hash is unchanged, avoid rewriting the Event.
             */
            if (
                ! $contentChanged
                && ! $force
                && $existingEvent !== null
            ) {
                $result = new PersistedEventResult(
                    CrawlEventStatus::Unchanged,
                    $existingEvent,
                );
            } else {
                $result = $persister->persist(
                    source: $source,
                    raw: $raw,
                    force: $force,
                    communeId: $communeId,
                    existingEvent: $existingEvent,
                );
            }

            $event = $result->event;

            if ($event === null) {
                throw new RuntimeException(
                    'Event persistence completed without an event model.'
                );
            }

            /*
             * 10. Persist SourceEvent → source-specific Event relationship.
             *
             * Existing Event rows created before SourceEvent persistence are
             * progressively backfilled as they are encountered.
             */
            $sourceEvents->attachEvent(
                $sourceEvent,
                $event->id,
            );

            /*
             * 11. Raw-only crawl stops here.
             *
             * content_hash has been updated.
             *
             * normalized_content_hash deliberately remains untouched so that
             * a future normal crawl knows this evidence version still needs
             * normalization.
             */
            if ($withoutEnrichment) {
                $this->record(
                    $runSource,
                    $reference,
                    $result,
                    $sourceEvent,
                );

                return;
            }

            /*
             * 12. Determine whether this evidence version requires AI
             * normalization.
             */
            $shouldNormalize =
                $sourceEvents->needsNormalization(
                    $sourceEvent,
                    $force,
                )
                || $event->normalized_json === null;

            $normalizedThisRun = false;

            if ($shouldNormalize) {
                /*
                 * 13. Normalize through the existing AI pipeline.
                 */
                $normalized = $normalizer->normalize(
                    rawHtml: $raw->rawHtml,
                    eventUrl: $raw->canonicalUrl,
                    imageUrl: $raw->imageUrl,
                );

                /*
                 * 14. Store the normalized event payload and usage metadata.
                 */
                $event->update([
                    'normalized_json' =>
                    $normalized['normalized'],

                    'normalization_cost_usd' => round(
                        $normalized['usage']['cost_usd'],
                        2
                    ),

                    'normalization_input_tokens' =>
                    $normalized['usage']['input_tokens'],

                    'normalization_output_tokens' =>
                    $normalized['usage']['output_tokens'],

                    'normalization_model' =>
                    $normalized['usage']['model'],
                ]);

                /*
                 * 15. Keep the existing normalized category synchronization.
                 */
                $normalizedCategories =
                    $normalized['normalized']['event']['categories'] ?? [];

                $categoryKeys = collect(
                    $normalizedCategories
                )
                    ->pluck('key')
                    ->filter()
                    ->values();

                $categories = Category::query()
                    ->whereIn(
                        'key',
                        $categoryKeys
                    )
                    ->get()
                    ->keyBy('key');

                $syncData = collect(
                    $normalizedCategories
                )
                    ->mapWithKeys(
                        function (
                            array $category
                        ) use ($categories) {
                            $model = $categories->get(
                                $category['key'] ?? null
                            );

                            if (! $model) {
                                return [];
                            }

                            return [
                                $model->id => [
                                    'is_primary' => (bool) (
                                        $category['primary']
                                        ?? false
                                    ),
                                ],
                            ];
                        }
                    )
                    ->all();

                $event->categories()->sync(
                    $syncData
                );

                /*
                 * The current evidence version has now been successfully
                 * normalized.
                 *
                 * This marker is about normalization only, not translation.
                 */
                $sourceEvents->markNormalized(
                    $sourceEvent
                );

                $normalizedThisRun = true;
            }

            /*
             * 16. Cross-source canonical reconciliation.
             *
             * This only operates after a valid normalized_json exists because
             * the deterministic matcher relies on normalized title,
             * occurrences, location, etc.
             *
             * Each source keeps its own Event and raw evidence.
             *
             * CanonicalEvent only groups multiple source Events that represent
             * the same real-world event.
             */
            $canonicalEvent = null;

            if ($event->normalized_json !== null) {
                /*
                 * Reconcile when:
                 *
                 * - this Event has never been grouped; or
                 * - its normalized evidence changed during this run.
                 *
                 * There is no need to rerun matching for every unchanged,
                 * already-grouped Event on every crawl.
                 */
                if (
                    $event->canonical_event_id === null
                    || $normalizedThisRun
                ) {
                    $canonicalEvent =
                        $canonicalEvents->reconcile(
                            $event
                        );
                } else {
                    $event->loadMissing(
                        'canonicalEvent'
                    );

                    $canonicalEvent =
                        $event->canonicalEvent;
                }
            }

            /*
             * 17. Translation happens only for the canonical/public primary
             * Event.
             *
             * Example:
             *
             * Geneva official Event    authority 100
             * Geneva Tourism Event     authority 50
             *
             * Both may remain normalized for provenance/deduplication, but
             * only the authoritative primary representation needs the public
             * translation pipeline.
             */
            if (
                $normalizedThisRun
                && $canonicalEvent !== null
                && (int) $canonicalEvent->primary_event_id
                === (int) $event->id
            ) {
                TranslateEvent::dispatch(
                    $event->id
                );
            }

            /*
             * 18. Record successful crawl outcome.
             */
            $this->record(
                $runSource,
                $reference,
                $result,
                $sourceEvent,
            );
        } catch (Throwable $exception) {
            $this->record(
                $runSource,
                $reference,
                new PersistedEventResult(
                    CrawlEventStatus::Failed,
                    $event ?? null,
                    errorMessage: $exception->getMessage(),
                ),
                $sourceEvent,
            );

            throw $exception;
        }
    }

    private function record(
        CrawlRunSource $runSource,
        EventReference $reference,
        PersistedEventResult $result,
        ?SourceEvent $sourceEvent = null,
    ): void {
        $runSource->eventResults()->updateOrCreate(
            [
                /*
                 * Keep the existing run-level EventReference identity.
                 */
                'reference_hash' =>
                $reference->hash(),
            ],
            [
                'event_id' =>
                $result->event?->id,

                'source_event_id' =>
                $sourceEvent?->id,

                'status' =>
                $result->status,

                'event_reference_url' =>
                $reference->url,

                'external_id' =>
                $reference->externalId,

                'error_message' =>
                $result->errorMessage,
            ],
        );
    }
}
