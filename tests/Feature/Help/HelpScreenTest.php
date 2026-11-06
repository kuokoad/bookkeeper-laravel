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
