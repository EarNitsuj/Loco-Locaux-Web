<?php

use App\Models\Event;
use Carbon\CarbonImmutable;
use Livewire\Component;

new class extends Component {
    public Event $event;

    public array $data = [];

    public function mount(Event $event): void
    {
        abort_if($event->normalized_json === null, 404);

        $event->loadMissing(['commune', 'categories', 'translations']);

        $this->event = $event;
        $this->data = $this->localizedJson($event);
    }

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

    public function dateLabel(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        return CarbonImmutable::parse($date)
            ->locale(app()->getLocale())
            ->translatedFormat('l j F Y');
    }

    public function shortDateLabel(?string $date): ?string
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

    public function dateRangeLabel(?string $start, ?string $end): ?string
    {
        if (!$start) {
            return null;
        }

        $locale = app()->getLocale();

        $startDate = CarbonImmutable::parse($start)->locale($locale);

        if (!$end) {
            return ucfirst($startDate->translatedFormat('l j F Y'));
        }

        $endDate = CarbonImmutable::parse($end)->locale($locale);

        if ($startDate->isSameDay($endDate)) {
            return ucfirst($startDate->translatedFormat('l j F Y'));
        }

        if ($startDate->year === $endDate->year) {
            return __('From :start to :end', [
                'start' => $startDate->translatedFormat('j F'),
                'end' => $endDate->translatedFormat('j F Y'),
            ]);
        }

        return __('From :start to :end', [
            'start' => $startDate->translatedFormat('j F Y'),
            'end' => $endDate->translatedFormat('j F Y'),
        ]);
    }

    public function categoryName($category): ?string
    {
        if (!$category) {
            return null;
        }

        $column = 'name_' . app()->getLocale();

        return $category->{$column} ?: $category->name_en;
    }
};
?>

@php
    $eventData = $data['event'] ?? [];
    $occurrences = $data['occurrences'] ?? [];

    $identity = $eventData['identity'] ?? [];
    $hero = $eventData['media']['hero'] ?? null;
    $location = $eventData['location'] ?? [];
    $pricing = $eventData['pricing'] ?? [];
    $registration = $eventData['registration'] ?? [];
    $contacts = $eventData['contacts'] ?? [];
    $organizers = $eventData['organizers'] ?? [];
    $documents = $eventData['documents'] ?? [];
    $accessibility = $eventData['accessibility'] ?? [];
    $links = $eventData['links'] ?? [];
    $sections = $eventData['content']['sections'] ?? [];
    $notices = $eventData['notices'] ?? [];
    $highlights = $eventData['highlights'] ?? [];

    $primaryCategory = $event->categories->first(fn($category) => (bool) $category->pivot->is_primary);

    $primaryCategoryName = $primaryCategory ? $this->categoryName($primaryCategory) : null;

    $sortedOccurrences = collect($occurrences)->filter(fn($occurrence) => !empty($occurrence['starts_at']))->sortBy('starts_at')->values();

    $firstOccurrence = $sortedOccurrences->first();
    $lastOccurrence = $sortedOccurrences->last();

    $rangeEnd = $lastOccurrence['ends_at'] ?? ($lastOccurrence['starts_at'] ?? null);

    $eventYear = !empty($firstOccurrence['starts_at']) ? CarbonImmutable::parse($firstOccurrence['starts_at'])->format('Y') : null;

    $isFree = ($pricing['is_free'] ?? false) === true;
    $hasPrice = !empty($pricing['summary']);

    $locationLabel = $location['name'] ?? ($location['address'] ?? ($event->commune?->name ?? null));

    $dateRangeLabel = $this->dateRangeLabel($firstOccurrence['starts_at'] ?? null, $rangeEnd);

    $headerDetailParts = array_values(array_filter([$dateRangeLabel, $locationLabel]));

    $headerDetail = implode(' — ', $headerDetailParts);

    $mapUrl = $location['map_url'] ?? (!empty($location['address']) ? 'https://maps.google.com/?q=' . urlencode($location['address']) : null);

    $homeUrl = route('events', [
        'locale' => app()->getLocale(),
    ]);
@endphp

<div class="pt-20">

    <main>
        {{-- Breadcrumb --}}
        <div class="max-w-295 mx-auto px-4 pt-6 sm:px-6">
            <nav aria-label="{{ __('Breadcrumb') }}"
                class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[12px] text-[#201e1d]/55">

                <a class="text-orange-600 underline-offset-2 hover:text-orange-700 hover:underline"
                    href="{{ $homeUrl }}"
                    wire:navigate>
                    {{ __('Agenda') }}
                </a>

                <span>/</span>

                @if ($primaryCategoryName)
                    <span>{{ $primaryCategoryName }}</span>
                    <span>/</span>
                @endif

                @if ($event->commune)
                    <span>{{ $event->commune->name }}</span>
                    <span>/</span>
                @endif

                <span class="text-[#201e1d]/80">
                    {{ $identity['title'] ?? __('Event') }}
                </span>
            </nav>
        </div>

        {{-- Header --}}
        <header class="max-w-295 mx-auto px-4 pb-8 pt-5 sm:px-6">
            <div class="mb-3 flex flex-wrap items-center gap-3">

                @if ($primaryCategoryName)
                    <span class="inline-flex border border-orange-600 px-3 py-1 text-[10px] font-extrabold uppercase tracking-[0.08em] text-orange-600">
                        {{ $primaryCategoryName }}
                    </span>
                @endif

                @if ($event->commune || $eventYear)
                    <span class="text-xs text-[#201e1d]/55">
                        @if ($event->commune)
                            {{ $event->commune->name }}
                        @endif

                        @if ($event->commune && $eventYear)
                            ·
                        @endif

                        @if ($eventYear)
                            {{ $eventYear }}
                        @endif
                    </span>
                @endif
            </div>

            <h1 class="max-w-[820px] text-[clamp(2.25rem,5vw,2.75rem)] font-extrabold leading-[1.08] tracking-[-0.025em]">
                {{ $identity['title'] ?? __('Event') }}
            </h1>

            <div class="mt-2 inline-flex items-center gap-4">
                @if (!empty($identity['subtitle']))
                    <p class="max-w-230 text-[17px] leading-relaxed text-[#201e1d]/75">
                        {{ $identity['subtitle'] }}
                    </p>
                @elseif ($headerDetail)
                    <p class="max-w-230 text-[17px] leading-relaxed text-[#201e1d]/75">
                        {{ $headerDetail }}
                    </p>
                @endif

                @php
                    $normalizationCost = (float) ($event->normalization_cost_usd ?? 0);

                    $translationCost = $event->translations->sum(fn($translation) => (float) ($translation->translation_cost_usd ?? 0));

                    $totalAiCost = $normalizationCost + $translationCost;
                @endphp

                <div class="bg-accent rounded-md p-2 text-sm text-white">
                    Total AI cost:
                    ${{ number_format($totalAiCost, 2) }}
                </div>
            </div>

        </header>

        {{-- Hero --}}
        <div class="max-w-295 mx-auto px-4 sm:px-6">
            <figure>
                <div class="aspect-21/9 flex w-full items-center justify-center overflow-hidden rounded-md bg-[#eae9e9]">
                    @if (!empty($hero['url']))
                        <img alt="{{ $hero['alt'] ?? ($identity['title'] ?? '') }}"
                            class="h-full w-full object-cover"
                            src="{{ $hero['url'] }}">
                    @else
                        <div class="flex flex-col items-center gap-3 px-6 text-center text-[#201e1d]/45">
                            <svg class="size-7"
                                fill="none"
                                stroke-width="1.5"
                                stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path
                                    d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M6.75 6.75h.008v.008H6.75V6.75Zm-2.25 13.5h15a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 19.5 3.75h-15A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25Z"
                                    stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>

                            @if (!empty($hero['alt']))
                                <span class="text-xs">{{ $hero['alt'] }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                @if (!empty($hero['credit']))
                    <figcaption class="mt-1 text-right text-[11px] text-[#201e1d]/50">
                        © {{ $hero['credit'] }}
                    </figcaption>
                @endif
            </figure>
        </div>

        {{-- Main content --}}
        <div class="max-w-295 mx-auto px-4 pb-20 pt-8 sm:px-6">
            <div class="grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">

                {{-- Main column --}}
                <div class="min-w-0">

                    @if (count($notices))
                        <section class="mb-6 rounded-md bg-orange-200 py-4">
                            <div class="space-y-3">
                                @foreach ($notices as $notice)
                                    <div class="flex gap-3 px-4">
                                        <span class="mt-2 size-1.5 shrink-0 bg-orange-600"></span>
                                        <p class="m-0 text-sm leading-relaxed text-orange-600">
                                            {{ $notice }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if (!empty($eventData['summary']))
                        <section class="mb-6">
                            <p class="m-0 text-2xl font-extrabold leading-[1.7]">
                                {{ $eventData['summary'] }}
                            </p>
                        </section>
                    @endif

                    @foreach ($sections as $section)
                        <section class="py-5 first:border-t-0 first:pt-0">

                            @if (!empty($section['title']))
                                <h2 class="mb-4 text-[22px] font-extrabold leading-tight tracking-[-0.015em]">
                                    {{ $section['title'] }}
                                </h2>
                            @endif

                            <div class="space-y-3 text-[15px] leading-[1.65] text-[#201e1d]">

                                @foreach ($section['blocks'] ?? [] as $block)
                                    @switch($block['type'] ?? null)
                                        @case('paragraph')
                                            @if (!empty($block['text']))
                                                <p class="whitespace-pre-line">{{ $block['text'] }}</p>
                                            @endif
                                        @break

                                        @case('list')
                                            <ul class="list-disc space-y-1.5 pl-5">
                                                @foreach ($block['items'] ?? [] as $item)
                                                    <li>{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                        @break

                                        @case('heading')
                                            @if (!empty($block['text']))
                                                <h3 class="pt-1 text-[15px] font-extrabold tracking-[-0.01em]">
                                                    {{ $block['text'] }}
                                                </h3>
                                            @endif
                                        @break

                                        @default
                                            @if (!empty($block['text']))
                                                <p class="whitespace-pre-line">{{ $block['text'] }}</p>
                                            @endif
                                    @endswitch
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    @if (count($highlights))
                        <section class="border-t border-[#201e1d]/45 py-5">
                            <h2 class="mb-4 text-[11px] font-extrabold uppercase tracking-[0.08em] text-[#201e1d]/60">
                                {{ __('Highlights') }}
                            </h2>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($highlights as $highlight)
                                    <div class="rounded-md bg-zinc-200 p-4">
                                        @if (!empty($highlight['label']))
                                            <div class="mb-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-[#201e1d]/55">
                                                {{ $highlight['label'] }}
                                            </div>
                                        @endif

                                        @if (!empty($highlight['value']))
                                            <div class="text-lg font-extrabold">
                                                {{ $highlight['value'] }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($hasPrice && count($pricing['groups'] ?? []))
                        <section class="py-5">
                            <h2 class="mb-5 text-[22px] font-extrabold tracking-[-0.015em]">
                                {{ __('Prices') }}
                            </h2>

                            <div class="space-y-6">
                                @foreach ($pricing['groups'] ?? [] as $group)
                                    <div>
                                        @if (!empty($group['label']))
                                            <h3 class="my-2 mt-8 text-sm font-extrabold uppercase text-zinc-500">
                                                {{ $group['label'] }}
                                            </h3>
                                        @endif

                                        @foreach ($group['items'] ?? [] as $item)
                                            <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-5 border-b border-zinc-200 py-2.5 text-sm">
                                                <span>{{ $item['label'] ?? '' }}</span>
                                                <span class="text-right font-semibold">
                                                    {{ $item['value'] ?? '' }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if (count($occurrences) > 1)
                        <section class="py-5"
                            x-data="{ open: true }">

                            <button @click="open = !open"
                                class="mb-4 flex w-full items-center justify-between gap-4 text-left"
                                type="button">

                                <h2 class="text-[22px] font-extrabold tracking-[-0.015em]">
                                    {{ __('All dates') }}
                                </h2>

                                <svg :class="open ? 'rotate-180' : ''"
                                    class="size-4 shrink-0 transition-transform"
                                    fill="none"
                                    stroke-width="1.8"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path d="m19 9-7 7-7-7"
                                        stroke-linecap="round"
                                        stroke-linejoin="round" />
                                </svg>
                            </button>

                            <div x-cloak
                                x-show="open"
                                x-transition.opacity>

                                @foreach ($occurrences as $occurrence)
                                    <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-5 border-b border-zinc-200 py-2.5 text-sm">
                                        <span class="capitalize">
                                            {{ $this->dateLabel($occurrence['starts_at'] ?? null) }}
                                        </span>

                                        <span class="text-right text-[#201e1d]/65">
                                            @if (!empty($occurrence['starts_at']))
                                                {{ $this->timeLabel($occurrence['starts_at']) }}
                                            @endif

                                            @if (!empty($occurrence['ends_at']))
                                                – {{ $this->timeLabel($occurrence['ends_at']) }}
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @php
                        $practicalRows = [];

                        if (!empty($location['name'])) {
                            $practicalRows[__('Venue')] = $location['name'];
                        }

                        if (!empty($location['address'])) {
                            $practicalRows[__('Address')] = $location['address'];
                        }

                        if ($isFree) {
                            $practicalRows[__('Price')] = __('Free');
                        } elseif ($hasPrice) {
                            $practicalRows[__('Price')] = $pricing['summary'];
                        }

                        if (!empty($registration['text'])) {
                            $practicalRows[__('Registration')] = $registration['text'];
                        }

                        if (!empty($eventData['audience'])) {
                            $practicalRows[__('Target audience')] = $eventData['audience'];
                        }
                    @endphp

                    @if (count($practicalRows))
                        <section class="py-5">
                            <h2 class="mb-4 text-[22px] font-extrabold tracking-[-0.015em]">
                                {{ __('Practical information') }}
                            </h2>

                            @foreach ($practicalRows as $label => $value)
                                <div class="grid grid-cols-1 gap-1 border-b border-zinc-200 py-3 text-sm sm:grid-cols-[150px_1fr] sm:gap-4">
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ $label }}
                                    </span>

                                    <span>{{ $value }}</span>
                                </div>
                            @endforeach
                        </section>
                    @endif

                    @if (count($accessibility))
                        <section class="py-5">
                            <h2 class="mb-4 text-[22px] font-extrabold tracking-[-0.015em]">
                                {{ __('Accessibility') }}
                            </h2>

                            @foreach ($accessibility as $item)
                                @php
                                    $accessibilityLabel = is_array($item) ? $item['label'] ?? null : $item;

                                    $accessibilityIcon = is_array($item) ? $item['icon_url'] ?? null : null;

                                    $accessibilityDetailsUrl = is_array($item) ? $item['details_url'] ?? null : null;
                                @endphp

                                <div class="flex items-center gap-3 border-b border-zinc-200 py-3 text-sm">

                                    @if ($accessibilityIcon)
                                        <img alt="{{ $accessibilityLabel ?? '' }}"
                                            class="size-10 shrink-0 rounded-md object-contain"
                                            src="{{ $accessibilityIcon }}">
                                    @else
                                        <span class="size-1.5 shrink-0 bg-[#ec3013]"></span>
                                    @endif

                                    @if ($accessibilityLabel)
                                        @if ($accessibilityDetailsUrl)
                                            <a class="text-[#201e1d] underline-offset-2 hover:text-[#ec3013] hover:underline"
                                                href="{{ $accessibilityDetailsUrl }}"
                                                rel="noopener noreferrer"
                                                target="_blank">
                                                {{ $accessibilityLabel }}
                                            </a>
                                        @else
                                            <span>{{ $accessibilityLabel }}</span>
                                        @endif
                                    @elseif ($accessibilityDetailsUrl)
                                        <a class="text-xs text-[#201e1d]/65 underline-offset-2 hover:text-[#ec3013] hover:underline"
                                            href="{{ $accessibilityDetailsUrl }}"
                                            rel="noopener noreferrer"
                                            target="_blank">
                                            Plus d'informations
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </section>
                    @endif

                    @if (count($documents))
                        <section class="py-5">
                            <h2 class="mb-3 text-[22px] font-extrabold tracking-[-0.015em]">
                                {{ __('Documents') }}
                            </h2>

                            @foreach ($documents as $document)
                                @php
                                    $documentMeta = array_values(array_filter([$document['type'] ?? ($document['format'] ?? null), $document['size'] ?? null]));
                                @endphp

                                <a class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-5 border-b border-zinc-200 py-3 text-sm text-[#201e1d] no-underline transition-colors hover:text-orange-600"
                                    href="{{ $document['url'] ?? '#' }}"
                                    rel="noopener noreferrer"
                                    target="_blank">

                                    <span>
                                        {{ $document['label'] ?? ($document['title'] ?? __('Document')) }}
                                    </span>

                                    @if (count($documentMeta))
                                        <span class="whitespace-nowrap text-[11px] uppercase tracking-[0.04em] text-[#201e1d]/50">
                                            {{ implode(' · ', $documentMeta) }}
                                        </span>
                                    @else
                                        <svg class="size-4 shrink-0"
                                            fill="none"
                                            stroke-width="1.8"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path d="M13.5 4.5H19.5V10.5M19 5L11 13"
                                                stroke-linecap="round"
                                                stroke-linejoin="round" />
                                        </svg>
                                    @endif
                                </a>
                            @endforeach
                        </section>
                    @endif

                    @if (count($links))
                        <section class="py-5">
                            <h2 class="mb-3 text-[22px] font-extrabold tracking-[-0.015em]">
                                {{ __('Links') }}
                            </h2>

                            @foreach ($links as $link)
                                @php
                                    $linkUrl = is_array($link) ? $link['url'] ?? null : null;

                                    $linkLabel = is_array($link) ? $link['label'] ?? ($link['title'] ?? ($link['url'] ?? __('Link'))) : $link;
                                @endphp

                                @if ($linkUrl)
                                    <a class="flex items-center justify-between gap-5 border-b border-zinc-200 py-3 text-sm text-[#201e1d] no-underline transition-colors hover:text-orange-600"
                                        href="{{ $linkUrl }}"
                                        rel="noopener noreferrer"
                                        target="_blank">

                                        <span>{{ $linkLabel }}</span>

                                        <svg class="size-4 shrink-0"
                                            fill="none"
                                            stroke-width="1.8"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path d="M13.5 4.5H19.5V10.5M19 5L11 13"
                                                stroke-linecap="round"
                                                stroke-linejoin="round" />
                                        </svg>
                                    </a>
                                @elseif ($linkLabel)
                                    <div class="border-b border-zinc-200 py-3 text-sm">
                                        {{ $linkLabel }}
                                    </div>
                                @endif
                            @endforeach
                        </section>
                    @endif

                </div>

                {{-- Summary sidebar --}}
                <aside class="lg:sticky lg:top-6">
                    <div class="rounded-md bg-zinc-100 px-6 py-5">

                        <h2 class="mb-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-[#201e1d]/55">
                            {{ __('Summary') }}
                        </h2>

                        <div>
                            @if ($firstOccurrence)
                                <div class="grid grid-cols-[92px_minmax(0,1fr)] gap-3 border-b border-zinc-200 py-3 first:pt-0">

                                    <div class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ __('Date') }}
                                    </div>

                                    <div class="text-sm leading-relaxed">
                                        <div class="capitalize">
                                            {{ $this->dateLabel($firstOccurrence['starts_at'] ?? null) }}
                                        </div>

                                        @if ($dateRangeLabel && count($sortedOccurrences) > 1)
                                            <div>{{ $dateRangeLabel }}</div>
                                        @elseif (!empty($firstOccurrence['starts_at']))
                                            <div class="text-[#201e1d]/65">
                                                {{ $this->timeLabel($firstOccurrence['starts_at']) }}

                                                @if (!empty($firstOccurrence['ends_at']))
                                                    – {{ $this->timeLabel($firstOccurrence['ends_at']) }}
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if (!empty($location['name']) || !empty($location['address']))
                                <div class="grid grid-cols-[92px_minmax(0,1fr)] gap-3 border-b border-zinc-200 py-3">

                                    <div class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ __('Venue') }}
                                    </div>

                                    <div class="text-sm leading-relaxed">
                                        @if (!empty($location['name']))
                                            <div>{{ $location['name'] }}</div>
                                        @endif

                                        @if (!empty($location['address']))
                                            <div class="text-[#201e1d]/65">
                                                {{ $location['address'] }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if ($isFree || $hasPrice)
                                <div class="grid grid-cols-[92px_minmax(0,1fr)] gap-3 border-b border-zinc-200 py-3">

                                    <div class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ __('Price') }}
                                    </div>

                                    <div class="text-sm leading-relaxed">
                                        {{ $isFree ? __('Free') : $pricing['summary'] }}
                                    </div>
                                </div>
                            @endif

                            @if (!empty($registration['text']))
                                <div class="grid grid-cols-[92px_minmax(0,1fr)] gap-3 border-b border-zinc-200 py-3">

                                    <div class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ __('Registration') }}
                                    </div>

                                    <div class="text-sm leading-relaxed">
                                        {{ $registration['text'] }}
                                    </div>
                                </div>
                            @endif

                            @if (!empty($eventData['audience']))
                                <div class="grid grid-cols-[92px_minmax(0,1fr)] gap-3 border-b border-zinc-200 py-3">

                                    <div class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ __('Target audience') }}
                                    </div>

                                    <div class="text-sm leading-relaxed">
                                        {{ $eventData['audience'] }}
                                    </div>
                                </div>
                            @endif

                            @foreach ($contacts as $contact)
                                @php
                                    $contactType = $contact['type'] ?? 'other';

                                    $contactTypeLabel = match ($contactType) {
                                        'email' => __('Email'),
                                        'telephone' => __('Telephone'),
                                        'mobile' => __('Mobile'),
                                        'fax' => __('Fax'),
                                        'website' => __('Website'),
                                        default => __('Contact'),
                                    };
                                @endphp

                                <div class="grid grid-cols-[92px_minmax(0,1fr)] gap-3 border-b border-zinc-200 py-3">

                                    <div class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ $contactTypeLabel }}
                                    </div>

                                    <div class="min-w-0 break-words text-sm leading-relaxed">

                                        @if ($contactType === 'email' && !empty($contact['value']))
                                            <a class="text-[#201e1d] underline-offset-2 hover:text-orange-600 hover:underline"
                                                href="mailto:{{ $contact['value'] }}">
                                                {{ $contact['value'] }}
                                            </a>
                                        @elseif (in_array($contactType, ['telephone', 'mobile']) && !empty($contact['value']))
                                            <a class="text-[#201e1d] underline-offset-2 hover:text-orange-600 hover:underline"
                                                href="tel:{{ preg_replace('/\s+/', '', $contact['value']) }}">
                                                {{ $contact['value'] }}
                                            </a>
                                        @elseif ($contactType === 'website' && !empty($contact['value']))
                                            <a class="text-[#201e1d] underline-offset-2 hover:text-orange-600 hover:underline"
                                                href="{{ $contact['value'] }}"
                                                rel="noopener noreferrer"
                                                target="_blank">
                                                {{ $contact['value'] }}
                                            </a>
                                        @else
                                            {{ $contact['value'] ?? '' }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            @if (count($organizers))
                                <div class="grid grid-cols-[92px_minmax(0,1fr)] gap-3 border-b border-zinc-200 py-3">

                                    <div class="text-[10px] font-semibold uppercase tracking-[0.07em] text-[#201e1d]/55">
                                        {{ __('Organizer') }}
                                    </div>

                                    <div class="space-y-1 text-sm leading-relaxed">
                                        @foreach ($organizers as $organizer)
                                            <div>
                                                {{ is_array($organizer) ? $organizer['name'] ?? '' : $organizer }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="mt-4 flex flex-col gap-2">

                            @if (!empty($registration['url']) && !str_starts_with($registration['url'], 'mailto:'))
                                <a @if (str_starts_with($registration['url'], 'http')) target="_blank" rel="noopener noreferrer" @endif
                                    class="flex min-h-9 w-full items-center justify-between gap-3 rounded-md bg-orange-600 px-4 py-2.5 text-sm font-extrabold text-[#f3f2f2] no-underline transition-colors hover:bg-orange-700"
                                    href="{{ $registration['url'] }}">

                                    <span>{{ __('Register') }}</span>

                                    <svg class="size-4 shrink-0"
                                        fill="none"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path d="M13.5 4.5H19.5V10.5M19 5L11 13"
                                            stroke-linecap="round"
                                            stroke-linejoin="round" />
                                    </svg>
                                </a>
                            @endif

                            @if ($mapUrl)
                                <a class="flex min-h-9 w-full items-center justify-between gap-3 rounded-md bg-orange-600 px-4 py-2.5 text-sm font-extrabold text-[#f3f2f2] no-underline transition-colors hover:bg-orange-700"
                                    href="{{ $mapUrl }}"
                                    rel="noopener noreferrer"
                                    target="_blank">

                                    <span>{{ __('Directions') }}</span>

                                    <svg class="size-4 shrink-0"
                                        fill="none"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path d="M17.657 16.657L13.414 20.9a2 2 0 0 1-2.827 0l-4.244-4.243a8 8 0 1 1 11.314 0Z" />
                                        <path d="M15 11a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                            @endif

                            @if (!empty($eventData['source']['event_url']))
                                <a class="flex min-h-9 w-full items-center justify-between gap-3 rounded-md bg-orange-600 px-4 py-2.5 text-sm font-extrabold text-[#f3f2f2] no-underline transition-colors hover:bg-orange-700"
                                    href="{{ $eventData['source']['event_url'] }}"
                                    rel="noopener noreferrer"
                                    target="_blank">

                                    <span>{{ __('View on official website') }}</span>

                                    <svg class="size-4 shrink-0"
                                        fill="none"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"
                                            stroke-linecap="round"
                                            stroke-linejoin="round" />
                                    </svg>
                                </a>
                            @endif
                        </div>
                    </div>
                </aside>

            </div>
        </div>
    </main>
</div>
