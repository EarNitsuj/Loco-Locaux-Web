<?php

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('layouts.admin')] #[Title('Admin Dashboard')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('admin.dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && !$user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return !$user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
};
?>

<div class="space-y-6">
    <flux:heading class="font-extrabold!"
        level="1"
        size="xl">Profile settings</flux:heading>

    <flux:card class="dark:bg-zinc-900">
        <flux:heading class="font-extrabold!"
            size="lg">{{ __('Personal information') }}</flux:heading>
        <flux:subheading>{{ __('Update your name and email address') }}</flux:subheading>

        <form class="mt-6 w-full space-y-6"
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
    </flux:card>

    @if ($this->showDeleteUser)
        <flux:card class="dark:bg-zinc-900">
            <livewire:settings.delete-user-form />
        </flux:card>
    @endif
</div>
