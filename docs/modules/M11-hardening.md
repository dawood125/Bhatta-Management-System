# M11 — Hardening, Backups, Deployment and UAT: Module Report

**Status:** built and tested on the development laptop, waiting for your review and sign-off. The deployment and UAT steps are ready to run with you.
**Date:** 2026-10-26
**Branch:** `m11-hardening` (based on `m9-reports`; M10 was dropped)

## What M11 delivers

### 1. Permissions review
- Every page needs exactly one named permission (a few pages, such as Reports, need one of several). A test opens **every page** with only its permission (must open) and with every other permission (must refuse). A new page cannot be added without deciding who may open it: the test fails.
- A second test follows **every link each role is shown**, from the dashboard onwards, and fails if a link leads to a refused page.
- Every action that changes data checks a permission (enforced by a test), and every table that belongs to a kiln is limited to the current kiln (enforced by a test).

### 2. Security
- Browser security headers on every page: the site cannot be framed by another site, scripts, styles and fonts only from this site, forms only post back here, HTTPS enforced when on HTTPS.
- Clear error pages in **English and Urdu** together ("You do not have access to this page", "Page not found", "This page has expired", "Something went wrong"…). They never show technical details.
- On the live server the database **cannot be wiped, rolled back or filled with demo data** by accident: those commands are blocked there.
- PDFs are limited to 30 a minute per user; private files (backups) can never be reached by a web address.
- Dependency audits: Composer and npm report **no known vulnerabilities** (after updating Livewire to 4.4.8, see "Problems found").

### 3. Backups
- **Every night at 2:00:** the whole database and the uploaded files (logo) in one zip, checked after writing, optionally locked with a backup password (AES-256). Kept: every backup for 7 days, then one a day for 30 days, one a week for 12 weeks, one a month for a year, one a year for 5 years.
- **Backups page** (owner only, new in the menu): whether backups are up to date, every backup with **Download**, **Back up now**, **Test a restore**, and the history of backups, copies and restore tests. Downloads are in the activity log.
- **Copies on Google Drive** (your request): the owner presses *Connect Google Drive*, enters the code shown at google.com/device on a phone and allows access. From then on every backup is also copied to a folder in their Drive (newest 30 kept). The app can only see that folder, nothing else in the Drive. A failed copy is shown on the page and retried.
- **Restore drill:** every month (and on request) the newest backup is restored into a separate empty database and compared with the live one, table by table (row counts and checksums), and the restored books are checked. On the development data: *50 tables and 4,563 rows came back the same; the books are consistent.*
- **Restoring:** `php artisan kiln:restore-backup` puts a backup back (after a safety backup of the database as it is), or restores it into another database just to look at it.
- An email goes to the chosen addresses when a backup fails or is more than a day old.

### 4. Email settings in the app (your request)
- **Settings → Email** (owner): the email account that sends invoice emails and backup alerts, the "from" name and address, and who gets backup alerts. Set by each kiln in the app; nothing to change on the server for a new customer.
- **Use Gmail** fills in Gmail's server; the page explains how to make a Gmail app password.
- **Send test email** sends at once and, if it fails, shows the email server's own message.
- The password is stored encrypted and never sent back to the browser.

### 5. Speed with years of data
Tested with about **three years of a busy kiln**: 100,519 ledger rows, 47,127 journal entries, 8,880 sales and 27,746 brick movements, entered through the real screens' logic. Times are the median of three runs, measured inside the app on the development laptop:

| Page | Before | After |
|---|---|---|
| Dashboard | 1,147 ms | 283 ms |
| Cash book, a whole year | 9,535 ms | 193 ms |
| Busiest customer's statement | 4,635 ms | 348 ms |
| Party statement, a year | 1,542 ms | 208 ms |
| Sales list | 1,720 ms | 494 ms |
| Sales summary, a year | 2,169 ms | 516 ms |
| Who owes us | 1,515 ms | 707 ms |
| Balance sheet | 516 ms | 231 ms |
| Production summary, a year | 561 ms | 746 ms (draws a row for every day of the year) |
| The other 29 pages | all under 500 ms | all under 300 ms |
| Statement PDF, a year (24 pages) | 3,798 ms | 3,261 ms |

What changed:
- Every ledger line carries its entry's date, so balances and period totals are read from one table with matching indexes.
- **Long statements show 100 rows a page**, newest first. "Older" and "Newer" move between pages; totals and the closing balance are always for the whole period, and PDFs keep every row.
- "Paid / still to pay" (oldest first) and the sales summary are worked out much faster.

### 6. Tools for the server
- `php artisan kiln:check` — is this installation set up right? Settings, PHP, database and migrations, the kiln and its owner, the ledger, files, the queue worker and scheduler (heartbeats), email, backups, Google Drive copies, restore drill. Each line says ok, check or fix.
- `php artisan kiln:benchmark` — times every main page as the owner (read only).

### 7. Deployment and UAT
- [07-deployment.md](../07-deployment.md): step-by-step for Ubuntu 24.04 — server, software, database, code, settings, web server with HTTPS (with a domain, or with a free `…sslip.io` name until there is one), queue worker and scheduler, first backup, updating, restoring, moving to a new server, troubleshooting. Configuration files in `deploy/`, an update script, a production `.env` template.
- **Where to host it:** a small VPS (Contabo, about €5 a month) is recommended for the partner test too; Oracle Cloud's free VM also works. **Render's free plan is not suitable** (no MySQL/MariaDB, files are wiped on restart, no scheduler or worker).
- [08-uat.md](../08-uat.md): the partner's test script (setting up a kiln, a working day, corrections, week and month end, who may see what, safety), the owner review, and how to report problems.

## Test results (real runs, 2026-10-26, Ubuntu with MariaDB 10.11)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **755 passed, 0 failed, 0 skipped (4,332 assertions)**, of which 49 are new in M11 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` | **consistent**, on the demo data and on the 100,519-row test data (including the new check that every line carries its entry's date) |
| `composer audit`, `npm audit` | **no vulnerabilities** |
| Migrations | the three new ones (backup history, ledger-line dates, app settings) run forward and back on MariaDB; the ledger-date one filled 100,519 rows in 4 seconds |
| Restore drill on the demo data | passed: 50 tables and 4,563 rows came back the same |
| Manual check in the built-in browser | Desktop and phone, English and Urdu: Backups (back up now, download, test a restore, Google Drive card), Settings → Email (Gmail button, validation messages), error pages, statements, and the content policy (dialogs, live search, charts and fonts work with it). No page is wider than a phone screen |

The M11 tests cover:
- every page's permission (only it opens the page, everything else is refused), every link each role sees, every action's permission check, kiln scoping of every kiln table;
- security headers, the content policy on pages only, the PDF limit, the error pages (and that a server error shows no details), the live-server guards;
- backups: owner only, back up now, a failed backup, the password lock, the list and its health, downloads (and that nothing else can be downloaded), the guard against overwriting the live database, unsafe zips, a wrong password, the restore drill end to end, a copy that differs from the live database, restoring into another database from the command line;
- Google Drive: connecting with a code (waiting, allowed, refused), the token stored encrypted, copying after each backup into the app's folder, keeping the newest 30, a failed copy that does not fail the backup, disconnecting, not offered without the sign-in details;
- email settings: owner only, password encrypted and kept, validation, settings used for every email and in every queue job, the test email, a real refused connection;
- statements page by page with the same balances as in one piece; the setup check.

## Problems found and fixed during M11
1. **Links a role could not open.** The munshi saw "Add materials in Kiln settings" on a new purchase, and data entry saw links to sales on Daily production. Both led to "Forbidden". They are now shown only to those who may open them. Found by the new link test.
2. **Long statements were slow** (a year's cash book: 9.5 s). Now shown a page at a time (see section 5).
3. **The dashboard and balance sheet read the whole ledger** for each month. They now use the line dates and indexes.
4. On the email settings page the *Security* choice was cut off on a laptop screen; the server name now has its own row.
5. In Urdu, the backup size read "KB 91.1"; dates and sizes are now kept left-to-right inside Urdu sentences.
6. A help text showed `\"` instead of quotes; typographic quotes are used.
7. Private files could in principle be served through a signed web address; that route is switched off.
8. **A Livewire security advisory** was published on 23 October (an authorization bypass in its `#[Authorize]` attribute, versions 4.3.0–4.4.7). The system does not use that attribute, so it was not exposed, but Livewire is updated to the fixed 4.4.8 and the dependency audit is clean again.

## What I need from you
1. **Google Drive:** create the Google sign-in for the app once, in your Google account (07-deployment.md, "Google Drive copies"; about 10 minutes, I will guide you). Until then the Backups page says Drive copies are not available.
2. **Test server:** choose where to put it for your partner's test. My recommendation is a small Contabo VPS (the same as the live server later); Oracle Cloud's free VM is the free option. Not Render.
3. **When we deploy:** a long random backup password, kept in your password manager.

## Not verified yet
- The deployment steps and files have been checked here but **not yet run on a real server** — we do that together, step by step.
- Google Drive copying is tested against simulated Google responses (every step: code, waiting, allowed, refused, upload, keeping 30, removed access). The first real connection happens once the Google sign-in exists.
- Email sending is tested with a simulated mail server and with a real refused connection; a real send happens when the first email account is set up.
