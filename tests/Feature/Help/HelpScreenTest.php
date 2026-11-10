<?php

declare(strict_types=1);

/* In-app Help (the old help pages and lib/help.ts) and the 500 page. */

use App\Livewire\Help\Pages;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

it('lists the guides for anyone signed in', function () {
    $this->get('/help')->assertRedirect('/login');
    $response = signedIn(staff())->get('/help')->assertOk()->assertSee('Stored on this machine, so it works without internet.');
    foreach (Pages::PAGES as $slug => [$title]) {
        $response->assertSee("/help/{$slug}")->assertSee($title);
    }
});

it('renders every guide from resources/docs', function (string $slug) {
    signedIn(staff())->get("/help/{$slug}")->assertOk()->assertSee(Pages::PAGES[$slug][0])->assertSee('All the guides');
})->with(array_keys(Pages::PAGES));

it('turns links to served guides into help links and the rest into plain words, and drops the H1', function () {
    $html = Pages::render('getting-started');
    expect($html)->toContain('href="/help/backups"')
        ->toContain('href="/help/fixing-a-mistake"')
        ->not->toContain('developer-setup.md')
        ->toContain('Developer setup')
        ->not->toContain('<h1>');
});

it('escapes raw HTML in a guide rather than running it', function () {
    $env = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
    expect(Pages::render('backups'))->not->toContain('<script');
    expect((string) (new MarkdownConverter($env->addExtension(new CommonMarkCoreExtension)))->convert('<script>x</script>'))
        ->toContain('&lt;script&gt;');
});

it('describes this app’s backups, not the old SQLite file', function () {
    expect(Pages::render('backups'))->toContain('php artisan nuna:backup')->not->toContain('npm run')->not->toContain('bookkeeper.db');
});

it('answers an unknown guide with not found', function () {
    $id = staff();
    signedIn($id)->get('/help/..%2F..%2F.env')->assertNotFound();
    signedIn($id)->get('/help/developer-setup')->assertNotFound();
});

it('says nothing was saved on the 500 page', function () {
    expect(view('errors.500')->render())->toContain('Something went wrong')->toContain('Nothing was saved.');
});

/*
 * Ported from the old tests/lib/help.test.ts. The old tests/lib/markdown.test.ts
 * tested a hand-written parser; league/commonmark replaces it, so only the
 * constructs the guides rely on (tables, fences, Windows line endings) are kept.
 */

it('points every guide at a file that exists and renders it in full', function (string $slug) {
    expect(resource_path('docs/'.Pages::PAGES[$slug][2]))->toBeFile()
        ->and(substr_count((string) Pages::render($slug), '<p>'))->toBeGreaterThan(3);
})->with(array_keys(Pages::PAGES));

it('leaves no raw markdown in the words a reader sees', function (string $slug) {
    // A single asterisk counts: `*accounts*` once reached the screen wearing them.
    $words = strip_tags((string) preg_replace('#<(code|pre)>.*?</\1>#s', '', (string) Pages::render($slug)));
    expect(preg_match_all('/\*|`|\]\(/', $words))->toBe(0);
})->with(array_keys(Pages::PAGES));

it('sends every link somewhere a reader can get to', function (string $slug) {
    preg_match_all('/href="([^"]*)"/', (string) Pages::render($slug), $m);
    expect($m[1])->toBeArray(); // a guide with no links still asserts something
    foreach ($m[1] as $href) {
        // `../reference/filters.md` means nothing in the app: those become plain words.
        expect($href)->not->toEndWith('.md');
        if (str_starts_with($href, '/')) {
            expect($href)->toMatch('#^/[a-z0-9/-]*$#');
        }
        if (str_starts_with($href, '/help/')) {
            expect(Pages::PAGES)->toHaveKey(substr($href, strlen('/help/')));
        }
    }
})->with(array_keys(Pages::PAGES));

it('renders the guides’ tables as tables, never as raw pipes', function (string $slug) {
    // Through Pages::render itself: a hand-built converter would stay green if Help lost its table support.
    $html = (string) Pages::render($slug);
    expect($html)->toContain('<table>')
        ->and(preg_match('/^\s*\|/m', strip_tags($html)))->toBe(0);
})->with(['fixing-a-mistake', 'credit-and-payments', 'moving-money', 'backdating-a-sale', 'managing-tax', 'backups']);

it('keeps a guide’s commands as code, exactly as written', function () {
    expect((string) Pages::render('backups'))->toMatch('#<pre><code[^>]*>php artisan nuna:backup#');
});
