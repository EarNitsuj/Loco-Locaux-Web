<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Admin Dashboard')] class extends Component {
    //
};
?>

<div class="space-y-8">

    <div class="relative mb-6 w-full">
        <flux:heading level="1"
            size="xl">{{ __('Settings') }}</flux:heading>
        <flux:subheading class="mb-6"
            size="lg">{{ __('Manage your profile and account settings') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div>
        <h1 class="text-3xl font-bold tracking-tight">
            Dashboard
        </h1>

        <p class="mt-2 text-sm text-zinc-500">
            Welcome back, {{ auth()->user()->name }}.
        </p>
    </div>

    <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">

        <div class="rounded-xl border border-zinc-200 bg-white p-6">
            <div class="text-sm font-medium text-zinc-500">
                Events
            </div>

            <div class="mt-2 text-3xl font-bold">
                —
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-6">
            <div class="text-sm font-medium text-zinc-500">
                Communes
            </div>

            <div class="mt-2 text-3xl font-bold">
                —
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-6">
            <div class="text-sm font-medium text-zinc-500">
                Users
            </div>

            <div class="mt-2 text-3xl font-bold">
                —
            </div>
        </div>

    </div>

</div>
