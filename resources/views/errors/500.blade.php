{{--
    The old (app)/error.tsx and global-error.tsx. Self-contained on purpose:
    no layout, no database, no built assets, so it still renders when the
    failure is in one of those. It must say what happened to the person's
    data: every write is one transaction, so nothing is ever half-written.
    It must not say "nothing was saved": this can be the page shown after a
    save that went through (a sale opens its own page next), and an owner
    told otherwise enters it twice.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Something went wrong · NunaBooks</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f6faff; color: #141d23; }
        main { max-width: 36rem; margin: 0 auto; padding: 3rem 1rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        .lead { margin: 0 0 1.5rem; color: #4b5560; font-size: .875rem; }
        .box { border: 1px solid #e3a8a8; background: #fdf1f1; border-radius: .75rem; padding: 1.25rem; font-size: .875rem; }
        .box p { margin: 0 0 .5rem; }
        .muted { color: #4b5560; }
        .ref { font-variant-numeric: tabular-nums; border: 1px solid #d6dde3; background: #fff; border-radius: .5rem; padding: .5rem .75rem; font-size: .75rem; color: #4b5560; margin-top: .75rem; }
        .actions { margin-top: 1.25rem; display: flex; gap: .5rem; flex-wrap: wrap; }
        .actions a { display: inline-block; border-radius: .5rem; padding: .5rem .75rem; font-size: .875rem; font-weight: 500; text-decoration: none; }
        .primary { background: #006947; color: #fff; }
        .secondary { background: #fff; color: #141d23; border: 1px solid #c4ccd3; }
        @media (prefers-color-scheme: dark) {
            body { background: #111417; color: #e7ecef; }
            .lead, .muted, .ref { color: #a7b0b8; }
            .box { background: #2a1717; border-color: #6b3434; }
            .ref, .secondary { background: #1a1d21; border-color: #3a4148; color: #e7ecef; }
            .primary { background: #3fbe90; color: #0b1410; }
        }
    </style>
</head>
<body>
<main>
    <h1>Something went wrong</h1>
    <p class="lead">This page could not be loaded.</p>
    <div class="box">
        <p><strong>Nothing has been half-written to the books.</strong> Each save goes through whole or not at all. If you had just saved something, it may already be recorded: look for it in its list before entering it again, so it is not entered twice.</p>
        <p class="muted">Try again. If it keeps happening, note the time below — it identifies this failure in the server log.</p>
        <p class="ref">Reference: {{ now()->utc()->format('Y-m-d H:i:s') }} UTC</p>
        <div class="actions">
            <a class="primary" href="">Try again</a>
            <a class="secondary" href="/dashboard">Back to the dashboard</a>
        </div>
    </div>
</main>
</body>
</html>
