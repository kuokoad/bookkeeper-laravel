<?php

declare(strict_types=1);

/*
| A page that failed because the files were updated but `migrate` was not run
| (the edited_at column was missing on 2026-11-10) says to finish the update,
| instead of the generic "Something went wrong".
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => false]);
    Route::get('/__crash', fn () => throw new RuntimeException('Undefined property: stdClass::$edited_at'));
});

/** Forgets one migration was run, as on a PC whose update skipped `migrate`. RefreshDatabase rolls it back. */
function forgetLastMigration(): void
{
    DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete();
}

it('tells the owner to finish the update when a page fails with migrations pending', function (): void {
    forgetLastMigration();

    $this->get('/__crash')
        ->assertStatus(503)
        ->assertSee('The update is not finished')
        ->assertSee('1 database change is waiting.')
        ->assertSee('scripts\update.ps1')
        // It cannot know whether a save went through just before (a sale opens its page next), so it never says nothing was saved.
        ->assertSee('Check before entering anything again.')
        ->assertDontSee('Nothing was saved');
});

it('answers a JSON request with the same instruction', function (): void {
    forgetLastMigration();

    $this->getJson('/__crash')->assertStatus(503)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'The last update was not finished') && str_contains($m, 'scripts\update.ps1'));
});

it('keeps the stack trace for a developer with debug on', function (): void {
    config(['app.debug' => true]);
    forgetLastMigration();

    // Status only: the debug page prints this file's source, which holds the page's wording.
    $this->get('/__crash')->assertStatus(500);
});

it('keeps the ordinary error page when every migration has run', function (): void {
    $this->get('/__crash')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('The update is not finished');
});

it('leaves a missing page as a 404 even with migrations pending', function (): void {
    forgetLastMigration();

    $this->get('/no-such-page')->assertNotFound()->assertDontSee('The update is not finished');
});
