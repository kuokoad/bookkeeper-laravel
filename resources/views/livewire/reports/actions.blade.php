{{-- The old shared/report-actions.tsx: back to the list, the CSV of what is on screen, and print. --}}
<x-button variant="secondary" size="sm" href="{{ $backHref ?? '/reports' }}">All reports</x-button>
<x-csv-button :report="$report" :query="$query" />
<x-button size="sm" x-on:click="window.print()">Print</x-button>
