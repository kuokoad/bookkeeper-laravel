{{--
    Sign in: the old (auth)/login page and login-form.tsx. Two ways in, one
    account: the till PIN for the counter machine, the password everywhere
    else. The tabs are links (?mode=pin), so it works with no JavaScript.
--}}
<x-layouts::auth title="Sign in">
    <div class="rounded-2xl border border-line bg-surface-raised p-8 shadow-sm">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-semibold text-content">{{ $shopName }}</h1>
            <p class="mt-1 text-sm text-content-muted">Sign in to continue</p>
        </div>

        @if ($setupComplete)
            <x-alert tone="success" class="mb-6">Setup complete. Sign in with the owner account you just created.</x-alert>
        @endif
        {{-- Shown by keep-form.js when a form was kept as the sign-in ended. --}}
        <div data-kept-form-note hidden>
            <x-alert tone="warning" class="mb-6">You were signed out before your last save went through. Sign in again and what you typed will be put back.</x-alert>
        </div>

        <div role="tablist" aria-label="How to sign in" class="mb-6 grid grid-cols-2 gap-1 rounded-lg border border-line bg-surface-sunken p-1">
            @foreach (['password' => 'Password', 'pin' => 'Till PIN'] as $value => $label)
                <a role="tab" href="{{ $value === 'pin' ? '/login?mode=pin' : '/login' }}" aria-selected="{{ $mode === $value ? 'true' : 'false' }}"
                   @class(['rounded-md px-3 py-2 text-center text-sm font-medium',
                       'bg-surface-raised text-content shadow-sm' => $mode === $value,
                       'text-content-muted hover:text-content' => $mode !== $value])>{{ $label }}</a>
            @endforeach
        </div>

        @if ($mode === 'password')
            <form method="POST" action="/login" class="space-y-5" novalidate>
                @csrf
                @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
                <x-field label="Username" for="username" required>
                    <x-input id="username" name="username" :value="old('username')" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus required :invalid="$errors->has('username')" />
                </x-field>
                <x-field label="Password" for="password" required>
                    <x-input id="password" name="password" type="password" autocomplete="current-password" required :invalid="$errors->has('password')" />
                </x-field>
                <x-button type="submit" size="lg" class="w-full">Sign in</x-button>
            </form>
        @else
            <form method="POST" action="/login/pin" class="space-y-5" novalidate>
                @csrf
                @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
                <x-field label="Username" for="pin-username" name="username" required>
                    <x-input id="pin-username" name="username" :value="old('username')" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus required :invalid="$errors->has('username')" />
                </x-field>
                {{-- A numeric keypad on the touchscreen at the counter: the whole point of a PIN. --}}
                <x-field label="Till PIN" for="pin" required>
                    <x-input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="8" required :invalid="$errors->has('pin')" />
                </x-field>
                <x-button type="submit" size="lg" class="w-full">Sign in with PIN</x-button>
            </form>
        @endif

        @if ($demo)
            <x-alert tone="warning" title="Demo data is loaded" class="mt-6">
                <ul class="mt-1 space-y-1">
                    <li>Owner — <code class="font-mono font-medium">{{ $demo['owner']['username'] }}</code> / <code class="font-mono font-medium">{{ $demo['owner']['password'] }}</code></li>
                    <li>Staff — <code class="font-mono font-medium">{{ $demo['staff']['username'] }}</code> / <code class="font-mono font-medium">{{ $demo['staff']['password'] }}</code></li>
                    <li>Till PIN — <code class="font-mono font-medium">{{ $demo['staff']['username'] }}</code> / <code class="font-mono font-medium">{{ $demo['staff']['pin'] }}</code>, on the <span class="font-medium">Till PIN</span> tab</li>
                </ul>
                <p class="mt-2">This panel appears only while the database contains demo records, and never in production. Run <code class="font-mono">php artisan migrate:fresh --seed</code> without demo data before using this for a real shop.</p>
            </x-alert>
        @endif
    </div>
</x-layouts::auth>
