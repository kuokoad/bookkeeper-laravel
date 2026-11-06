{{--
    The old shared/theme-switch.tsx: two forms, only one visible. CSS decides
    which (.theme-when-* in app.css), because under "match my device" only the
    browser knows whether the screen is dark. No JavaScript either way.
--}}
@php
    $track = 'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-colors';
    $knob = 'absolute h-4.5 w-4.5 rounded-full bg-surface-raised shadow-sm transition-transform';
@endphp
<div class="flex items-center gap-2">
    <form method="POST" action="/theme" class="theme-when-light inline-flex">
        @csrf
        <input type="hidden" name="theme" value="dark">
        <button type="submit" role="switch" aria-checked="false" aria-label="Dark mode" title="Switch to dark" class="{{ $track }} border-line-strong bg-surface-sunken">
            <span class="{{ $knob }} left-0.5"></span>
        </button>
    </form>
    <form method="POST" action="/theme" class="theme-when-dark">
        @csrf
        <input type="hidden" name="theme" value="light">
        <button type="submit" role="switch" aria-checked="true" aria-label="Dark mode" title="Switch to light" class="{{ $track }} border-accent bg-accent">
            <span class="{{ $knob }} right-0.5"></span>
        </button>
    </form>
</div>
