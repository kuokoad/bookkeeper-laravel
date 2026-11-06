{{--
    The document every screen shares: the old app/layout.tsx. The theme (a
    cookie on this screen) and the look (the shop's setting) are written onto
    <html> before the page is sent, so the right palette is there from the
    first paint with no JavaScript. Every combination is painted in app.css.
--}}
@props(['title' => null])
@php($root = \App\View\Shell::root())
<!DOCTYPE html>
<html lang="en" @if ($root['theme']) data-theme="{{ $root['theme'] }}" @endif @if ($root['look']) data-look="{{ $root['look'] }}" @endif>
<head>
    <meta charset="utf-8">
    {{-- Staff need to zoom into a receipt; never disable it. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Bookkeeping, inventory, sales and accounts for a small retail shop.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · NunaBooks' : 'NunaBooks' }}</title>
    <link rel="icon" href="/brand/nunabooks-mark.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh bg-surface text-content antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
