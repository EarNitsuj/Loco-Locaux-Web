<?php

use App\Crawling\Data\CrawlOptions;
use App\Crawling\Services\CrawlerAdapterRegistry;
use App\Crawling\Services\StartCrawl;
use App\Enums\CrawlRunStatus;
use App\Jobs\CrawlCommune;
use App\Models\Category;
use App\Models\Commune;
use App\Models\CrawlRun;
use App\Models\CrawlRunSource;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Carbon\CarbonImmutable;
use Livewire\Component;

new class extends Component {
    public array $locales = ['fr', 'en', 'de', 'it', 'pt', 'es'];
    public $events;

    public array $testCommunes = [];
    public string $selectedCommune = '';
    public ?int $testRunId = null;
    public bool $testProcessing = false;
    public string $testMessage = '';
    public array $testResult = [];

    public function mount(): void
    {
        $locale = request()->route('locale') ?? session('locale');

        if (is_string($locale) && in_array($locale, $this->locales, true)) {
            app()->setLocale($locale);
        }

        $this->refreshEventList();
        $this->refreshCommuneOptions();
        $this->restoreProcessingStatus();
    }

    /**
     * Restore the previous run from the session, or recover an existing limited
     * crawl from persistent database records (including runs started before this fix).
     * This means reloading the page does not erase the processing indicator.
     */
    private function restoreProcessingStatus(): void
    {
        $savedRunId = session('loco_test_run_id');

        if (is_numeric($savedRunId)) {
            $saved = CrawlRun::find((int) $savedRunId);
            if ($saved !== null) {
                $this->testRunId = $saved->id;
            } else {
                session()->forget('loco_test_run_id');
            }
        }

        if ($this->testRunId === null) {
            // Recover an active crawl, but NEVER resurrect an already completed
            // success message on the next browser refresh. A run saved in the
            // session is still restored while its translations are pending.
            $candidate = CrawlRun::query()
                ->with('sources.eventSource.commune')
                ->whereNull('finished_at')
                ->where('started_at', '>=', now()->subDay())
                ->orderByDesc('id')
                ->limit(60)
                ->get()
                ->first(function (CrawlRun $run): bool {
                    $options = $run->options ?? [];

                    if (($options['scheduled'] ?? false) || ($options['all'] ?? false) || ($options['without_enrichment'] ?? false) || (int) ($options['event_limit'] ?? 0) !== 1 || $run->sources->count() !== 1 || $run->sources->first()?->eventSource?->commune_id === null) {
                        return false;
                    }

                    return ($options['test_interface'] ?? false) || $run->finished_at === null;
                });

            if ($candidate !== null) {
                $this->testRunId = $candidate->id;
            }
        }

        if ($this->testRunId === null) {
            return;
        }

        $run = CrawlRun::query()->with('sources.eventSource.commune')->find($this->testRunId);

        $slug = $run?->sources->first()?->eventSource?->commune?->slug;
        if (is_string($slug)) {
            $this->selectedCommune = $slug;
        }

        session()->put('loco_test_run_id', $this->testRunId);
        $this->refreshProcessingStatus();
    }

    /** Hide completed results and prevent restoring them after a refresh. */
    private function clearCompletedTest(): void
    {
        $this->testRunId = null;
        $this->testProcessing = false;
        $this->testMessage = '';
        $this->testResult = [];

        session()->forget('loco_test_run_id');
    }

    public function switchLocale(string $locale): void
    {
        abort_unless(in_array($locale, $this->locales, true), 404);

        session()->put('locale', $locale);
        app()->setLocale($locale);
    }

    /**
     * The landing page presents only normalized events as public cards.
     * Eager-load data used by the card so no extra queries run per item.
     */
    private function refreshEventList(): void
    {
        $locale = app()->getLocale();

        $this->events = Event::query()
            ->with(['commune', 'categories', 'translations' => fn($query) => $query->where('locale', $locale)])
            ->whereNotNull('normalized_json')
            ->orderByDesc('id')
            ->get();
    }

    /** Choose the translated event data for the active URL locale when present. */
    public function localizedJson(Event $event): array
    {
        $sourceJson = $event->normalized_json ?? [];
        $sourceLocale = $sourceJson['event']['language'] ?? null;
        $locale = app()->getLocale();

        if ($sourceLocale === $locale) {
            return $sourceJson;
        }

        $translation = $event->translations->firstWhere('locale', $locale);

        return $translation?->normalized_json ?? $sourceJson;
    }

    public function categoryName(Category $category): string
    {
        $column = 'name_' . app()->getLocale();

        return $category->{$column} ?: $category->name_en;
    }

    /** Prefer the next occurrence, otherwise the latest past occurrence. */
    public function displayOccurrence(array $occurrences): ?array
    {
        $occurrences = collect($occurrences)->filter(fn($occurrence) => is_array($occurrence) && !empty($occurrence['starts_at']))->sortBy('starts_at')->values();

        if ($occurrences->isEmpty()) {
            return null;
        }

        $upcoming = $occurrences->first(fn(array $occurrence) => CarbonImmutable::parse($occurrence['starts_at'])->gte(now()));

        return $upcoming ?? $occurrences->last();
    }

    public function dateLabel(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        return CarbonImmutable::parse($date)
            ->locale(app()->getLocale())
            ->translatedFormat('j M Y');
    }

    public function timeLabel(?string $date): ?string
    {
        return $date ? CarbonImmutable::parse($date)->format('H:i') : null;
    }

    private function hasActiveCrawl(int $communeId): bool
    {
        return CrawlRunSource::query()->whereHas('eventSource', fn($query) => $query->where('commune_id', $communeId))->whereHas('crawlRun', fn($query) => $query->whereIn('status', [CrawlRunStatus::Pending->value, CrawlRunStatus::Running->value]))->exists();
    }

    private function refreshCommuneOptions(): void
    {
        $registry = app(CrawlerAdapterRegistry::class);
        $sources = EventSource::query()->with('commune')->where('is_active', true)->whereNotNull('commune_id')->whereNotNull('adapter')->orderBy('id')->get()->filter(fn(EventSource $source): bool => $source->commune !== null && is_string($source->adapter) && $registry->hasAdapter($source->adapter))->unique('commune_id');

        $counts = Event::query()->selectRaw('commune_id, COUNT(*) AS total')->groupBy('commune_id')->pluck('total', 'commune_id');

        $this->testCommunes = $sources
            ->map(function (EventSource $source) use ($counts): array {
                return [
                    'slug' => $source->commune->slug,
                    'name' => $source->commune->name,
                    'count' => (int) ($counts[$source->commune_id] ?? 0),
                    'busy' => $this->hasActiveCrawl((int) $source->commune_id),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function processTest(): void
    {
        $this->testMessage = '';
        $this->testResult = [];

        if ($this->testProcessing) {
            return;
        }

        $slug = trim($this->selectedCommune);
        if ($slug === '') {
            $this->addError('selectedCommune', 'Veuillez sélectionner une commune.');
            return;
        }

        $commune = Commune::query()->where('slug', $slug)->first();
        if ($commune === null) {
            $this->addError('selectedCommune', 'Commune inconnue.');
            return;
        }

        // Public interface: cap how frequently one visitor and the whole site can start paid AI work.
        $ipKey = 'loco-test:ip:' . hash('sha256', (string) request()->ip());
        $globalKey = 'loco-test:global';
        if (RateLimiter::tooManyAttempts($ipKey, 3) || RateLimiter::tooManyAttempts($globalKey, 20)) {
            $this->testMessage = 'Limite temporaire atteinte. Réessayez plus tard.';
            return;
        }

        try {
            $result = Cache::lock('loco-test:commune:' . $commune->id, 15)->block(2, function () use ($commune, $slug, $ipKey, $globalKey): array {
                // This is the authoritative server-side check, not the disabled button.
                if (Event::query()->where('commune_id', $commune->id)->count() >= 2) {
                    return ['error' => 'Cette commune possède déjà au moins 2 événements.'];
                }

                if ($this->hasActiveCrawl((int) $commune->id)) {
                    return ['error' => 'Un traitement est déjà en cours pour cette commune.'];
                }

                $registry = app(CrawlerAdapterRegistry::class);
                $source = EventSource::query()->with('commune')->where('commune_id', $commune->id)->where('is_active', true)->whereNotNull('adapter')->orderBy('id')->get()->first(fn(EventSource $candidate): bool => is_string($candidate->adapter) && $registry->hasAdapter($candidate->adapter));

                if ($source === null) {
                    return ['error' => 'Aucune source active prise en charge pour cette commune.'];
                }

                $options = CrawlOptions::fromConfig(communeSlugs: [$slug], eventLimit: 1, all: false, force: true, withoutEnrichment: false, scheduled: false);

                $run = app(StartCrawl::class)->startForSource($options, $source);
                $run->forceFill([
                    'options' => array_merge($run->options ?? [], [
                        'test_interface' => true,
                    ]),
                ])->save();
                $runSource = $run->sources()->firstOrFail();

                try {
                    // Use an actual worker rather than performing OpenAI calls inside a web request.
                    CrawlCommune::dispatch($runSource->id)->onConnection('database')->onQueue('default');
                } catch (\Throwable $exception) {
                    $runSource
                        ->forceFill([
                            'status' => CrawlRunStatus::Failed,
                            'finished_at' => now(),
                            'failure_reason' => 'Queue dispatch failed',
                        ])
                        ->save();
                    $run->forceFill([
                        'status' => CrawlRunStatus::Failed,
                        'finished_at' => now(),
                    ])->save();
                    report($exception);
                    return ['error' => 'Impossible de lancer la tâche de traitement.'];
                }

                RateLimiter::hit($ipKey, 3600);
                RateLimiter::hit($globalKey, 86400);
                return ['run_id' => $run->id];
            });

            if (isset($result['error'])) {
                $this->testMessage = $result['error'];
            } else {
                $this->testRunId = (int) $result['run_id'];
                session()->put('loco_test_run_id', $this->testRunId);
                $this->testProcessing = true;
                $this->testMessage = 'Traitement lancé. Recherche et normalisation en cours…';
            }
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $exception) {
            $this->testMessage = 'Une autre demande est en cours. Réessayez dans un instant.';
        } catch (\Throwable $exception) {
            report($exception);
            $this->testMessage = 'Une erreur est survenue lors du lancement.';
        }

        $this->refreshCommuneOptions();
    }

    public function refreshProcessingStatus(): void
    {
        if ($this->testRunId === null) {
            return;
        }

        $run = CrawlRun::query()->with('sources.eventResults.event.translations')->find($this->testRunId);
        if ($run === null) {
            $this->testProcessing = false;
            $this->testMessage = 'Le traitement est introuvable.';
            return;
        }

        $source = $run->sources->first();
        $record = $source?->eventResults->first();
        $event = $record?->event;
        $crawlFinished = $run->finished_at !== null;
        $normalizationDone = $event !== null && is_array($event->normalized_json);
        $primary = $event !== null && ($event->canonical_event_id === null || (int) \App\Models\CanonicalEvent::query()->whereKey($event->canonical_event_id)->value('primary_event_id') === (int) $event->id);

        $sourceLocale = $normalizationDone ? $event->normalized_json['event']['language'] ?? 'fr' : null;
        $targetLocales = array_values(array_diff(['fr', 'en', 'de', 'it', 'es', 'pt'], [$sourceLocale]));
        $freshTranslations = $event?->translations->filter(fn($translation) => in_array($translation->locale, $targetLocales, true) && $translation->updated_at !== null && $translation->updated_at->gte($run->started_at))->pluck('locale')->unique()->values()->all() ?? [];

        $this->testResult = [
            'run_id' => $run->id,
            'event_id' => $event?->id,
            'title' => $normalizationDone ? $event->normalized_json['event']['identity']['title'] ?? 'Événement sans titre' : null,
            'status' => $record?->status?->value ?? ($source?->status?->value ?? $run->status->value),
            'normalized' => $normalizationDone,
            'translations' => count($freshTranslations),
            'translations_expected' => count($targetLocales),
            'translation_applicable' => $event !== null && $normalizationDone && $primary,
            'error' => $record?->error_message ?? $source?->failure_reason,
        ];

        if (!$crawlFinished) {
            // Important: mount() must also enable polling after a full refresh.
            $this->testProcessing = true;
            $this->testMessage = 'Crawl et normalisation OpenAI en cours…';
        } elseif ($event === null || !$normalizationDone || $run->status !== CrawlRunStatus::Completed) {
            // Keep unsuccessful results visible so the tester can inspect them.
            $this->testProcessing = false;
            $this->testMessage = 'Traitement terminé avec une erreur ou aucun événement traité.';
        } elseif (!$primary || count($freshTranslations) >= count($targetLocales)) {
            // Successful completion, or an already-normalized secondary source
            // which doesn't require translations: the event card is the result.
            $this->clearCompletedTest();
        } elseif ($run->started_at && $run->started_at->lt(now()->subMinutes(30))) {
            $this->testProcessing = false;
            $this->testMessage = 'Traductions incomplètes après 30 minutes. Vérifiez la queue et les tâches en échec.';
        } else {
            $this->testProcessing = true;
            $this->testMessage = 'Crawl terminé. Traductions OpenAI en cours ou en attente…';
        }

        $this->refreshEventList();
        $this->refreshCommuneOptions();
    }
};
?>

<div class="min-h-screen font-['Archivo',system-ui,sans-serif] text-[15px] leading-[1.55] text-[#201e1d]">
    <section class="min-h-screen bg-zinc-100 px-4 py-8 sm:px-6 sm:py-12">
        <div class="mx-auto max-w-[1180px] space-y-6">
            <div class="border border-zinc-200 bg-white p-6">
                <div class="mb-4">
                    <h2 class="text-lg font-bold">Test de traitement des événements</h2>
                    <p class="mt-1 text-sm text-zinc-600">Choisissez une commune pour traiter un événement avec OpenAI et ses traductions. Maximum : 2 événements par commune.</p>
                </div>

                <form class="flex flex-col gap-3 sm:flex-row sm:items-end"
                    wire:submit="processTest">
                    <div class="flex-1">
                        <label class="mb-1 block text-sm font-semibold"
                            for="test-commune">Commune</label>
                        <select @disabled($testProcessing)
                            class="w-full border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-[#ec3013] focus:outline-none"
                            id="test-commune"
                            wire:model.live="selectedCommune">
                            <option value="">Sélectionner une commune</option>
                            @foreach ($testCommunes as $commune)
                                <option @disabled($commune['count'] >= 2 || $commune['busy'])
                                    value="{{ $commune['slug'] }}">
                                    {{ $commune['name'] }} ({{ $commune['count'] }}/2){{ $commune['busy'] ? ' — en cours' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('selectedCommune')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    @php
                        $selectedOption = collect($testCommunes)->firstWhere('slug', $selectedCommune);
                        $testDisabled = $testProcessing || !$selectedOption || $selectedOption['count'] >= 2 || $selectedOption['busy'];
                    @endphp
                    <button @disabled($testDisabled)
                        class="inline-flex min-h-10 items-center justify-center gap-2 bg-[#201e1d] px-6 py-2 text-sm font-semibold text-white hover:bg-[#ec3013] disabled:cursor-not-allowed disabled:bg-zinc-300 disabled:text-zinc-600"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="processTest">
                        <svg class="size-4 animate-spin"
                            fill="none"
                            viewBox="0 0 24 24"
                            wire:loading
                            wire:target="processTest">
                            <circle class="opacity-30"
                                cx="12"
                                cy="12"
                                r="9"
                                stroke-width="3"
                                stroke="currentColor" />
                            <path d="M21 12a9 9 0 0 0-9-9"
                                stroke-linecap="round"
                                stroke-width="3"
                                stroke="currentColor" />
                        </svg>
                        <span wire:loading.remove
                            wire:target="processTest">Importer un événement</span>
                        <span wire:loading
                            wire:target="processTest">Lancement…</span>
                    </button>
                </form>

                @if ($testMessage !== '')
                    <div class="mt-4 border-l-2 border-[#ec3013] bg-zinc-50 px-4 py-3 text-sm"
                        role="status">
                        <div class="flex items-center gap-2">
                            @if ($testProcessing)
                                <svg class="size-4 animate-spin"
                                    fill="none"
                                    viewBox="0 0 24 24">
                                    <circle class="opacity-30"
                                        cx="12"
                                        cy="12"
                                        r="9"
                                        stroke-width="3"
                                        stroke="currentColor" />
                                    <path d="M21 12a9 9 0 0 0-9-9"
                                        stroke-linecap="round"
                                        stroke-width="3"
                                        stroke="currentColor" />
                                </svg>
                            @endif
                            <span>{{ $testMessage }}</span>
                        </div>
                        @if ($testProcessing)
                            <div wire:poll.3s="refreshProcessingStatus"></div>
                        @endif
                        @if ($testResult !== [])
                            <div class="mt-3 space-y-1 text-xs text-zinc-600">
                                <p>Run #{{ $testResult['run_id'] }} · {{ $testResult['status'] }}</p>
                                @if ($testResult['title'])
                                    <p class="font-semibold text-zinc-900">{{ $testResult['title'] }}</p>
                                @endif
                                <p>Normalisation : {{ $testResult['normalized'] ? 'OK' : 'En attente / échec' }}</p>
                                <p>Traductions :
                                    {{ $testResult['translation_applicable'] ? $testResult['translations'] . '/' . $testResult['translations_expected'] : ($testResult['normalized'] ? 'Non applicable (source secondaire)' : 'En attente') }}</p>
                                @if ($testResult['error'])
                                    <p class="text-red-700">{{ $testResult['error'] }}</p>
                                @endif
                                @if ($testResult['event_id'])
                                    <a class="font-semibold text-sky-600 underline"
                                        href="{{ route('events.show', ['locale' => request()->route('locale') ?? 'fr', 'event' => $testResult['event_id']]) }}">Voir l’événement</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Public event cards — same visual design and navigation as events.blade.php. --}}
            @if ($events->isNotEmpty())
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($events as $event)
                        @php
                            $json = $this->localizedJson($event);
                            $eventData = $json['event'] ?? [];
                            $identity = $eventData['identity'] ?? [];
                            $hero = $eventData['media']['hero'] ?? null;
                            $location = $eventData['location'] ?? [];
                            $pricing = $eventData['pricing'] ?? [];
                            $occurrence = $this->displayOccurrence($json['occurrences'] ?? []);
                            $isFree = ($pricing['is_free'] ?? false) === true;
                            $hasPrice = !empty($pricing['summary']);
                            $primaryCategory = $event->categories->first(fn($category) => (bool) $category->pivot->is_primary);
                            $primaryCategoryName = $primaryCategory ? $this->categoryName($primaryCategory) : null;
                        @endphp

                        <article class="group relative flex min-w-0 flex-col overflow-hidden rounded-md bg-white shadow"
                            wire:key="event-{{ $event->id }}">

                            <a class="flex h-full flex-col"
                                href="{{ route('events.show', [
                                    'locale' => app()->getLocale(),
                                    'event' => $event,
                                ]) }}"
                                wire:navigate>

                                {{-- Image --}}
                                <div class="aspect-16/10 relative overflow-hidden bg-[#e9e8e8]">

                                    @if (!empty($hero['url']))
                                        <img alt="{{ $hero['alt'] ?? ($identity['title'] ?? '') }}"
                                            class="h-full w-full object-cover grayscale-[15%] transition duration-300 group-hover:scale-[1.02] group-hover:grayscale-0"
                                            loading="lazy"
                                            src="{{ $hero['url'] }}">
                                    @else
                                        <div class="flex h-full items-center justify-center">

                                            <svg class="size-9 text-[#201e1d]/25"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path
                                                    d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M6.75 6.75h.008v.008H6.75V6.75Zm-2.25 13.5h15a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 19.5 3.75h-15A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25Z"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="1.4" />
                                            </svg>

                                        </div>
                                    @endif

                                    {{-- Commune + Category --}}
                                    <div class="absolute bottom-0 left-0 flex">

                                        @if ($event->commune)
                                            <span class="inline-flex bg-[#f3f2f2] px-3 py-2 text-[10px] font-bold uppercase tracking-[0.12em] text-[#201e1d]">
                                                {{ $event->commune->name }}
                                            </span>
                                        @endif

                                        @if ($primaryCategoryName)
                                            <span class="inline-flex bg-[#201e1d] px-3 py-2 text-[10px] font-bold uppercase tracking-[0.12em] text-white">
                                                {{ $primaryCategoryName }}
                                            </span>
                                        @endif

                                    </div>

                                    {{-- Free --}}
                                    @if ($isFree)
                                        <span class="absolute right-0 top-0 bg-[#ec3013] px-3 py-2 text-[10px] font-black uppercase tracking-[0.12em] text-white">
                                            {{ __('Free') }}
                                        </span>
                                    @endif

                                </div>

                                {{-- Card body --}}
                                <div class="flex flex-1 flex-col p-5 sm:p-6">

                                    {{-- Date --}}
                                    @if ($occurrence)
                                        <div class="mb-4 flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.1em] text-[#201e1d]/55">

                                            <svg class="size-3.5 shrink-0 text-[#ec3013]"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path d="M6.75 3v2.25M17.25 3v2.25M3.75 9.75h16.5"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="1.8" />

                                                <path d="M5.25 5.25h13.5a1.5 1.5 0 0 1 1.5 1.5v12a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-12a1.5 1.5 0 0 1 1.5-1.5Z"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="1.8" />
                                            </svg>

                                            <span>
                                                {{ $this->dateLabel($occurrence['starts_at'] ?? null) }}

                                                @if (!empty($occurrence['starts_at']))
                                                    · {{ $this->timeLabel($occurrence['starts_at']) }}
                                                @endif
                                            </span>

                                        </div>
                                    @endif

                                    {{-- Title --}}
                                    <h3 class="text-xl font-black leading-[1.1] tracking-[-0.025em] transition-colors group-hover:text-[#ec3013] sm:text-[1.4rem]">
                                        {{ $identity['title'] ?? __('Event') }}
                                    </h3>

                                    {{-- Summary --}}
                                    @if (!empty($eventData['summary']))
                                        <p class="mt-3 line-clamp-3 text-[13px] leading-5 text-[#201e1d]/65">
                                            {{ $eventData['summary'] }}
                                        </p>
                                    @endif

                                    <div class="flex-1"></div>

                                    {{-- Location --}}
                                    @if (!empty($location['name']) || !empty($location['address']))
                                        <div class="mt-6 flex items-start gap-2 border-t border-[#201e1d]/25 pt-3 text-xs text-[#201e1d]/65">

                                            <svg class="mt-0.5 size-3.5 shrink-0"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path d="M12 21s6-5.686 6-12A6 6 0 1 0 6 9c0 6.314 6 12 6 12Z"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="1.7" />

                                                <circle cx="12"
                                                    cy="9"
                                                    r="2"
                                                    stroke-width="1.7" />
                                            </svg>

                                            <span class="line-clamp-1 font-medium">
                                                {{ $location['name'] ?: $location['address'] }}
                                            </span>

                                        </div>
                                    @endif

                                    {{-- Footer --}}
                                    <div class="mt-3 flex min-h-10 items-end justify-between gap-5">

                                        <div class="text-xs font-bold">

                                            @if ($isFree)
                                                <span class="text-[#ec3013]">
                                                    {{ __('Free') }}
                                                </span>
                                            @elseif ($hasPrice)
                                                <span>
                                                    {{ $pricing['summary'] }}
                                                </span>
                                            @endif

                                        </div>

                                        <span class="inline-flex shrink-0 items-center gap-2 border-b border-[#201e1d] pb-0.5 text-xs font-bold transition-all group-hover:border-[#ec3013] group-hover:text-[#ec3013]">

                                            {{ __('View event') }}

                                            <svg class="size-3.5 transition-transform group-hover:translate-x-0.5"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path d="m9 5 7 7-7 7"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2" />
                                            </svg>

                                        </span>

                                    </div>

                                </div>

                            </a>

                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <footer class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] py-6 text-[13px] opacity-55">{{ __('Loco-Locaux brings together event listings published by Geneva\'s communes.') }}</footer>
</div>
