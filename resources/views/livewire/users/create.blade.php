{{-- The old (app)/users/new page and new-user-form.tsx. --}}
<div class="mx-auto max-w-4xl">
    <x-page-header title="Add a user" :description="$isOwner ? 'Someone who will sign in — a shop assistant, a manager, or a second owner.' : 'Someone who will sign in. You can give them any of the access you have yourself.'" />

    <form wire:submit="save" class="space-y-6" novalidate>
        @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Their name" for="displayName" required>
                    <x-input id="displayName" wire:model="displayName" autocomplete="name" required autofocus :invalid="$errors->has('displayName')" />
                </x-field>
                <x-field label="Username" for="username" hint="What they type to sign in." required>
                    <x-input id="username" wire:model="username" autocapitalize="none" autocorrect="off" spellcheck="false" required :invalid="$errors->has('username')" />
                </x-field>
                <x-field label="Starting password" for="password" hint="They must choose their own the first time they sign in." required>
                    <x-input id="password" type="text" wire:model="password" autocomplete="off" required :invalid="$errors->has('password')" />
                </x-field>
                <x-field label="Till PIN (optional)" for="pin" hint="4–8 digits, for quickly switching at the till.">
                    <x-input id="pin" wire:model="pin" inputmode="numeric" autocomplete="off" placeholder="e.g. 8351" />
                </x-field>
            </div>
        </div>

        {{-- Only an owner can make another owner, so nobody else is offered the choice. --}}
        @if ($isOwner)
            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <h2 class="mb-3 text-sm font-semibold text-content">What kind of account?</h2>
                <div class="space-y-3">
                    <label class="flex items-start gap-3">
                        <input type="radio" value="STAFF" wire:model.live="role" class="mt-1 h-4 w-4">
                        <span class="text-sm text-content">Staff<span class="mt-0.5 block text-xs text-content-subtle">Can only do what you tick below. This is the right choice for almost everyone.</span></span>
                    </label>
                    <label class="flex items-start gap-3">
                        <input type="radio" value="OWNER" wire:model.live="role" class="mt-1 h-4 w-4">
                        <span class="text-sm text-content">Owner<span class="mt-0.5 block text-xs text-content-subtle">Full access to everything, including money, settings, closing the books and other people&rsquo;s accounts. Give this only to someone who owns the business.</span></span>
                    </label>
                </div>
                @if ($role === 'OWNER')
                    <x-alert tone="warning" class="mt-4">An owner can do anything, including changing what everyone else can do and reopening closed periods. There is no way to limit an owner.</x-alert>
                @endif
            </div>
        @endif

        @if ($role === 'STAFF')
            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <h2 class="mb-1 text-sm font-semibold text-content">What are they allowed to do?</h2>
                <p class="mb-4 text-sm text-content-muted">Start from a preset and adjust. You can change this at any time.</p>
                @include('livewire.users.partials.matrix', ['disabled' => false])
            </div>
        @endif

        <div class="flex items-center gap-3">
            <x-button type="submit" size="lg" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Create the account</span><span wire:loading wire:target="save">Creating…</span>
            </x-button>
            <x-button variant="ghost" href="/users">Cancel</x-button>
        </div>
    </form>
</div>
