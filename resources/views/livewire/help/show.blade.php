{{-- The old (app)/help/[slug] page. $html is CommonMark output with raw HTML escaped (Pages::render), so it is printed unescaped here. --}}
<div class="mx-auto max-w-3xl">
    <x-page-header :title="$title">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/help">All help</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($html === null)
        <p class="rounded-xl border border-dashed border-line-strong bg-surface-raised px-6 py-10 text-center text-sm text-content-muted">
            This help page could not be read from the machine. Your records are not affected. Whoever installed the software can restore it from the <code>resources/docs</code> folder.
        </p>
    @else
        <article class="help-doc max-w-3xl text-sm leading-relaxed text-content-muted
            [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-content
            [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-content
            [&_h4]:mt-4 [&_h4]:mb-2 [&_h4]:text-sm [&_h4]:font-semibold [&_h4]:text-content
            [&_p]:mb-3 [&_strong]:font-semibold [&_strong]:text-content [&_em]:italic
            [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:pl-5
            [&_a]:text-accent [&_a:hover]:underline
            [&_code]:rounded [&_code]:bg-surface-sunken [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.9em] [&_code]:text-content
            [&_pre]:mb-4 [&_pre]:overflow-x-auto [&_pre]:rounded-xl [&_pre]:border [&_pre]:border-line [&_pre]:bg-surface-sunken [&_pre]:p-3 [&_pre]:text-xs [&_pre]:text-content [&_pre_code]:bg-transparent [&_pre_code]:p-0
            [&_blockquote]:mb-4 [&_blockquote]:border-l-2 [&_blockquote]:border-line-strong [&_blockquote]:pl-4
            [&_table]:mb-4 [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto [&_table]:rounded-xl [&_table]:border [&_table]:border-line
            [&_th]:border-b [&_th]:border-line [&_th]:bg-surface-sunken [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_th]:text-xs [&_th]:font-semibold [&_th]:text-content-subtle
            [&_td]:border-b [&_td]:border-line [&_td]:px-3 [&_td]:py-2 [&_hr]:my-6 [&_hr]:border-line">
            {!! $html !!}
        </article>
    @endif

    {{-- One line, not a button for every other guide: the list is one click away. --}}
    <div class="mt-10 border-t border-line pt-4">
        <a href="/help" class="text-sm font-medium text-accent hover:underline">All the guides</a>
    </div>
</div>
