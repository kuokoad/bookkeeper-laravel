{{-- Change your own password: the old (account)/account/password page. --}}
<x-layouts::account title="Change your password" :shopName="$shopName">
    <div class="mx-auto max-w-lg">
        <x-page-header title="Change your password" :description="'Signed in as '.$user->display_name" />

        @if ($required)
            <x-alert tone="warning" title="Please choose your own password" class="mb-4">
                Your password was set by someone else. Choose one only you know before carrying on.
            </x-alert>
        @endif

        <form method="POST" action="/account/password" class="space-y-5" novalidate>
            @csrf
            @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
            <x-field label="Your current password" for="currentPassword" required>
                <x-input id="currentPassword" name="currentPassword" type="password" autocomplete="current-password" required autofocus :invalid="$errors->has('currentPassword')" />
            </x-field>
            <x-field label="New password" for="newPassword" required hint="At least 8 characters. This protects the shop's money records.">
                <x-input id="newPassword" name="newPassword" type="password" autocomplete="new-password" required :invalid="$errors->has('newPassword')" />
            </x-field>
            <x-field label="New password again" for="confirmPassword" required>
                <x-input id="confirmPassword" name="confirmPassword" type="password" autocomplete="new-password" required :invalid="$errors->has('confirmPassword')" />
            </x-field>
            <x-button type="submit" size="lg" class="w-full">Change my password</x-button>
        </form>

        <p class="mt-4 text-xs text-content-subtle">Changing your password signs out every device, including this one, so you will sign in again with the new password.</p>
    </div>
</x-layouts::account>
