<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('layouts.admin')] class extends Component {
    //
};
?>

<div class="relative flex h-full items-center justify-center">

    <flux:card class="max-w-150 relative z-10 dark:bg-zinc-900">

        <x-auth-header :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
            :title="__('Confirm password')" />

        <x-auth-session-status :status="session('status')"
            class="text-center" />

        <x-passkey-verify :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm" />

        <form action="{{ route('password.confirm.store') }}"
            class="flex flex-col gap-6"
            method="POST">
            @csrf

            <flux:input :label="__('Password')"
                :placeholder="__('Password')"
                autocomplete="current-password"
                name="password"
                required
                type="password"
                viewable />

            <flux:button data-test="confirm-password-button"
                type="submit"
                variant="primary">{{ __('Confirm') }}</flux:button>
        </form>
    </flux:card>

    <div class="grid-mask-radial absolute inset-0 [--grid-color:var(--color-zinc-200)] dark:[--grid-color:var(--color-zinc-700)]"></div>
</div>
