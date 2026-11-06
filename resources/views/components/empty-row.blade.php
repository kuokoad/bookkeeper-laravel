{{-- The old EmptyRow: one table row that says the filter found nothing. --}}
@props(['colspan'])
<tr>
    <td colspan="{{ $colspan }}" class="px-3 py-10 text-center text-sm text-content-muted">{{ $slot }}</td>
</tr>
