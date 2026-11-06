{{-- A table cell; `numeric` right-aligns it in tabular figures. --}}
@props(['numeric' => false])
<td {{ $attributes->class(['px-3 py-2.5 align-middle', 'tabular text-right' => $numeric]) }}>{{ $slot }}</td>
