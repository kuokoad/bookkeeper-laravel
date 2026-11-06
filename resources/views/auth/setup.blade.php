{{-- First run: the old (auth)/setup page and setup-form.tsx. --}}
<x-layouts::auth title="Set up your shop">
    <div class="rounded-2xl border border-line bg-surface-raised p-8 shadow-sm">
        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-content">Welcome</h1>
            <p class="mt-2 text-sm text-content-muted">Let’s set up your shop and create your owner account. This happens once.</p>
        </div>

        <form method="POST" action="/setup" class="space-y-5" novalidate>
            @csrf
            @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

            <x-field label="Shop name" for="businessName" required hint="Appears on receipts and reports. You can change it later in Settings.">
                <x-input id="businessName" name="businessName" :value="old('businessName')" autofocus required :invalid="$errors->has('businessName')" />
            </x-field>

            <fieldset>
                <legend class="mb-1 block text-sm font-medium text-content">What kind of shop is it?</legend>
                <p class="mb-3 text-sm text-content-muted">You can change this later in Settings. It only decides what you are shown — nothing you record is ever hidden by it.</p>
                <div class="space-y-2">
                    @foreach ($types as $type)
                        <label class="flex cursor-pointer gap-3 rounded-xl border border-line p-3 has-checked:border-accent has-checked:bg-accent-soft">
                            <input type="radio" name="businessType" value="{{ $type->value }}" @checked(old('businessType', 'general_retail') === $type->value) class="mt-1 h-4 w-4 shrink-0 accent-[var(--accent)]">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-content">{{ $type->label() }}</span>
                                <span class="mt-0.5 block text-xs text-content-muted">{{ $type->blurb() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-field label="Your name" for="displayName" required>
                <x-input id="displayName" name="displayName" :value="old('displayName')" autocomplete="name" required :invalid="$errors->has('displayName')" />
            </x-field>
            <x-field label="Username" for="username" required hint="What you will type to sign in.">
                <x-input id="username" name="username" :value="old('username')" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required :invalid="$errors->has('username')" />
            </x-field>
            <x-field label="Password" for="password" required hint="At least 8 characters. This protects your money records — do not share it.">
                <x-input id="password" name="password" type="password" autocomplete="new-password" required :invalid="$errors->has('password')" />
            </x-field>
            <x-field label="Confirm password" for="confirmPassword" required>
                <x-input id="confirmPassword" name="confirmPassword" type="password" autocomplete="new-password" required :invalid="$errors->has('confirmPassword')" />
            </x-field>

            <x-button type="submit" size="lg" class="w-full">Create owner account</x-button>
        </form>
    </div>
</x-layouts::auth>
