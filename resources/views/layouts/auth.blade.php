{{-- Sign-in and first-run setup: the old (auth)/layout.tsx. --}}
@props(['title' => null])
<x-layouts::base :title="$title">
    <main class="flex min-h-dvh items-center justify-center bg-surface-sunken px-4 py-10">
        <div class="w-full max-w-md">{{ $slot }}</div>
    </main>
</x-layouts::base>
