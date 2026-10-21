<flux:dropdown align="start"
    position="bottom">
    <flux:sidebar.profile :initials="auth()->user()->initials()"
        :name="auth()->user()->name"
        data-test="sidebar-menu-button"
        icon:trailing="chevrons-up-down" />

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar :initials="auth()->user()->initials()"
                :name="auth()->user()->name" />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('admin.profile')"
                icon:variant="outline"
                icon="user"
                wire:navigate>
                Profile
            </flux:menu.item>
            <flux:menu.item :href="route('admin.security')"
                icon:variant="outline"
                icon="shield-check"
                wire:navigate>
                Security
            </flux:menu.item>

            <flux:menu.separator />

            <div class="cursor-pointer p-3">
                <flux:switch align="left"
                    label="Dark mode"
                    x-data
                    x-model="$flux.dark" />
            </div>

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
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
