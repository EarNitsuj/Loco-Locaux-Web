<?php

use Livewire\Component;
use App\Concerns\PasswordValidationRules;
// use Exception;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;

new #[Layout('layouts.admin')] #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public bool $canManageTwoFactor;

    #[Locked]
    public bool $twoFactorEnabled;

    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showModal = false;

    public bool $showVerificationStep = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    #[Locked]
    public bool $canManagePasskeys;

    /**
     * @var array<int, array{id: int, name: string, authenticator: string|null, created_at_diff: string, last_used_at_diff: string|null}>
     */
    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = Auth::user()
            ->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(
                fn($passkey) => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'authenticator' => $passkey->authenticator,
                    'created_at_diff' => $passkey->created_at->diffForHumans(),
                    'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                ],
            )
            ->all();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = Auth::user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (!$this->deletingPasskeyId) {
            return;
        }

        $user = Auth::user();
        $passkey = $user->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey($user, $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Enable two-factor authentication for the user.
     */
    public function enable(EnableTwoFactorAuthentication $enableTwoFactorAuthentication): void
    {
        $enableTwoFactorAuthentication(auth()->user());

        if (!$this->requiresConfirmation) {
            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
        }

        $this->loadSetupData();

        $this->showModal = true;
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = auth()->user();

        try {
            $this->qrCodeSvg = $user?->twoFactorQrCodeSvg();
            $this->manualSetupKey = decrypt($user->two_factor_secret);
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $confirmTwoFactorAuthentication(auth()->user(), $this->code);

        $this->closeModal();

        $this->twoFactorEnabled = true;
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset('code', 'manualSetupKey', 'qrCodeSvg', 'showModal', 'showVerificationStep');

        $this->resetErrorBag();

        if (!$this->requiresConfirmation) {
            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
        }
    }

    /**
     * Get the current modal configuration state.
     *
     * @return array{title: string, description: string, buttonText: string}
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->twoFactorEnabled) {
            return [
                'title' => __('Two-factor authentication enabled'),
                'description' => __('Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.'),
                'buttonText' => __('Close'),
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => __('Verify authentication code'),
                'description' => __('Enter the 6-digit code from your authenticator app.'),
                'buttonText' => __('Continue'),
            ];
        }

        return [
            'title' => __('Enable two-factor authentication'),
            'description' => __('To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.'),
            'buttonText' => __('Continue'),
        ];
    }
};
?>

<div class="space-y-6">

    <flux:heading class="font-extrabold!"
        level="1"
        size="xl">Security settings</flux:heading>

    <flux:card class="dark:bg-zinc-900">
        <flux:heading class="font-extrabold!"
            size="lg">{{ __('Update password') }}</flux:heading>
        <flux:subheading>{{ __('Ensure your account is using a long, random password to stay secure') }}</flux:subheading>

        <form class="mt-6 space-y-6"
            method="POST"
            wire:submit="updatePassword">
            <flux:input :label="__('Current password')"
                autocomplete="current-password"
                required
                type="password"
                viewable
                wire:model="current_password" />
            <flux:input :label="__('New password')"
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                required
                type="password"
                viewable
                wire:model="password" />
            <flux:input :label="__('Confirm password')"
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                required
                type="password"
                viewable
                wire:model="password_confirmation" />

            <div class="flex items-center gap-4">
                <flux:button data-test="update-password-button"
                    type="submit"
                    variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:card>

    @if ($canManageTwoFactor)
        <flux:card class="dark:bg-zinc-900">
            <flux:heading class="font-extrabold!"
                size="lg">{{ __('Two-factor authentication') }}</flux:heading>
            <flux:subheading>{{ __('Manage your two-factor authentication settings') }}</flux:subheading>

            <div class="mx-auto flex w-full flex-col text-sm"
                wire:cloak>
                @if ($twoFactorEnabled)
                    <div class="space-y-4">
                        <flux:text>
                            {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                        </flux:text>

                        <div class="flex justify-start">
                            <flux:button variant="danger"
                                wire:click="disable">
                                {{ __('Disable 2FA') }}
                            </flux:button>
                        </div>

                        <livewire:settings.two-factor.recovery-codes :$requiresConfirmation />
                    </div>
                @else
                    <div class="space-y-4">
                        <flux:text class="mt-6"
                            variant="subtle">
                            {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                        </flux:text>

                        <flux:button variant="primary"
                            wire:click="enable">
                            {{ __('Enable 2FA') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </flux:card>
    @endif

    @if ($canManageTwoFactor)
        <flux:modal @close="closeModal"
            class="md:min-w-md max-w-md"
            name="two-factor-setup-modal"
            wire:model="showModal">
            <div class="space-y-6">
                <div class="flex flex-col items-center space-y-4">
                    <div class="w-auto rounded-full border border-stone-100 bg-white p-0.5 shadow-sm dark:border-stone-600 dark:bg-stone-800">
                        <div class="relative overflow-hidden rounded-full border border-stone-200 bg-stone-100 p-2.5 dark:border-stone-600 dark:bg-stone-200">
                            <div class="absolute inset-0 flex h-full w-full items-stretch justify-around divide-x divide-stone-200 opacity-50 dark:divide-stone-300 [&>div]:flex-1">
                                @for ($i = 1; $i <= 5; $i++)
                                    <div></div>
                                @endfor
                            </div>

                            <div class="absolute inset-0 flex h-full w-full flex-col items-stretch justify-around divide-y divide-stone-200 opacity-50 dark:divide-stone-300 [&>div]:flex-1">
                                @for ($i = 1; $i <= 5; $i++)
                                    <div></div>
                                @endfor
                            </div>

                            <flux:icon.qr-code class="dark:text-accent-foreground relative z-20" />
                        </div>
                    </div>

                    <div class="space-y-2 text-center">
                        <flux:heading size="lg">{{ $this->modalConfig['title'] }}</flux:heading>
                        <flux:text>{{ $this->modalConfig['description'] }}</flux:text>
                    </div>
                </div>

                @if ($showVerificationStep)
                    <div class="space-y-6">
                        <div class="flex flex-col items-center justify-center space-y-3"
                            x-data
                            x-init="$nextTick(() => $el.querySelector('input')?.focus())">
                            <flux:otp class="mx-auto"
                                label:sr-only
                                label="OTP Code"
                                length="6"
                                name="code"
                                wire:model="code" />
                        </div>

                        <div class="flex items-center space-x-3">
                            <flux:button class="flex-1"
                                variant="outline"
                                wire:click="resetVerification">
                                {{ __('Back') }}
                            </flux:button>

                            <flux:button class="flex-1"
                                variant="primary"
                                wire:click="confirmTwoFactor"
                                x-bind:disabled="$wire.code.length < 6">
                                {{ __('Confirm') }}
                            </flux:button>
                        </div>
                    </div>
                @else
                    @error('setupData')
                        <flux:callout heading="{{ $message }}"
                            icon="x-circle"
                            variant="danger" />
                    @enderror

                    <div class="flex justify-center">
                        <div class="relative aspect-square w-64 overflow-hidden rounded-lg border border-stone-200 dark:border-stone-700">
                        @empty($qrCodeSvg)
                            <div class="absolute inset-0 flex animate-pulse items-center justify-center bg-white dark:bg-stone-700">
                                <flux:icon.loading />
                            </div>
                        @else
                            <div class="flex h-full items-center justify-center p-4"
                                x-data>
                                <div :style="($flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)) ? 'filter: invert(1) brightness(1.5)' : ''"
                                    class="rounded bg-white p-3">
                                    {!! $qrCodeSvg !!}
                                </div>
                            </div>
                        @endempty
                    </div>
                </div>

                <div>
                    <flux:button :disabled="$errors->has('setupData')"
                        class="w-full"
                        variant="primary"
                        wire:click="showVerificationIfNecessary">
                        {{ $this->modalConfig['buttonText'] }}
                    </flux:button>
                </div>

                <div class="space-y-4">
                    <div class="relative flex w-full items-center justify-center">
                        <div class="absolute inset-0 top-1/2 h-px w-full bg-stone-200 dark:bg-stone-600"></div>
                        <span class="relative bg-white px-2 text-sm text-stone-600 dark:bg-stone-800 dark:text-stone-400">
                            {{ __('or, enter the code manually') }}
                        </span>
                    </div>

                    <div class="flex items-center space-x-2"
                        x-data="{
                            copied: false,
                            async copy() {
                                try {
                                    await navigator.clipboard.writeText('{{ $manualSetupKey }}');
                                    this.copied = true;
                                    setTimeout(() => this.copied = false, 1500);
                                } catch (e) {
                                    console.warn('Could not copy to clipboard');
                                }
                            }
                        }">
                        <div class="flex w-full items-stretch rounded-xl border dark:border-stone-700">
                        @empty($manualSetupKey)
                            <div class="flex w-full items-center justify-center bg-stone-100 p-3 dark:bg-stone-700">
                                <flux:icon.loading variant="mini" />
                            </div>
                        @else
                            <input class="w-full p-3 text-stone-900 outline-none dark:bg-zinc-900 dark:text-stone-100"
                                readonly
                                type="text"
                                value="{{ $manualSetupKey }}" />

                            <button @click="copy()"
                                class="cursor-pointer border-l border-stone-200 px-3 transition-colors dark:border-stone-600">
                                <flux:icon.document-duplicate variant="outline"
                                    x-show="!copied"></flux:icon>
                                    <flux:icon.check class="text-green-500"
                                        variant="solid"
                                        x-show="copied">
                                        </flux:icon>
                            </button>
                        @endempty
                    </div>
                </div>
            </div>
        @endif
    </div>
</flux:modal>
@endif

@if ($canManagePasskeys)
<flux:card class="dark:bg-zinc-900">
    <flux:heading class="font-extrabold!"
        size="lg">{{ __('Passkeys') }}</flux:heading>
    <flux:subheading>{{ __('Manage your passkeys for passwordless sign-in') }}</flux:subheading>

    <div class="mx-auto mt-6 flex w-full flex-col space-y-6 text-sm"
        wire:cloak>
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            @forelse ($passkeys as $passkey)
                <div class="{{ !$loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }} flex items-center justify-between p-4">
                    <div class="flex items-center gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon.key class="size-5 text-zinc-500 dark:text-zinc-400" />
                        </div>
                        <div class="space-y-1">
                            <div class="flex items-center gap-2.5">
                                <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                @if ($passkey['authenticator'])
                                    <flux:badge size="sm">{{ $passkey['authenticator'] }}</flux:badge>
                                @endif
                            </div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                @if ($passkey['last_used_at_diff'])
                                    <span class="mx-1 opacity-50">/</span>
                                    {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <flux:button class="text-red-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/50"
                        icon:variant="outline"
                        icon="trash"
                        size="sm"
                        variant="ghost"
                        wire:click="confirmDelete({{ $passkey['id'] }})" />
                </div>
            @empty
                <div class="p-8 text-center">
                    <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                        <flux:icon.key class="size-7 text-zinc-400 dark:text-zinc-500" />
                    </div>
                    <p class="font-medium">{{ __('No passkeys yet') }}</p>
                    <flux:text class="mt-1">{{ __('Add a passkey to sign in without a password') }}</flux:text>
                </div>
            @endforelse
        </div>

        <x-passkey-registration />
    </div>
</flux:card>
@endif

<flux:modal @close="closeDeleteModal"
class="md:min-w-md max-w-md"
name="delete-passkey-modal"
wire:model="showDeleteModal">
<div class="space-y-6">
    <div class="space-y-2">
        <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
        <flux:text>
            {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
        </flux:text>
    </div>

    <div class="flex justify-end gap-3">
        <flux:button variant="outline"
            wire:click="closeDeleteModal">
            {{ __('Cancel') }}
        </flux:button>
        <flux:button variant="danger"
            wire:click="deletePasskey">
            {{ __('Remove passkey') }}
        </flux:button>
    </div>
</div>
</flux:modal>
</div>
