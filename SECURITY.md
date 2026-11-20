# Security Policy

> Status: **implemented**, except where a line below says planned, open or TBD.
> This is the security model the Laravel rebuild keeps from the old app
> (`C:\ClaudeProjects\bookkeeper`), with the departures from it marked. Still to
> do before cut-over: the backup-restore rehearsal on the shop's real data.
> Last updated: 2026-11-20.

## Reporting a vulnerability
Contact: **TBD**. Please do not open public issues for vulnerabilities.

## Supported versions
| Version | Supported |
|---|---|
| Laravel rebuild (unreleased) | In development |
| Old Next.js app | Supported until cut-over |

## Security model

The app runs on the shop's own Windows PC and is reached over the shop WiFi on plain HTTP. **It must not be exposed to the internet.**

### Sensitive data
| Data | Classification | Where stored | Protection |
|---|---|---|---|
| Passwords, till PINs | Secret | `users.password_hash`, `users.pin_hash` | Laravel `Hash` (bcrypt or argon2id). Imported scrypt hashes verified once, then rehashed. Never logged, stripped from audit metadata. |
| Sessions | Secret | Laravel `sessions` table | Session id regenerated on login; all of a user's sessions dropped on password change, role or permission change, or deactivation. |
| Financial records | Business-confidential | Whole database | Per-module permissions; LAN only; backups are full copies, so treat backup files as sensitive. |
| Customer contact details | Personal data | `customers`, `suppliers` | Module permissions. |
| Audit trail | Integrity-critical | `audit_logs` | Insert-only: no update or delete path in code, and DB triggers refuse `UPDATE` / `DELETE`. |
| Shop logo | Untrusted binary | `business_settings.logo_data` | Type from magic bytes (PNG, JPEG, WebP only; SVG refused by name), ≤ 1 MB, pixel dimensions capped, served with the sniffed type plus `X-Content-Type-Options: nosniff` and a sandbox CSP. |

### Authentication and authorization

**Credentials** (carried over exactly from `src/lib/auth/`):
- Password: 8 to 200 characters. Refuse a short list of common passwords (password, password1, password123, 12345678, 123456789, qwerty123, shop1234, admin123, letmein1, welcome1) and a single repeated character. NFKC-normalise before hashing and verifying.
- Till PIN: 4 to 8 digits. Refuse all-same digits and a PIN that starts with a simple run (0123, 1234 … 3210). It is a second credential for the same account and shares one throttle and one lockout counter with the password.
- Lockout (staff): 5 failed sign-ins lock the account for 15 minutes; an owner can unlock it. Each further 5 failures double the lock, up to 24 hours, and the count is forgotten a day after the last lock ended (the old app started again at 15 minutes every time). An attempt made while a lock is running is not counted.
- Owners are never locked out: from the fifth failure in a row each attempt waits, doubling up to 60 seconds, and one attempt is checked per wait.
- Throttle: 10 attempts per 10 minutes per typed username, under a looser ceiling of 50 on one shop-wide key (per client IP only when proxy headers are trusted). Counters must survive a restart, so they are stored in the database.
- Error text is identical for every failure: "Incorrect username or password." / "Incorrect username or PIN."
- Sessions: 7-day idle expiry that slides forward when less than 24 h remains, and a hard limit of 30 days from sign-in. Cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` only when `SESSION_SECURE_COOKIE=true` (default off on the LAN).
- `must_change_password`: after an owner resets a password, every page except the password-change screen redirects there.

**Migrating old password hashes.** The old format is `scrypt$N$r$p$<salt b64url>$<hash b64url>` with N = 32768, r = 8, p = 1, 16-byte salt and 64-byte key. PHP has no built-in scrypt with explicit N/r/p, so the rebuild ships a small verifier (`app/Auth/LegacyScrypt.php`) used only when a stored hash starts with `scrypt$`. On the first successful sign-in the password (or PIN) is rehashed with Laravel's hasher in the same transaction. Hashes are never reset or weakened in bulk. The verifier is tested against the RFC 7914 vectors and against hashes made by the old app. A malformed hash still costs a verification, so timing does not reveal it.

**Roles and permissions:**
- `OWNER`: everything.
- `STAFF`: per-module flags `view` / `create` / `edit` / `void` across 14 modules (sales, purchases, inventory, products, customers, suppliers, expenses, income, accounts, reports, reconciliation, quotations, users, settings). Any non-view right also requires view. New staff get sales view+create, products view, inventory view, customers view+create.
- Dashboard, search, help and the password-change screen need only a session.
- Two answers to a refusal, one decision: pages redirect to `/no-access?area=<known module>`; actions and API routes return 403. Hiding a menu link is a courtesy, not the control.
- Users are switched off, never deleted. The last active owner cannot be switched off or demoted, and nobody can switch off themselves. Role checks for owner-only actions read the role from the database, not from the session.
- Backdating a sale needs sales `edit`. Handing money back on a customer return needs sales `void`. Downloading a backup needs settings `edit`. Updates are owner-only.

**Other controls:** CSRF protection on every form (Laravel default); all input validated server-side (Form Requests or service-level validation); Eloquent / query-builder bindings for every value; sort keys whitelisted before they reach `ORDER BY`; Blade escapes output; per-user throttles on exports (30/min), backups (6/5 min) and search (240/min).

### Secrets management
- Secrets come only from `.env` (git-ignored). `.env.example` holds placeholders. `APP_KEY` is generated at install.
- The MySQL app user has rights on the app database only. A separate user, with only the rights `mysqldump --single-transaction` needs, is used for backups.
- Key rotation: TBD.

### Dependencies
- `composer audit` and `npm audit` before each release. Cadence: TBD.

### Compliance
- Ghana Data Protection Act, 2012 (Act 843) may apply to customer personal data. Not assessed. TBD.
- Tax records (VAT, NHIL, GETFund) must stay complete and unaltered, which the void-with-reversal and audit rules provide.

## Known risks and mitigations
| Risk | Mitigation | Status |
|---|---|---|
| Plain HTTP on the shop LAN | LAN only, firewall rule limited to the private network, `Secure` cookies when HTTPS is added | Open (same as old app) |
| One PC holds all the data | Verified `mysqldump` backups, copied off the machine; restore tested in the test suite. A restore copies the live books first | In place; rehearsal on the real data still to do |
| A backup file from outside (a USB stick) | The file is loaded into a scratch database and checked before it replaces anything, but it is not signed: a tampered file can still run statements while it is checked. Restore only files this shop made | Open |
| Legacy scrypt verifier is custom code | Tested against RFC 7914 and real old-app hashes; used only until each user's first sign-in | In place |
| MySQL root password on the shop PC | Random at install, kept out of the app; app uses a least-privilege user. The installer restricts the app folder to Administrators and SYSTEM before it writes `.env` | In place |
| Two tills writing at once (new risk with MySQL) | Row locks on stock, sequences and balances; tested with concurrent processes | In place |
| A staff account locked by somebody else's wrong guesses | The lock doubles on repeats, so a colleague who knows a username can keep it locked; an owner can unlock it | Accepted |
| Login throttle has a shop-wide ceiling | Per username first; per-IP needs trusted proxy headers | Accepted |
