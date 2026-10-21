<?php

use App\AI\EventTaxonomy;
use App\Models\Category;
use App\Models\Commune;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'commune')]
    public string $commune = '';

    #[Url(as: 'category')]
    public string $category = '';

    #[Url(as: 'audience')]
    public string $audience = '';

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCommune(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedAudience(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'commune', 'category', 'audience']);

        $this->sort = 'newest';

        $this->resetPage();
    }

    public function displayOccurrence(array $occurrences): ?array
    {
        if ($occurrences === []) {
            return null;
        }

        $occurrences = collect($occurrences)->filter(fn(array $occurrence) => !empty($occurrence['starts_at']))->sortBy('starts_at')->values();

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
        if (!$date) {
            return null;
        }

        return CarbonImmutable::parse($date)->format('H:i');
    }

    /**
     * Return the correct event JSON for the current language.
     *
     * If the event's original language matches the UI locale, use
     * events.normalized_json. Otherwise prefer event_translations.
     */
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

    public function audienceName(string $key): string
    {
        return __("audience.{$key}");
    }

    public function with(): array
    {
        $locale = app()->getLocale();

        $events = Event::query()
            ->with(['commune', 'categories', 'translations' => fn($query) => $query->where('locale', $locale)])
            ->whereNotNull('normalized_json')

            // Commune
            ->when($this->commune !== '', fn(Builder $query) => $query->whereHas('commune', fn(Builder $query) => $query->where('slug', $this->commune)))

            // Category
            ->when($this->category !== '', fn(Builder $query) => $query->whereHas('categories', fn(Builder $query) => $query->where('key', $this->category)))

            // Audience
            ->when($this->audience !== '', fn(Builder $query) => $query->whereJsonContains('normalized_json->event->audiences', $this->audience))

            // Search source JSON + current translation
            ->when(trim($this->search) !== '', function (Builder $query) use ($locale) {
                $search = '%' . trim($this->search) . '%';

                $query->where(function (Builder $query) use ($search, $locale) {
                    // Original normalized content
                    $query
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.identity.title')) LIKE ?", [$search])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.summary')) LIKE ?", [$search])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.location.name')) LIKE ?", [$search])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.location.address')) LIKE ?", [$search])

                        // Current-language translation
                        ->orWhereHas('translations', function (Builder $query) use ($search, $locale) {
                            $query->where('locale', $locale)->where(function (Builder $query) use ($search) {
                                $query
                                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.identity.title')) LIKE ?", [$search])
                                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.summary')) LIKE ?", [$search])
                                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.location.name')) LIKE ?", [$search])
                                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.event.location.address')) LIKE ?", [$search]);
                            });
                        });
                });
            });

        match ($this->sort) {
            'oldest' => $events->oldest(),
            default => $events->latest(),
        };

        $communes = Commune::query()->whereHas('events', fn(Builder $query) => $query->whereNotNull('normalized_json'))->orderBy('name')->get();

        $categories = Category::query()
            ->whereHas('events')
            ->orderBy('name_' . app()->getLocale())
            ->get();

        return [
            'events' => $events->paginate(12),
            'communes' => $communes,
            'categories' => $categories,
            'audiences' => EventTaxonomy::AUDIENCES,
        ];
    }
};
?>

<div>
    {{-- ============================================================= --}}
    {{-- HERO --}}
    {{-- ============================================================= --}}

    <section class="max-w-295 mx-auto px-4 py-6 sm:px-6">

        <div class="flex flex-col gap-10 lg:flex-row lg:items-end lg:justify-between">

            {{-- <div class="max-w-3xl">

                <h1 class="max-w-3xl text-[2.75rem] font-black leading-[0.98] tracking-[-0.04em] text-[#201e1d] sm:text-6xl lg:text-[4.5rem]">
                    {{ __("What's happening") }}<br>
                    <span class="text-[#ec3013]">
                        {{ __('near you?') }}
                    </span>
                </h1>

                <p class="mt-6 max-w-xl text-sm leading-6 text-[#201e1d]/70 sm:text-base">
                    {{ __("Events, activities and local happenings across Geneva's communes — for all ages and interests.") }}
                </p>

            </div> --}}

        </div>

        {{-- ========================================================= --}}
        {{-- SEARCH + FILTERS --}}
        {{-- ========================================================= --}}

        <div class="mt-12 grid gap-px overflow-hidden rounded-md bg-zinc-200 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_200px_220px_200px_170px]">

            {{-- Search --}}
            <div class="relative bg-[#f3f2f2] md:col-span-2 xl:col-span-1">

                <svg class="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />
                </svg>

                <input class="h-13 w-full border-0 bg-transparent py-3.5 pl-11 pr-4 text-sm text-[#201e1d] outline-none placeholder:text-[#201e1d]/40 focus:ring-0"
                    placeholder="{{ __('Search for an event, place, or activity…') }}"
                    type="search"
                    wire:model.live.debounce.400ms="search">

            </div>

            {{-- Commune --}}
            <div class="relative bg-[#f3f2f2]">

                <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />

                    <path d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />
                </svg>

                <select class="h-13 w-full appearance-none border-0 bg-transparent py-3.5 pl-10 pr-9 text-sm text-[#201e1d] outline-none focus:ring-0"
                    wire:model.live="commune">
                    <option value="">
                        {{ __('All communes') }}
                    </option>

                    @foreach ($communes as $communeOption)
                        <option value="{{ $communeOption->slug }}">
                            {{ $communeOption->name }}
                        </option>
                    @endforeach
                </select>

                <svg class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="m19 9-7 7-7-7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />
                </svg>

            </div>

            {{-- Category --}}
            <div class="relative bg-[#f3f2f2]">

                <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="M3.75 6.75h6v6h-6v-6Zm10.5 0h6v6h-6v-6Zm-10.5 10.5h6v3h-6v-3Zm10.5 0h6v3h-6v-3Z"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.5" />
                </svg>

                <select class="h-13 w-full appearance-none border-0 bg-transparent py-3.5 pl-10 pr-9 text-sm text-[#201e1d] outline-none focus:ring-0"
                    wire:model.live="category">
                    <option value="">
                        {{ __('All categories') }}
                    </option>

                    @foreach ($categories as $categoryOption)
                        <option value="{{ $categoryOption->key }}">
                            {{ $this->categoryName($categoryOption) }}
                        </option>
                    @endforeach
                </select>

                <svg class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="m19 9-7 7-7-7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />
                </svg>

            </div>

            {{-- Audience --}}
            <div class="relative bg-[#f3f2f2]">

                <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path
                        d="M15 19.128a9.38 9.38 0 0 0 2.625.372A3.375 3.375 0 0 0 21 16.125V15a4.125 4.125 0 0 0-7.875-1.705M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766v-.109a6.375 6.375 0 0 1 11.964-3.07M12.75 7.875a4.125 4.125 0 1 1-8.25 0 4.125 4.125 0 0 1 8.25 0Zm6.75 2.625a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.5" />
                </svg>

                <select class="h-13 w-full appearance-none border-0 bg-transparent py-3.5 pl-10 pr-9 text-sm text-[#201e1d] outline-none focus:ring-0"
                    wire:model.live="audience">
                    <option value="">
                        {{ __('All audiences') }}
                    </option>

                    @foreach ($audiences as $audienceOption)
                        <option value="{{ $audienceOption }}">
                            {{ __("audience.{$audienceOption}") }}
                        </option>
                    @endforeach
                </select>

                <svg class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="m19 9-7 7-7-7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />
                </svg>

            </div>

            {{-- Sort --}}
            <div class="relative bg-[#f3f2f2]">

                <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="M3 7h18M6 12h12M9 17h6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />
                </svg>

                <select class="h-13 w-full appearance-none border-0 bg-transparent py-3.5 pl-10 pr-9 text-sm text-[#201e1d] outline-none focus:ring-0"
                    wire:model.live="sort">
                    <option value="newest">
                        {{ __('Newest') }}
                    </option>

                    <option value="oldest">
                        {{ __('Oldest') }}
                    </option>
                </select>

                <svg class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[#201e1d]/45"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path d="m19 9-7 7-7-7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8" />
                </svg>

            </div>

        </div>

        {{-- ========================================================= --}}
        {{-- ACTIVE FILTERS --}}
        {{-- ========================================================= --}}

        @if ($search !== '' || $commune !== '' || $category !== '' || $audience !== '' || $sort !== 'newest')
            <div class="mt-4 flex flex-wrap items-center gap-2">

                <span class="mr-1 text-[10px] font-bold uppercase tracking-[0.16em] text-[#201e1d]/50">
                    {{ __('Active filters') }}
                </span>

                @if ($search !== '')
                    <span class="inline-flex items-center gap-1.5 border border-[#201e1d]/40 px-2.5 py-1 text-xs font-semibold">

                        <svg class="size-3"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2" />
                        </svg>

                        "{{ $search }}"
                    </span>
                @endif

                @if ($commune !== '')
                    <span class="inline-flex items-center gap-1.5 border border-[#201e1d]/40 px-2.5 py-1 text-xs font-semibold">
                        {{ $communes->firstWhere('slug', $commune)?->name ?? $commune }}
                    </span>
                @endif

                @if ($category !== '')
                    @php
                        $selectedCategory = $categories->firstWhere('key', $category);
                    @endphp

                    <span class="inline-flex items-center gap-1.5 border border-[#201e1d]/40 px-2.5 py-1 text-xs font-semibold">
                        {{ $selectedCategory ? $this->categoryName($selectedCategory) : $category }}
                    </span>
                @endif

                @if ($audience !== '')
                    <span class="inline-flex items-center gap-1.5 border border-[#201e1d]/40 px-2.5 py-1 text-xs font-semibold">
                        {{ __("audience.{$audience}") }}
                    </span>
                @endif

                @if ($sort !== 'newest')
                    <span class="inline-flex items-center gap-1.5 border border-[#201e1d]/40 px-2.5 py-1 text-xs font-semibold">
                        {{ __('Oldest') }}
                    </span>
                @endif

                <button class="ml-1 inline-flex items-center gap-1 border-b border-[#201e1d]/40 pb-0.5 text-xs font-semibold transition hover:border-[#ec3013] hover:text-[#ec3013]"
                    type="button"
                    wire:click="clearFilters">
                    <svg class="size-3"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path d="M6 18 18 6M6 6l12 12"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2" />
                    </svg>

                    {{ __('Clear all') }}
                </button>

            </div>
        @endif

    </section>

    {{-- ============================================================= --}}
    {{-- MAIN CONTENT --}}
    {{-- ============================================================= --}}

    <main class="bg-zinc-100 dark:bg-zinc-900">

        <div class="max-w-295 mx-auto px-4 py-6 sm:px-6">

            {{-- Loading --}}
            <div class="mb-8 border-l-2 border-[#ec3013] py-2 pl-4"
                wire:loading
                wire:target="search,commune,category,audience,sort">

                <div class="flex items-center gap-3">

                    <svg class="size-4 animate-spin text-[#ec3013]"
                        fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25"
                            cx="12"
                            cy="12"
                            r="10"
                            stroke-width="4"
                            stroke="currentColor" />

                        <path class="opacity-75"
                            d="M4 12a8 8 0 018-8v8H4Z"
                            fill="currentColor" />
                    </svg>

                    <span class="text-sm font-medium">
                        {{ __('Updating results…') }}
                    </span>

                </div>

            </div>

            @if ($events->count())

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                    wire:loading.class="opacity-40 pointer-events-none"
                    wire:target="search,commune,category,audience,sort">

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

                {{-- Pagination --}}
                <div class="mt-12 border-t border-[#201e1d]/30 pt-6">
                    {{ $events->links() }}
                </div>
            @else
                {{-- Empty state --}}
                <div class="py-20 text-center">

                    <div class="mx-auto flex size-14 items-center justify-center border border-[#201e1d]/40">

                        <svg class="size-6 text-[#201e1d]/50"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.5" />
                        </svg>

                    </div>

                    <h3 class="mt-5 text-xl font-black tracking-[-0.02em]">
                        {{ __('No events match') }}
                    </h3>

                    <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-[#201e1d]/60">
                        {{ __('Try another keyword or change your filters.') }}
                    </p>

                    <button class="mt-6 inline-flex items-center gap-2 bg-[#ec3013] px-5 py-3 text-xs font-bold text-white transition hover:bg-[#201e1d]"
                        type="button"
                        wire:click="clearFilters">

                        <svg class="size-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8" />
                        </svg>

                        {{ __('View all events') }}

                    </button>

                </div>

            @endif

        </div>

    </main>
</div>
