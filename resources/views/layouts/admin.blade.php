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

    <body class="min-h-screen antialiased">

        <flux:sidebar class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
            collapsible="mobile"
            sticky>
            <flux:sidebar.header>

                <a href="{{ route('admin.dashboard') }}"
                    wire:navigate>
                    <img alt="Logo"
                        class="w-full"
                        src="{{ asset('assets/logo-light-mode.svg') }}">
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')"
                    class="grid">
                    <flux:sidebar.item :current="request()->routeIs('admin.dashboard')"
                        :href="route('admin.dashboard')"
                        icon="home"
                        wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item href="https://github.com/laravel/livewire-starter-kit"
                    icon="folder-git-2"
                    target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item href="https://laravel.com/docs/starter-kits#livewire"
                    icon="book-open-text"
                    target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu :name="auth()->user()->name"
                class="hidden lg:block" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden"
                icon="bars-2"
                inset="left" />

            <flux:spacer />

            <flux:dropdown align="end"
                position="top">
                <flux:profile :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down" />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar :initials="auth()->user()->initials()"
                                    :name="auth()->user()->name" />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')"
                            icon="cog"
                            wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form action="{{ route('logout') }}"
                        class="w-full"
                        method="POST">
                        @csrf
                        <flux:menu.item as="button"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                            icon="arrow-right-start-on-rectangle"
                            type="submit">
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:main>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
        @livewireScripts
    </body>

</html>
