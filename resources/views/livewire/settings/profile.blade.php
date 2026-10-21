<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')"
        :subheading="__('Update your name and email address')">
        <form class="my-6 w-full space-y-6"
            wire:submit="updateProfileInformation">
            <flux:input :label="__('Name')"
                autocomplete="name"
                autofocus
                required
                type="text"
                wire:model="name" />

            <div>
                <flux:input :label="__('Email')"
                    autocomplete="email"
                    required
                    type="email"
                    wire:model="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="cursor-pointer text-sm"
                                wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <flux:button type="submit"
                    variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
