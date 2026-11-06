{{-- The old (app)/settings/health page: backup status and download, updating, and the readiness checks. --}}
@use('App\View\Format')
@php
    $tones = ['pass' => 'success', 'warn' => 'warning', 'fail' => 'danger'];
    $labels = ['pass' => 'OK', 'warn' => 'Check', 'fail' => 'Problem'];
    $changes = ($check['ok'] ?? false) ? $check['changes'] : [];
@endphp
<div class="mx-auto max-w-3xl">
    <x-page-header title="Health & backup" description="Whether this shop is safe to trade on, and how to take a copy of your records.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/settings">Settings</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card class="mb-6">
        <h2 class="text-sm font-semibold text-content">Back up your records</h2>
        <p class="mt-2 text-sm text-content-muted">Everything your shop has ever recorded is in one database. This makes a fresh copy, checks it opens and that the books inside it balance, then sends it to your computer.</p>
        <p class="mt-2 text-sm text-content-muted">Do this at the end of each day, and keep the file somewhere other than this computer — a copy that lives beside the original does not survive the original being lost.</p>

        <div class="mt-4">
            <x-alert :tone="$backup['state'] === 'current' ? 'info' : ($backup['state'] === 'due' ? 'warning' : 'danger')" :title="$backup['state'] === 'current' ? 'Up to date' : 'Backup needed'">
                {{ $backupLine }}
                @if ($backup['lastTakenAt'])
                    <span class="mt-1 block text-xs text-content-subtle">Last taken {{ Format::dateTime($backup['lastTakenAt']) }}.</span>
                @endif
            </x-alert>
        </div>

        @if ($canBackUp)
            {{-- A plain form, not Livewire: the browser downloads the response. --}}
            <form method="POST" action="/settings/backup" class="mt-4">
                @csrf
                <x-button type="submit">Download a backup</x-button>
            </form>
        @else
            <p class="mt-4 text-sm text-content-subtle">Only the shop owner can take a backup — it contains every customer and every figure.</p>
        @endif
    </x-card>

    <x-card class="mb-6">
        <h2 class="text-sm font-semibold text-content">Update the app</h2>
        <p class="mt-2 text-sm text-content-muted">{{ $version ? "This computer is running version {$version['hash']}, from {$version['date']}." : 'The installed version could not be read.' }}</p>

        @if ($updating)
            {{-- The app goes down mid-update, so failed requests are expected; reload once the updater reports back. --}}
            <div x-data x-init="setInterval(async () => { try { const r = await fetch('/settings/update', { cache: 'no-store', headers: { Accept: 'application/json' } }); if (! r.ok) return; const s = await r.json(); if (s && s.state !== 'running') window.location.reload(); } catch (e) {} }, 5000)">
                <x-alert tone="info" title="Updating" class="mt-4">A backup is taken first, then the app stops, installs the new version and starts again. This usually takes a few minutes. Leave this page open; it reloads when the app is back.</x-alert>
            </div>
        @elseif ($lastStatus)
            <x-alert :tone="$lastStatus['state'] === 'done' ? 'success' : 'danger'" :title="$lastStatus['state'] === 'done' ? 'Last update installed' : 'Last update failed'" class="mt-4">{{ $lastStatus['message'] }}</x-alert>
        @endif

        @if (! $updating && ! $isOwner)
            <p class="mt-4 text-sm text-content-subtle">Only the shop owner can update the app.</p>
        @endif
        @if (! $updating && $isOwner && $unsupportedReason)
            <p class="mt-4 text-sm text-content-subtle">{{ $unsupportedReason }}</p>
        @endif

        @if (! $updating && $isOwner && ! $unsupportedReason)
            <div class="mt-4 space-y-4">
                @if ($checkError)<x-alert tone="danger">{{ $checkError }}</x-alert>@endif
                @if ($check && ! $check['ok'])<x-alert tone="warning">{{ $check['reason'] }}</x-alert>@endif
                @if ($check && $check['ok'] && $changes === [])<x-alert tone="success">This is the newest version.</x-alert>@endif
                @if ($startError)<x-alert tone="danger">{{ $startError }}</x-alert>@endif

                @if ($changes !== [])
                    <div>
                        <p class="text-sm font-medium text-content">{{ count($changes) }} change{{ count($changes) === 1 ? '' : 's' }} available:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-content-muted">
                            @foreach ($changes as $change)<li>{{ $change['subject'] }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @if ($changes !== [] && $confirming)
                    <x-alert tone="warning" title="Stop trading first">
                        Every till will be unavailable for a few minutes while the update installs. Finish any sale in progress, then continue. A backup is taken before anything changes.
                        <div class="mt-3 flex gap-2">
                            <x-button size="sm" wire:click="startUpdate" wire:loading.attr="disabled" wire:target="startUpdate">Yes, update now</x-button>
                            <x-button size="sm" variant="secondary" wire:click="$set('confirming', false)">Not now</x-button>
                        </div>
                    </x-alert>
                @else
                    <div class="flex gap-2">
                        <x-button size="sm" :variant="$changes !== [] ? 'secondary' : 'primary'" wire:click="checkForUpdates" wire:loading.attr="disabled" wire:target="checkForUpdates">
                            <span wire:loading.remove wire:target="checkForUpdates">Check for updates</span><span wire:loading wire:target="checkForUpdates">Checking…</span>
                        </x-button>
                        @if ($changes !== [])
                            <x-button size="sm" wire:click="$set('confirming', true)">Update now…</x-button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </x-card>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="check" label="Checks passed" :value="(string) (count($checks) - $failures - $warnings)" />
        <x-stat icon="warning" label="Problems" :value="(string) $failures" :tone="$failures > 0 ? 'danger' : 'default'" />
        <x-stat icon="warning" label="Worth checking" :value="(string) $warnings" :tone="$warnings > 0 ? 'warning' : 'default'" />
    </div>

    @if ($failures > 0)
        <x-alert tone="danger" title="Do not trade on this yet" class="mb-4">{{ $failures }} thing(s) below must be put right first. Each one is something that would misstate your money or let the wrong person in.</x-alert>
    @endif
    @if ($failures === 0 && $warnings === 0)
        <x-alert tone="success" class="mb-4">Everything checks out. Your books balance and nothing is misconfigured.</x-alert>
    @endif

    <div class="space-y-2">
        @foreach ($checks as $item)
            <div class="flex items-start justify-between gap-4 rounded-lg border border-line bg-surface-raised px-4 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-content">{{ $item['name'] }}</p>
                    <p class="mt-0.5 break-words text-sm text-content-muted">{{ $item['detail'] }}</p>
                </div>
                <x-badge :tone="$tones[$item['status']]">{{ $labels[$item['status']] }}</x-badge>
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-content-subtle">These are the same checks as <code>php artisan nuna:preflight</code>, and the backup is the same as <code>php artisan nuna:backup</code> — shown here because a shop counter has no command line.</p>
</div>
