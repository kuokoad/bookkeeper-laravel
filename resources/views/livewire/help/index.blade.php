{{-- The old (app)/help page. --}}
<div class="mx-auto max-w-3xl">
    <x-page-header title="Help" description="How to use the shop's books. Stored on this machine, so it works without internet." />

    <div class="grid gap-3">
        @foreach ($pages as $slug => [$title, $blurb])
            <a href="/help/{{ $slug }}" class="block">
                <x-card class="h-full transition-colors hover:bg-surface-sunken">
                    <p class="font-medium text-accent">{{ $title }}</p>
                    <p class="mt-1 text-sm text-content-muted">{{ $blurb }}</p>
                </x-card>
            </a>
        @endforeach
    </div>

    <p class="mt-6 text-xs text-content-subtle">These pages are the same ones kept with the software, so what you read here matches the version you are running.</p>
</div>
