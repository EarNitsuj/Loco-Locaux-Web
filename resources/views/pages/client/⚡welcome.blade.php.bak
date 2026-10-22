<?php

use Livewire\Component;
use App\Models\Event;

new class extends Component {
    public array $locales = ['fr', 'en', 'de', 'it', 'pt', 'es'];
    public $events;

    public function mount(): void
    {
        $locale = request()->route('locale') ?? session('locale');

        $this->events = Event::all();

        if (is_string($locale) && in_array($locale, $this->locales, true)) {
            app()->setLocale($locale);
        }
    }

    public function switchLocale(string $locale): void
    {
        abort_unless(in_array($locale, $this->locales, true), 404);

        session()->put('locale', $locale);
        app()->setLocale($locale);
    }
};
?>

<div class="min-h-screen font-['Archivo',system-ui,sans-serif] text-[15px] leading-[1.55] text-[#201e1d]">

    <section class="min-w-screen min-h-screen space-y-4 overflow-y-auto bg-zinc-100 px-20 py-20">
        @foreach ($events as $event)
            <div class="bg-white p-6">
                <p class="text-xl font-bold">{{ $event->commune?->name }}</p>
                <div class="mt-4 bg-zinc-50 p-4">
                    {!! $event->raw_html !!}
                </div>
                <br>
                <a class="text-sky-500 underline"
                    href="{{ $event->event_url }}"
                    target="_blank">{{ $event->event_url }}</a>
            </div>
        @endforeach
    </section>

    <!-- Navigation -->
    {{-- <nav class="sticky top-0 z-20 flex flex-wrap items-center gap-4 border-b-2 border-[#201e1d]/40 px-4 py-3">
        <span class="mr-auto text-lg font-extrabold">Loco-Locaux</span>
        <a class="text-sm transition-colors hover:text-[#ec3013]"
            href="#comment">{{ __('How it works') }}</a>

        <div aria-label="Language"
            class="inline-flex overflow-hidden border border-[#201e1d]/40"
            role="group">
            @foreach ($locales as $locale)
                <button aria-pressed="{{ app()->getLocale() === $locale ? 'true' : 'false' }}"
                    class="{{ app()->getLocale() === $locale ? 'bg-[#ec3013] text-[#f3f2f2] hover:bg-[#ec3013]' : '' }} border-l border-[#201e1d]/40 px-3 py-[7px] text-[13px] uppercase transition first:border-l-0 hover:bg-[#201e1d]/[0.07]"
                    type="button"
                    wire:click="switchLocale('{{ $locale }}')">
                    {{ strtoupper($locale) }}
                </button>
            @endforeach
        </div>

        <button class="inline-flex items-center justify-center bg-[#ec3013] px-[14px] py-2 text-sm font-extrabold leading-[1.2] text-[#f3f2f2] transition hover:bg-[#dd2b0f] active:bg-[#ae1800]"
            type="button">{{ __('Get the app') }}</button>
    </nav> --}}
    <!-- Hero -->
    <section class="max-w-295 mx-auto flex h-screen flex-wrap items-center px-4 py-6 sm:px-6">
        <div class="min-w-[280px] flex-[1_1_420px]">
            <h1 class="mb-5 max-w-[16ch] text-[clamp(38px,5.4vw,64px)] font-extrabold leading-[1.06] tracking-[-0.02em]">{{ __('There is more happening around you than you think.') }}</h1>
            <p class="mb-7 max-w-[46ch] text-lg leading-[1.6] opacity-85">{{ __('Every commune in Geneva runs its own agenda. We bring them together in one place, so your weekend doesn\'t depend on which website you remembered to check.') }}</p>
            <div class="flex flex-wrap gap-3">
                <button class="inline-flex items-center justify-center bg-[#ec3013] px-[14px] py-2 text-sm font-extrabold leading-[1.2] text-[#f3f2f2] transition hover:bg-[#dd2b0f] active:bg-[#ae1800]"
                    type="button">{{ __('Get the app') }}</button>
                <a class="inline-flex items-center justify-center border border-[#201e1d]/40 px-[14px] py-2 text-sm font-extrabold leading-[1.2] transition hover:bg-[#201e1d]/[0.07] active:bg-[#201e1d]/[0.14]"
                    href="#discovery">{{ __('See what\'s on this weekend') }}</a>
            </div>
        </div>
        <!-- App preview -->
        <div class="w-full max-w-[320px] flex-[0_0_320px] border-2 border-[#201e1d]/40">
            <div class="mx-auto my-2.5 h-1.5 w-[60px] bg-[#201e1d]/40"></div>
            <div class="px-4 pb-5">
                <p class="mb-3 text-[11px] uppercase tracking-[0.08em] opacity-55">Loco-Locaux</p>
                <div class="mb-4 flex flex-wrap gap-1.5">
                    <span class="inline-flex items-center bg-[#f8f4f4] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#444141]">{{ __('Tonight') }}</span>
                    <span class="inline-flex items-center bg-[#f8f4f4] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#444141]">{{ __('This weekend') }}</span>
                    <span class="inline-flex items-center bg-[#f8f4f4] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#444141]">{{ __('Near you') }}</span>
                    <span class="inline-flex items-center bg-[#fff2ef] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#7c1405]">{{ __('Free') }}</span>
                    <span class="inline-flex items-center bg-[#f8f4f4] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#444141]">{{ __('For families') }}</span>
                    <span class="inline-flex items-center bg-[#f8f4f4] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#444141]">{{ __('Outdoors') }}</span>
                </div>
                <div class="mb-2.5 flex flex-col gap-2 bg-[#eae9e9] p-3 shadow-[0_1px_2px_rgba(45,43,43,0.14)]">
                    <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Carouge</p>
                    <h3 class="text-[17px] font-extrabold leading-[1.2]">{{ __('Carouge night market') }}</h3>
                    <p class="text-[11px] text-[#201e1d]/50">
                        <span>Sat 23 Aug</span>
                        <span> · </span>
                        <span>{{ __('Free') }}</span>
                    </p>
                </div>
                <div class="flex flex-col gap-2 bg-[#eae9e9] p-3 shadow-[0_1px_2px_rgba(45,43,43,0.14)]">
                    <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Parc des Bastions</p>
                    <h3 class="text-[17px] font-extrabold leading-[1.2]">{{ __('Free concert, Parc des Bastions') }}</h3>
                    <p class="text-[11px] text-[#201e1d]/50">
                        <span>Fri 22 Aug, 20:00</span>
                        <span> · </span>
                        <span>{{ __('Free') }}</span>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- Problem recognition -->
    <section class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]">
        <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('Today') }}</span>
        <h2 class="mb-4 max-w-[20ch] text-[clamp(28px,3.6vw,42px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('Finding one event shouldn\'t require five websites.') }}</h2>
        <p class="mb-9 max-w-[56ch] text-base leading-[1.65] opacity-80">{{ __('Every commune keeps its own agenda: a website, a newsletter, a PDF, a social page. To know what\'s happening nearby, you\'d have to check them all, one at a time.') }}
        </p>
        <div class="flex flex-wrap gap-4">
            <div class="min-w-[180px] flex-[1_1_180px] border border-[#201e1d]/40 bg-[#eae9e9] px-4 py-3.5">
                <p class="mb-1.5 text-[10px] uppercase tracking-[0.1em] opacity-50">ville-meyrin.ch/agenda</p>
                <p class="mb-2 text-base font-extrabold">Meyrin</p>
                <p class="text-[11px] uppercase tracking-[0.08em] text-[#ae1800]">AGENDA</p>
            </div>
            <div class="min-w-[180px] flex-[1_1_180px] border border-[#201e1d]/40 bg-[#eae9e9] px-4 py-3.5">
                <p class="mb-1.5 text-[10px] uppercase tracking-[0.1em] opacity-50">ville-vernier.ch/agenda</p>
                <p class="mb-2 text-base font-extrabold">Vernier</p>
                <p class="text-[11px] uppercase tracking-[0.08em] text-[#ae1800]">AGENDA</p>
            </div>
            <div class="min-w-[180px] flex-[1_1_180px] border border-[#201e1d]/40 bg-[#eae9e9] px-4 py-3.5">
                <p class="mb-1.5 text-[10px] uppercase tracking-[0.1em] opacity-50">ville-carouge.ch/agenda</p>
                <p class="mb-2 text-base font-extrabold">Carouge</p>
                <p class="text-[11px] uppercase tracking-[0.08em] text-[#ae1800]">AGENDA</p>
            </div>
            <div class="min-w-[180px] flex-[1_1_180px] border border-[#201e1d]/40 bg-[#eae9e9] px-4 py-3.5">
                <p class="mb-1.5 text-[10px] uppercase tracking-[0.1em] opacity-50">ville-onex.ch/agenda</p>
                <p class="mb-2 text-base font-extrabold">Onex</p>
                <p class="text-[11px] uppercase tracking-[0.08em] text-[#ae1800]">AGENDA</p>
            </div>
            <div class="min-w-[180px] flex-[1_1_180px] border border-[#201e1d]/40 bg-[#eae9e9] px-4 py-3.5">
                <p class="mb-1.5 text-[10px] uppercase tracking-[0.1em] opacity-50">ville-genève.ch/agenda</p>
                <p class="mb-2 text-base font-extrabold">Genève</p>
                <p class="text-[11px] uppercase tracking-[0.08em] text-[#ae1800]">AGENDA</p>
            </div>
        </div>
    </section>
    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- Transformation -->
    <section class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]">
        <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('The idea') }}</span>
        <h2 class="mb-4 text-[clamp(28px,3.6vw,42px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('45 communes. One experience.') }}</h2>
        <p class="mb-9 max-w-[56ch] text-base leading-[1.65] opacity-80">{{ __('Not because the list is long — because you shouldn\'t have to think commune by commune. Interest first, commune second.') }}</p>
        <div class="flex flex-wrap items-center gap-6">
            <div class="min-w-[260px] flex-[1_1_320px] border border-[#201e1d]/40 p-5">
                <p class="mb-3 text-[11px] uppercase tracking-[0.08em] opacity-55">{{ __('Search each commune site, one by one') }}</p>
                <div class="flex flex-col gap-1.5 text-[13px] opacity-75">
                    <span>Meyrin</span>
                    <span>Vernier</span>
                    <span>Carouge</span>
                    <span>Onex</span>
                    <span>Genève</span>
                    <span>Lancy</span>
                    <span>Satigny</span>
                    <span>Grand-Saconnex</span>
                    <span>Confignon</span>
                    <span>Bernex</span>
                </div>
            </div>
            <div class="flex-none text-[28px] font-extrabold text-[#ec3013]">→</div>
            <div class="min-w-[260px] flex-[1_1_320px] border-2 border-[#ec3013] p-5">
                <p class="mb-3 text-[11px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('Open one app, see what\'s around you') }}</p>
                <p class="text-[22px] font-extrabold">Loco-Locaux</p>
            </div>
        </div>
    </section>
    <!-- Source file contains no actual image asset; this replaces its image-slot placeholder. -->
    {{-- <div class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] pb-[clamp(48px,6vw,72px)]">
        <div class="flex aspect-[16/6] w-full items-center justify-center border border-[#201e1d]/40 bg-[#eae9e9] text-center text-sm text-[#201e1d]/45 contrast-[1.08] grayscale">
            Drop a photo — a Geneva market, park or neighbourhood scene
        </div>
    </div> --}}
    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- Discovery -->
    <section class="mx-auto max-w-[1200px] scroll-mt-24 px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]"
        id="discovery">
        <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('Search by what you feel like doing') }}</span>
        <h2 class="mb-4 text-[clamp(28px,3.6vw,42px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('Tonight. This weekend. Near you. Free.') }}</h2>
        <p class="mb-7 max-w-[56ch] text-base leading-[1.65] opacity-80">{{ __('Filters built around real questions, not administrative categories.') }}</p>
        <div class="mb-8 flex flex-wrap gap-2">
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('Tonight') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('This weekend') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('Near you') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('Free') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('For families') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('Outdoors') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('Culture') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('Food &amp; markets') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('Sports') }}</span>
            <span class="inline-flex items-center border border-[#ec3013] px-2.5 py-[3px] text-[11px] tracking-[0.02em] text-[#ec3013]">{{ __('For seniors') }}</span>
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4">
            <article class="flex flex-col gap-2 bg-[#eae9e9] p-3 shadow-[0_1px_2px_rgba(45,43,43,0.14)]">
                <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Carouge</p>
                <h3 class="text-[17px] font-extrabold leading-[1.2]">{{ __('Carouge night market') }}</h3>
                <p class="flex-1 text-[13px] opacity-80">Sat 23 Aug</p>
                <p class="text-[11px] text-[#201e1d]/50">{{ __('Free') }}</p>
            </article>
            <article class="flex flex-col gap-2 bg-[#eae9e9] p-3 shadow-[0_1px_2px_rgba(45,43,43,0.14)]">
                <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Onex</p>
                <h3 class="text-[17px] font-extrabold leading-[1.2]">{{ __('Children\'s workshop, Onex') }}</h3>
                <p class="flex-1 text-[13px] opacity-80">Sun 24 Aug, 10:00</p>
                <p class="text-[11px] text-[#201e1d]/50">{{ __('Free') }}</p>
            </article>
            <article class="flex flex-col gap-2 bg-[#eae9e9] p-3 shadow-[0_1px_2px_rgba(45,43,43,0.14)]">
                <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Vernier</p>
                <h3 class="text-[17px] font-extrabold leading-[1.2]">{{ __('Open-air cinema, Vernier') }}</h3>
                <p class="flex-1 text-[13px] opacity-80">Sat 23 Aug, 21:30</p>
                <p class="text-[11px] text-[#201e1d]/50">{{ __('Free') }}</p>
            </article>
        </div>
    </section>
    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- Serendipity -->
    <section class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]">
        <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('Around the corner') }}</span>
        <h2 class="mb-4 max-w-[24ch] text-[clamp(28px,3.6vw,42px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('The best event might be the one you weren\'t looking for.') }}</h2>
        <p class="mb-8 max-w-[56ch] text-base leading-[1.65] opacity-80">{{ __('A pop-up market in Carouge. A free concert at the Parc des Bastions. A workshop in Onex you\'d never have found on your own.') }}</p>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
            <article class="flex flex-col gap-2 bg-[#eae9e9] p-3">
                <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Carouge</p>
                <h3 class="text-[15px] font-extrabold leading-[1.2]">{{ __('Carouge night market') }}</h3>
            </article>
            <article class="flex flex-col gap-2 bg-[#eae9e9] p-3">
                <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Onex</p>
                <h3 class="text-[15px] font-extrabold leading-[1.2]">{{ __('Children\'s workshop, Onex') }}</h3>
            </article>
            <article class="flex flex-col gap-2 bg-[#eae9e9] p-3">
                <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Meyrin</p>
                <h3 class="text-[15px] font-extrabold leading-[1.2]">{{ __('Tea dance for seniors, Meyrin') }}</h3>
            </article>
            <article class="flex flex-col gap-2 bg-[#eae9e9] p-3">
                <p class="text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Vernier</p>
                <h3 class="text-[15px] font-extrabold leading-[1.2]">{{ __('Open-air cinema, Vernier') }}</h3>
            </article>
        </div>
    </section>
    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- No borders -->
    <section class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]">
        <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('No borders') }}</span>
        <h2 class="mb-4 max-w-[22ch] text-[clamp(28px,3.6vw,42px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('Geneva doesn\'t stop at your commune. Neither should your plans.') }}</h2>
        <p class="mb-10 max-w-[56ch] text-base leading-[1.65] opacity-80">{{ __('Live in Meyrin? Something in Vernier, Grand-Saconnex or Satigny might be ten minutes away, and worth the trip.') }}</p>
        <div class="relative flex flex-wrap items-center justify-center gap-5">
            <div class="border-2 border-[#ec3013] px-[22px] py-4 text-sm font-extrabold text-[#ae1800]">{{ __('You') }}</div>
            <div class="border border-[#201e1d]/40 px-[18px] py-3.5 text-center">
                <p class="mb-1 text-sm font-extrabold">Vernier</p>
                <p class="text-xs opacity-55">10 min</p>
            </div>
            <div class="border border-[#201e1d]/40 px-[18px] py-3.5 text-center">
                <p class="mb-1 text-sm font-extrabold">Grand-Saconnex</p>
                <p class="text-xs opacity-55">12 min</p>
            </div>
            <div class="border border-[#201e1d]/40 px-[18px] py-3.5 text-center">
                <p class="mb-1 text-sm font-extrabold">Satigny</p>
                <p class="text-xs opacity-55">15 min</p>
            </div>
            <div class="border border-[#201e1d]/40 px-[18px] py-3.5 text-center">
                <p class="mb-1 text-sm font-extrabold">Carouge</p>
                <p class="text-xs opacity-55">18 min</p>
            </div>
        </div>
    </section>
    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- Save / reminder flow -->
    <section class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]">
        <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('After you find it') }}</span>
        <h2 class="mb-4 max-w-[24ch] text-[clamp(28px,3.6vw,42px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('Found something for Saturday? We\'ll help you remember it.') }}</h2>
        <p class="mb-10 max-w-[56ch] text-base leading-[1.65] opacity-80">{{ __('Discovering an event is only the beginning.') }}</p>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))]">
            <div class="border-b border-[#201e1d]/40 py-5 sm:border-b-0 sm:border-r sm:pr-5 sm:pt-0">
                <p class="mb-2 text-[13px] font-extrabold text-[#ae1800]">01</p>
                <p class="mb-2 text-[19px] font-extrabold">{{ __('Discover') }}</p>
                <p class="text-sm leading-[1.5] opacity-75">{{ __('Come across something worth doing') }}</p>
            </div>
            <div class="border-b border-[#201e1d]/40 py-5 sm:border-b-0 sm:border-r sm:px-5 sm:pt-0">
                <p class="mb-2 text-[13px] font-extrabold text-[#ae1800]">02</p>
                <p class="mb-2 text-[19px] font-extrabold">{{ __('Save') }}</p>
                <p class="text-sm leading-[1.5] opacity-75">{{ __('Keep it, so you don\'t lose it in a busy week') }}</p>
            </div>
            <div class="border-b border-[#201e1d]/40 py-5 sm:border-b-0 sm:border-r sm:px-5 sm:pt-0">
                <p class="mb-2 text-[13px] font-extrabold text-[#ae1800]">03</p>
                <p class="mb-2 text-[19px] font-extrabold">{{ __('Reminder') }}</p>
                <p class="text-sm leading-[1.5] opacity-75">{{ __('We nudge you before it starts') }}</p>
            </div>
            <div class="py-5 sm:pl-5 sm:pt-0">
                <p class="mb-2 text-[13px] font-extrabold text-[#ae1800]">04</p>
                <p class="mb-2 text-[19px] font-extrabold">{{ __('Go') }}</p>
                <p class="text-sm leading-[1.5] opacity-75">{{ __('Show up, plan made') }}</p>
            </div>
        </div>
    </section>
    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- Language experience -->
    <section class="mx-auto flex max-w-[1200px] flex-wrap items-center gap-[clamp(24px,5vw,56px)] px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]">
        <div class="min-w-[280px] flex-[1_1_380px]">
            <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('In your language') }}</span>
            <h2 class="mb-4 max-w-[22ch] text-[clamp(28px,3.6vw,40px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('Local life shouldn\'t depend on the language you speak.') }}</h2>
            <p class="mb-6 max-w-[48ch] text-base leading-[1.65] opacity-80">{{ __('The same event, presented naturally in the language you\'re comfortable with — try the toggle.') }}</p>
            <div aria-label="Preview language"
                class="inline-flex overflow-hidden border border-[#201e1d]/40"
                role="group">
                @foreach ($locales as $locale)
                    <button class="{{ app()->getLocale() === $locale ? 'bg-[#ec3013] text-[#f3f2f2] hover:bg-[#ec3013]' : '' }} border-l border-[#201e1d]/40 px-3 py-[7px] text-[13px] uppercase transition first:border-l-0 hover:bg-[#201e1d]/[0.07]"
                        type="button"
                        wire:click="switchLocale('{{ $locale }}')">
                        {{ strtoupper($locale) }}
                    </button>
                @endforeach
            </div>
        </div>
        <article class="w-full max-w-[320px] flex-[0_0_320px] border border-[#201e1d]/40 bg-[#eae9e9] p-[18px]">
            <p class="mb-2 text-[10px] uppercase tracking-[0.1em] text-[#ec3013]">Parc des Bastions</p>
            <h3 class="mb-2 text-[19px] font-extrabold">{{ __('Free concert, Parc des Bastions') }}</h3>
            <p class="text-sm leading-[1.55] opacity-80">{{ __('An open-air evening of music by the lake, open to everyone, no ticket required.') }}</p>
        </article>
    </section>
    <hr class="mx-auto h-0.5 max-w-[1200px] border-0 bg-[#201e1d]/40" />
    <!-- Standard event layout -->
    <section class="mx-auto flex max-w-[1200px] flex-wrap-reverse items-center gap-[clamp(24px,5vw,64px)] px-[clamp(20px,5vw,72px)] py-[clamp(48px,6vw,72px)]">
        <article class="w-full max-w-[320px] flex-[0_0_320px] border-2 border-[#201e1d]/40 p-[18px]">
            <p class="mb-2.5 text-[11px] uppercase tracking-[0.08em] text-[#ae1800]">Parc des Bastions</p>
            <h3 class="mb-3.5 text-[21px] font-extrabold">{{ __('Free concert, Parc des Bastions') }}</h3>
            <div class="flex flex-col gap-2 border-t border-[#201e1d]/40 pt-3 text-[13px]">
                <div class="flex justify-between gap-4">
                    <span class="opacity-55">{{ __('Date') }}</span>
                    <span class="text-right font-semibold">Fri 22 Aug</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="opacity-55">{{ __('Time') }}</span>
                    <span class="text-right font-semibold">20:00</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="opacity-55">{{ __('Location') }}</span>
                    <span class="text-right font-semibold">Parc des Bastions</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="opacity-55">{{ __('Commune') }}</span>
                    <span class="text-right font-semibold">Parc des Bastions</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="opacity-55">{{ __('Price') }}</span>
                    <span class="text-right font-semibold">{{ __('Free') }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="opacity-55">{{ __('Audience') }}</span>
                    <span class="text-right font-semibold">{{ __('All ages') }}</span>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button class="flex-1 bg-[#ec3013] px-[14px] py-2 text-sm font-extrabold text-[#f3f2f2] transition hover:bg-[#dd2b0f] active:bg-[#ae1800]"
                    type="button">{{ __('Save') }}</button>
                <button class="flex-1 border border-[#201e1d]/40 px-[14px] py-2 text-sm font-extrabold transition hover:bg-[#201e1d]/[0.07] active:bg-[#201e1d]/[0.14]"
                    type="button">{{ __('Remind me') }}</button>
            </div>
        </article>
        <div class="min-w-[280px] flex-[1_1_380px]">
            <span class="mb-3.5 block text-[13px] uppercase tracking-[0.08em] text-[#ae1800]">{{ __('Every event, laid out the same way') }}</span>
            <h2 class="mb-4 max-w-[20ch] text-[clamp(28px,3.6vw,40px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('One layout. Every time.') }}</h2>
            <p class="max-w-[48ch] text-base leading-[1.65] opacity-80">{{ __('Title, date, time, location, price, audience, organiser — always in the same place, however the source presented it.') }}</p>
        </div>
    </section>
    <!-- Closing CTA -->
    <section class="scroll-mt-20 bg-[#ec3013] py-[clamp(56px,8vw,96px)] text-[#f3f2f2]"
        id="comment">
        <div class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)]">
            <h2 class="mb-4 max-w-[20ch] text-[clamp(30px,4.4vw,52px)] font-extrabold leading-[1.1] tracking-[-0.015em]">{{ __('Your next plan might already be happening nearby.') }}</h2>
            <p class="mb-7 max-w-[44ch] text-[17px] leading-[1.6] opacity-90">{{ __('Discover events across Geneva, in one place.') }}</p>
            <div class="flex flex-wrap items-center gap-3">
                <button class="border-[1.5px] border-[#f3f2f2] px-[14px] py-2 text-sm font-extrabold text-[#f3f2f2] transition hover:bg-white/10 active:bg-white/20"
                    type="button">{{ __('Get the app') }}</button>
                <div class="flex flex-wrap gap-2.5">
                    <span class="border-[1.5px] border-[#f3f2f2] px-4 py-[9px] text-[13px] font-semibold">App Store</span>
                    <span class="border-[1.5px] border-[#f3f2f2] px-4 py-[9px] text-[13px] font-semibold">▶ Google Play</span>
                </div>
            </div>
        </div>
    </section>
    <footer class="mx-auto max-w-[1200px] px-[clamp(20px,5vw,72px)] py-6 text-[13px] opacity-55">{{ __('Loco-Locaux brings together event listings published by Geneva\'s communes.') }}</footer>
</div>
