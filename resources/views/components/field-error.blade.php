{{--
    One field's error under its control, for forms laid out in rows where
    <x-field> does not fit: <x-field-error id="q-1" :message="$errors->first('rows.1.qty')" />.
    Nothing at all when there is no message. The control points at it with aria-describedby="{id}-error".
--}}
@props(['id', 'message' => null])
@if ($message)<p id="{{ $id }}-error" class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@endif
