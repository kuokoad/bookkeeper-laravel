<?php

declare(strict_types=1);

/*
| A Livewire call goes to /livewire/update, not to the page's route, so the
| page's session.limits and password.chosen run again only as persistent
| middleware (AppServiceProvider). These post a real update request, as the
| browser does from a page left open.
*/

use App\Auth\Sessions;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

afterEach(fn () => Carbon::setTestNow());

/** The page as the browser got it, signed in at $at: its first component's snapshot. */
function openPage(int $userId, Carbon $at): string
{
    $html = test()->actingAs(User::query()->findOrFail($userId))->withSession([
        Sessions::SIGNED_IN_AT => $at->getTimestampMs(),
        Sessions::FINGERPRINT => Sessions::fingerprint($userId),
    ])->get('/products')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $m);

    return htmlspecialchars_decode($m[1], ENT_QUOTES);
}

/** A round trip from that page, as Livewire's JavaScript sends it. */
function livewireCall(string $snapshot, array $headers = []): mixed
{
    return test()->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ], ['X-Livewire' => '', 'Accept' => '*/*', ...$headers]);
}

it('refuses a Livewire call from an open page once the 30-day session limit has passed', function () {
    $start = Carbon::parse('2026-11-01 08:00:00', 'UTC');
    Carbon::setTestNow($start);
    $id = owner();
    $snapshot = openPage($id, $start);
    livewireCall($snapshot)->assertOk();

    Carbon::setTestNow($start->copy()->addDays(Sessions::ABSOLUTE_MAX_DAYS)->addSecond());
    livewireCall($snapshot)->assertRedirect('/login');
    $this->assertGuest();
});

it('refuses that call with a 401 when it asks for JSON, rather than running it', function () {
    $start = Carbon::parse('2026-11-01 08:00:00', 'UTC');
    Carbon::setTestNow($start);
    $snapshot = openPage(owner(), $start);

    Carbon::setTestNow($start->copy()->addDays(Sessions::ABSOLUTE_MAX_DAYS)->addSecond());
    livewireCall($snapshot, ['X-Requested-With' => 'XMLHttpRequest'])->assertUnauthorized();
});

it('refuses a Livewire call from somebody who must choose their own password first', function () {
    $id = owner();
    $snapshot = openPage($id, Carbon::now());
    DB::table('users')->where('id', $id)->update(['must_change_password' => true]);

    livewireCall($snapshot)->assertRedirect('/account/password?required=1');
});

it('refuses a Livewire call from an open page once that right has been taken away', function () {
    $id = staff(['products' => viewOnly()]);
    $snapshot = openPage($id, Carbon::now());
    livewireCall($snapshot)->assertOk();

    // The right goes, and the same browser stays signed in (or signs in again): the old tab must not keep re-rendering.
    DB::table('user_permissions')->where('user_id', $id)->delete();
    $this->withSession([Sessions::SIGNED_IN_AT => Carbon::now()->getTimestampMs(), Sessions::FINGERPRINT => Sessions::fingerprint($id)]);
    livewireCall($snapshot)->assertRedirect('/no-access?area=products');
});
