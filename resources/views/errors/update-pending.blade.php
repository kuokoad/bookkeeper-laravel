{{--
    Shown instead of the 500 page when a page fails and migrations are
    pending (bootstrap/app.php): the files were updated but the database was
    not. Self-contained like 500.blade.php, since the database is the part
    that is behind. $count is null when there is no migration history at all.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Update not finished · NunaBooks</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f6faff; color: #141d23; }
        main { max-width: 36rem; margin: 0 auto; padding: 3rem 1rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        .lead { margin: 0 0 1.5rem; color: #4b5560; font-size: .875rem; }
        .box { border: 1px solid #e0c27a; background: #fdf7e8; border-radius: .75rem; padding: 1.25rem; font-size: .875rem; }
        .box p { margin: 0 0 .5rem; }
        .muted { color: #4b5560; }
        code { font-family: ui-monospace, Consolas, monospace; border: 1px solid #d6dde3; background: #fff; border-radius: .375rem; padding: .125rem .375rem; font-size: .8125rem; }
        .actions { margin-top: 1.25rem; }
        .actions a { display: inline-block; border-radius: .5rem; padding: .5rem .75rem; font-size: .875rem; font-weight: 500; text-decoration: none; background: #006947; color: #fff; }
        @media (prefers-color-scheme: dark) {
            body { background: #111417; color: #e7ecef; }
            .lead, .muted { color: #a7b0b8; }
            .box { background: #2a2414; border-color: #6b5a2a; }
            code { background: #1a1d21; border-color: #3a4148; color: #e7ecef; }
            .actions a { background: #3fbe90; color: #0b1410; }
        }
    </style>
</head>
<body>
<main>
    <h1>The update is not finished</h1>
    <p class="lead">NunaBooks was updated, but its database was not brought up to date, so this page cannot open yet.</p>
    <div class="box">
        {{-- Not "nothing was saved": this can be the page shown after a save that went through (a sale opens its receipt page next). --}}
        <p><strong>Check before entering anything again.</strong> If you had just saved something, it may already be recorded. Once the update is finished, look for it in its list first, so it is not entered twice.</p>
        <p>
            @if ($count === null)
                The database has no update history at all.
            @else
                {{ $count }} database {{ $count === 1 ? 'change is' : 'changes are' }} waiting.
            @endif
            To finish, run the update again on the shop computer: <code>scripts\update.ps1</code> in the NunaBooks folder.
        </p>
        <p class="muted">Then reload this page. If you do not know how, stop using NunaBooks for now and call whoever installs it for you.</p>
        <div class="actions">
            <a href="">Reload</a>
        </div>
    </div>
</main>
</body>
</html>
