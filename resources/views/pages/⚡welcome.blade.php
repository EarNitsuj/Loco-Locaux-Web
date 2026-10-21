<?php

use App\Models\Event;
use Carbon\CarbonImmutable;
use Livewire\Component;

new class extends Component {
    public Event $event;

    public array $data = [];

    public function mount(Event $event): void
    {
        $this->event = $event;
        $this->data = $event->normalized_json ?? [];
    }

    public function dateLabel(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        return CarbonImmutable::parse($date)->locale('fr')->translatedFormat('l j F Y');
    }

    public function timeLabel(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        return CarbonImmutable::parse($date)->format('H:i');
    }
};
?>

@php
    $eventData = $data['event'] ?? [];
    $occurrences = $data['occurrences'] ?? [];

    $identity = $eventData['identity'] ?? [];
    $media = $eventData['media'] ?? [];
    $hero = $media['hero'] ?? null;
    $location = $eventData['location'] ?? [];
    $pricing = $eventData['pricing'] ?? [];
    $registration = $eventData['registration'] ?? [];
    $contacts = $eventData['contacts'] ?? [];
    $sections = $eventData['content']['sections'] ?? [];
    $notices = $eventData['notices'] ?? [];
    $highlights = $eventData['highlights'] ?? [];
@endphp

<div class="min-h-screen bg-white dark:bg-zinc-950">

    {{-- Hero --}}
    @if (!empty($hero['url']))
        <div class="relative w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="relative aspect-[16/7] overflow-hidden rounded-b-3xl">
                    <img alt="{{ $hero['alt'] ?? ($identity['title'] ?? '') }}"
                        class="h-full w-full object-cover"
                        src="{{ $hero['url'] }}">

                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent"></div>

                    @if (!empty($hero['credit']))
                        <div class="absolute bottom-4 right-4 rounded-full bg-black/50 px-3 py-1.5 text-xs text-white backdrop-blur">
                            © {{ $hero['credit'] }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">

        {{-- Header --}}
        <div class="mb-10 max-w-4xl">

            @if (!empty($identity['category']))
                <div class="mb-4">
                    <span class="inline-flex rounded-full bg-zinc-100 px-3 py-1 text-sm font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ $identity['category'] }}
                    </span>
                </div>
            @endif

            <h1 class="text-3xl font-bold tracking-tight text-zinc-950 sm:text-4xl lg:text-5xl dark:text-white">
                {{ $identity['title'] ?? 'Événement' }}
            </h1>

            @if (!empty($identity['subtitle']))
                <p class="mt-3 text-xl text-zinc-500 dark:text-zinc-400">
                    {{ $identity['subtitle'] }}
                </p>
            @endif

            @if (!empty($eventData['summary']))
                <p class="mt-5 max-w-3xl text-lg leading-8 text-zinc-600 dark:text-zinc-300">
                    {{ $eventData['summary'] }}
                </p>
            @endif

            @if (!empty($eventData['audience']))
                <div class="mt-5 inline-flex items-center gap-2 rounded-lg bg-zinc-100 px-4 py-2 text-sm font-medium text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                    <svg class="size-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path d="M17 20h5v-2a4 4 0 00-5-4M9 20H2v-2a4 4 0 014-4h3m4 6v-2a4 4 0 00-4-4H6m8-5a4 4 0 11-8 0 4 4 0 018 0zm7 1a3 3 0 10-3-3"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8" />
                    </svg>

                    {{ $eventData['audience'] }}
                </div>
            @endif
        </div>

        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-14">

            {{-- ========================================================= --}}
            {{-- MAIN CONTENT --}}
            {{-- ========================================================= --}}

            <div class="min-w-0">

                {{-- Notices --}}
                @foreach ($notices as $notice)
                    <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                        <div class="flex gap-3">
                            <svg class="mt-0.5 size-5 shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path d="M12 9v3.75m9-1.5a9 9 0 11-18 0 9 9 0 0118 0zM12 16.5h.008v.008H12V16.5z"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8" />
                            </svg>

                            <div>{{ $notice }}</div>
                        </div>
                    </div>
                @endforeach

                {{-- Description / normalized content --}}
                @foreach ($sections as $section)
                    <section class="mb-10">

                        @if (!empty($section['title']))
                            <h2 class="mb-5 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">
                                {{ $section['title'] }}
                            </h2>
                        @endif

                        <div class="space-y-5 text-[16px] leading-7 text-zinc-700 dark:text-zinc-300">

                            @foreach ($section['blocks'] ?? [] as $block)
                                @switch($block['type'] ?? null)
                                    @case('paragraph')
                                        <p>
                                            {{ $block['text'] }}
                                        </p>
                                    @break

                                    @case('notice')
                                        {{-- Already prominently displayed above --}}
                                    @break

                                    @case('list')
                                        <ul class="list-disc space-y-2 pl-5">
                                            @foreach ($block['items'] ?? [] as $item)
                                                <li>{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                    @break

                                    @case('heading')
                                        <h3 class="pt-2 text-lg font-semibold text-zinc-950 dark:text-white">
                                            {{ $block['text'] }}
                                        </h3>
                                    @break

                                    @default
                                        @if (!empty($block['text']))
                                            <p>{{ $block['text'] }}</p>
                                        @endif
                                @endswitch
                            @endforeach

                        </div>
                    </section>
                @endforeach

                {{-- Highlights --}}
                @if (count($highlights))
                    <section class="mb-10">
                        <h2 class="mb-5 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">
                            À retenir
                        </h2>

                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($highlights as $highlight)
                                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                    @if (!empty($highlight['label']))
                                        <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                            {{ $highlight['label'] }}
                                        </div>
                                    @endif

                                    <div class="text-sm leading-6 text-zinc-800 dark:text-zinc-200">
                                        {{ $highlight['value'] ?? '' }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

            </div>

            {{-- ========================================================= --}}
            {{-- SIDEBAR --}}
            {{-- ========================================================= --}}

            <aside>
                <div class="space-y-4 lg:sticky lg:top-6">

                    {{-- Date / occurrences --}}
                    @if (count($occurrences))
                        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                                Date et horaire
                            </h2>

                            <div class="space-y-4">
                                @foreach ($occurrences as $occurrence)
                                    <div class="flex gap-3">

                                        <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                            <svg class="size-4 text-zinc-700 dark:text-zinc-300"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path d="M6.75 3v2.25M17.25 3v2.25M3.75 9.75h16.5M5.25 5.25h13.5A1.5 1.5 0 0120.25 6.75v12A1.5 1.5 0 0118.75 20.25H5.25a1.5 1.5 0 01-1.5-1.5v-12a1.5 1.5 0 011.5-1.5z"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="1.8" />
                                            </svg>
                                        </div>

                                        <div>
                                            <div class="font-semibold capitalize text-zinc-950 dark:text-white">
                                                {{ $this->dateLabel($occurrence['starts_at'] ?? null) }}
                                            </div>

                                            @if (!empty($occurrence['starts_at']))
                                                <div class="mt-1 text-sm text-zinc-500">
                                                    {{ $this->timeLabel($occurrence['starts_at']) }}

                                                    @if (!empty($occurrence['ends_at']))
                                                        –
                                                        {{ $this->timeLabel($occurrence['ends_at']) }}
                                                    @endif
                                                </div>
                                            @endif
                                        </div>

                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Location --}}
                    @if (!empty($location['name']) || !empty($location['address']))
                        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                                Lieu
                            </h2>

                            @if (!empty($location['name']))
                                <div class="font-semibold text-zinc-950 dark:text-white">
                                    {{ $location['name'] }}
                                </div>
                            @endif

                            @if (!empty($location['address']))
                                <address class="mt-1 not-italic leading-6 text-zinc-600 dark:text-zinc-300">
                                    {{ $location['address'] }}
                                </address>
                            @endif

                            @if (!empty($location['map_url']))
                                <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-zinc-950 hover:underline dark:text-white"
                                    href="{{ $location['map_url'] }}"
                                    rel="noopener noreferrer"
                                    target="_blank">
                                    Voir sur la carte

                                    <svg class="size-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path d="M13.5 4.5H19.5V10.5M19 5L11 13M10 6H6.5A1.5 1.5 0 005 7.5v10A1.5 1.5 0 006.5 19h10a1.5 1.5 0 001.5-1.5V14"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="1.8" />
                                    </svg>
                                </a>
                            @endif
                        </div>
                    @endif

                    {{-- Pricing --}}
                    @if (($pricing['is_free'] ?? null) !== null || !empty($pricing['summary']))
                        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                                Tarif
                            </h2>

                            @if (($pricing['is_free'] ?? false) === true)
                                <div class="text-lg font-semibold text-zinc-950 dark:text-white">
                                    Gratuit
                                </div>
                            @elseif (!empty($pricing['summary']))
                                <div class="font-semibold leading-6 text-zinc-950 dark:text-white">
                                    {{ $pricing['summary'] }}
                                </div>
                            @endif

                        </div>
                    @endif

                    {{-- Registration --}}
                    @if (!empty($registration['text']) || !empty($registration['url']))
                        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                                Inscription
                            </h2>

                            @if (!empty($registration['text']))
                                <p class="text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                                    {{ $registration['text'] }}
                                </p>
                            @endif

                            @if (!empty($registration['url']))
                                <a @if (str_starts_with($registration['url'], 'http')) target="_blank"
                                        rel="noopener noreferrer" @endif
                                    class="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
                                    href="{{ $registration['url'] }}">
                                    S'inscrire
                                </a>
                            @endif

                        </div>
                    @endif

                    {{-- Contacts --}}
                    @if (count($contacts))
                        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                                Contact
                            </h2>

                            <div class="space-y-3">
                                @foreach ($contacts as $contact)
                                    <div>
                                        @if (!empty($contact['label']))
                                            <div class="text-xs font-medium text-zinc-500">
                                                {{ $contact['label'] }}
                                            </div>
                                        @endif

                                        @if (($contact['type'] ?? null) === 'email')
                                            <a class="text-sm font-medium text-zinc-950 hover:underline dark:text-white"
                                                href="mailto:{{ $contact['value'] }}">
                                                {{ $contact['value'] }}
                                            </a>
                                        @elseif (($contact['type'] ?? null) === 'phone')
                                            <a class="text-sm font-medium text-zinc-950 hover:underline dark:text-white"
                                                href="tel:{{ preg_replace('/\s+/', '', $contact['value']) }}">
                                                {{ $contact['value'] }}
                                            </a>
                                        @else
                                            <div class="text-sm text-zinc-800 dark:text-zinc-200">
                                                {{ $contact['value'] }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                        </div>
                    @endif

                </div>
            </aside>

        </div>
    </main>
</div>
