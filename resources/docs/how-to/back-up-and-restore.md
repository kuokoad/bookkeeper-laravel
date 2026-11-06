# How to back up and restore

The routine, what the commands actually do, and what to do on a bad day.

## Prerequisites

Terminal access on the machine running the app, or someone allowed to change Settings for the in-app
download at `/settings/health`.

## What a backup is

The whole business is one MySQL database. Every sale, every payment, every product,
the ledger underneath them, the users, the settings and the logo. A copy of that
database kept somewhere safe is the shop. Lose it with no copy and there is nothing to
go back to.

```bash
php artisan nuna:backup
```

That writes a timestamped file such as `nunabooks-2026-08-26T18-00-00.sql.gz` into
`storage/backups`, and it is safe to run while the shop is trading.

**This is why a backup is a command and not a file copy.** Copying MySQL's data folder
to a USB stick in the file explorer is not a backup. MySQL keeps recent work in memory
and in its own logs while it runs, so a copy of its files taken on its own can be
missing the latest trading, or torn in half mid-write. The command asks MySQL for a
consistent snapshot instead (`mysqldump --single-transaction`), which produces one
complete file without asking the shop to stop.

### Every copy is checked before it counts

A backup nobody has opened is a guess. So the command loads the finished file into a
scratch database, checks it there, and drops the scratch copy afterwards. It proves:

- The file loads end to end, with the triggers that guard the books.
- **The books inside the copy still balance** — every entry, and the trial balance.
- What customers owe and what you owe suppliers equal their control accounts.
- Every product's stock replays to the figure on screen, and the stock ledger equals
  the Inventory account.
- An owner can still sign in.
- The audit log, the journal and every account's totals match the live books it was
  taken from.

The balance checks are the ones that matter. Loading says the file survived; the
others say the *accounts* survived, which is what the shop actually needs from a
backup.

**A copy that fails is deleted and the command fails loudly.** A broken backup you
believe in is worse than no backup at all, so it is never left in the folder looking
valid. Older backups are trimmed only after a good one exists, so a failure never
leaves you with fewer than you started with.

Each backup is a single self-contained `.sql.gz` file. It can be copied to a stick on
its own and still be whole.

### The commands

| Command                                         | What it does                           |
| ----------------------------------------------- | -------------------------------------- |
| `php artisan nuna:backup`                       | Verified backup, keeping the newest 14 |
| `php artisan nuna:backup --keep=30`             | Keep 30 instead                        |
| `php artisan nuna:backup --dir=E:\backups`      | Write straight to a USB stick          |
| `php artisan nuna:restore <file> --force`       | Put one back (see below)               |

## The daily routine

At close of business:

```bash
php artisan nuna:backup --dir=E:\backups
```

Point `--dir` at a USB stick. That is the whole routine.

A backup sitting on the same computer does not survive that computer being stolen,
dropped, or having its disk fail — which are the three things you are backing up
against. A copy on the same disk protects you only from your own mistakes, which is
the least likely of the four.

If the shop has no terminal, someone allowed to change Settings can download a verified copy from
**Settings → Health & backup**. Same file, same verification.

## Before anything risky

Take one before:

- closing a financial year
- restoring an older backup (so today is recoverable if the restore was the mistake)
- upgrading the app (**Update now** on the Health screen takes one for you first)
- any bulk change

```bash
php artisan nuna:backup
```

## Restoring

```bash
php artisan nuna:restore storage/backups/nunabooks-2026-08-26T18-00-00.sql.gz --force
```

Stop the app first. The command refuses while the app's web server (the
`NunaBooksApache` service) is running. Restoring is done from the command line only;
there is no restore button in the app.

### Never load a backup into the database by hand

This is the one instruction on this page that is worth reading twice, because getting
it wrong is silent.

Loading a dump over a live database with a MySQL tool of your own leaves no copy of
what was there before, and nothing checks that what went in is sound. A file that
loads only half way, or a dump from a different shop, can leave books that still
balance, so nothing warns you. You would only find out by noticing a figure.

The command does it properly, in this order:

1. Checks the backup in a scratch database **before** touching anything, so a bad file
   never replaces a working database.
2. Refuses while the app is running.
3. Saves the books it is about to replace as `...-before-restore.sql.gz` beside your
   backups, so a restore made in a panic is itself undoable. That copy is not checked
   against itself, on purpose: the books being replaced may be the broken thing.
4. Empties the database and loads the backup.
5. Checks the result again, triggers included, and says where the replaced books are
   if anything does not match.

Restoring onto a brand-new computer with an empty database skips step 3: there is
nothing to keep.

## Practise it once

A backup you have never restored is a hope, not a plan. Once, on a copy:

1. Take a backup.
2. Note today's sales total.
3. Restore a backup from a few days ago.
4. Confirm the total changed to match that day.
5. Restore the backup from step 1 to get back.

Fifteen minutes, once. You will know the routine works and that you can do it under
pressure.

## Verification

After a restore:

- Sign in. The shop name and logo are the ones you expect.
- **Trial balance** balances.
- Recent sales are the ones from the backup's date, not today's.
- **Inventory** shows no red alert.
- `/settings/health` passes its readiness checks.

## Troubleshooting

**The backup command failed and deleted its own output.** Working as intended. Every
backup is verified before it counts, and one that fails is removed rather than left
looking valid. A broken backup you believe in is worse than none. Investigate the
database itself: `php artisan nuna:preflight`.

**"The web server (NunaBooksApache) is running."** Stop it as the message says
(`Stop-Service NunaBooksApache`), then restore.

**I restored and the books do not balance.** Restore the `...-before-restore.sql.gz`
file to get back to where you were, then investigate before trying again.

**Backups are filling the disk.** `php artisan nuna:backup` keeps the newest 14. Use
`--keep=30` for more.

**How far back do I need?** Far enough to cover the gap between a mistake happening
and someone noticing. For a shop filing monthly, a month of dailies plus a keeper at
each month end.

## Related

- [Close a period](close-a-period.md) — take one before closing a year.
- [Commands](../reference/commands.md) — `nuna:backup`, `nuna:restore`, `nuna:preflight`.
