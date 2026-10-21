<section class="space-y-6">
    <div class="relative mb-5">
        <flux:heading class="font-extrabold!"
            size="lg">{{ __('Account deletion') }}</flux:heading>
        <flux:subheading>{{ __('Permanently delete your account and associated data') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger"
            x-data=""
            x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
            {{ __('Delete account') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal :show="$errors->isNotEmpty()"
        class="max-w-lg"
        focusable
        name="confirm-user-deletion">
        <form class="space-y-6"
            method="POST"
            wire:submit="deleteUser">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </flux:subheading>
            </div>

            <flux:input :label="__('Password')"
                type="password"
                viewable
                wire:model="password" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit"
                    variant="danger">{{ __('Delete account') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
