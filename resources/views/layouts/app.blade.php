<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

    <head>
        <meta charset="utf-8">
        <meta content="width=device-width, initial-scale=1.0"
            name="viewport">

        <title>{{ $title ?? config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance

        @livewireStyles
    </head>

    <body>

        <nav class="fixed z-20 w-screen bg-white dark:bg-zinc-900">
            <div class="max-w-295 mx-auto flex items-center justify-between px-4 py-4 sm:px-6">
                <a class="w-64"
                    href="{{ url('/') }}"
                    wire:navigate>
                    <img alt="Logo"
                        class="w-full"
                        src="{{ asset('assets/logo-light-mode.svg') }}">
                </a>

                <div class="inline-flex items-center gap-2">
                    <flux:button aria-label="Toggle dark mode"
                        square
                        variant="subtle"
                        x-data
                        x-on:click="$flux.dark = ! $flux.dark">
                        <flux:icon.moon variant="mini"
                            x-show="! $flux.dark" />

                        <flux:icon.sun variant="mini"
                            x-show="$flux.dark" />
                    </flux:button>
                    @php
                        $currentRoute = request()->route();
                        $currentRouteName = $currentRoute?->getName();
                        $currentParameters = $currentRoute?->parameters() ?? [];
                    @endphp

                    <flux:dropdown>
                        <flux:button icon:trailing="chevron-down"
                            variant="ghost">
                            {{ strtoupper(app()->getLocale()) }}
                        </flux:button>

                        <flux:menu>
                            @foreach (['en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch', 'it' => 'Italiano', 'es' => 'Español', 'pt' => 'Português'] as $locale => $label)
                                @php
                                    $parameters = array_merge($currentParameters, [
                                        'locale' => $locale,
                                    ]);

                                    $languageUrl = $currentRouteName ? route($currentRouteName, $parameters) : route('home', ['locale' => $locale]);
                                @endphp

                                <flux:menu.item :href="$languageUrl"
                                    :icon="app()->getLocale() === $locale ? 'check' : null">
                                    {{ $label }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>

            </div>
        </nav>

        <div class="border-t-6 border-zinc-100 dark:border-zinc-800">
            {{ $slot }}
        </div>

        @fluxScripts
        @livewireScripts
    </body>

</html>
