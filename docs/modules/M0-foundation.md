# M0 — Foundation and Design System: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-18

## What M0 delivers

| Area | What exists now |
|---|---|
| Project | Laravel 13.37 in the repository root, git initialised. Local MySQL databases `gbricks_dev` (demo data) and `gbricks_test` (automated tests) |
| Login | Login with **username or email**. 5 wrong attempts are throttled. Deactivated accounts and accounts without kiln access are refused. "Keep me logged in" is optional. Logout. If someone is deactivated, or their session expires while a page is open, their next click takes them to the login page with the reason shown (no English-only error boxes) |
| Kiln separation | Every request runs inside one kiln. Any kiln-owned table automatically sees only its own kiln's rows, and saving or deleting another kiln's row is refused (it throws). A kiln in the session that the user doesn't belong to is ignored |
| Roles | Owner, Manager, Munshi/Accountant, Data entry, Viewer, assigned **per kiln**. Permissions live in code (`App\Enums\Permission`) and are applied with `php artisan permissions:sync` |
| Users screen | List, search, status filter and sorting. Add, edit, change role, reset password, deactivate/activate. Safety rules: you can't change your own role or deactivate yourself; only an owner can manage an owner; the kiln always keeps at least one active owner; a deactivated user is logged out of every device immediately |
| My profile | Name, phone, email, language. Change password (current password required); other devices are logged out |
| Activity log | Permanent, read-only history per kiln (who / what / when / old → new values). Filter by person and date. Passwords are never recorded |
| Money | `App\Support\Money`: integer paisa, exact maths, half-up rounding applied once, and rate × quantity (e.g. PKR 1,200 per 1,000 bricks × 11,500 = 13,800.00). `MoneyCast` refuses plain numbers, so rupees and paisa can't be mixed up |
| English / Urdu | Full Urdu translation, right-to-left layout, Urdu font (self-hosted, no internet needed), Urdu validation messages, Urdu dates. Language is saved per user. Switch on the login page and in the top bar |
| Design system | Brick/terracotta colour tokens, Inter + Noto Nastaliq Urdu, Lucide icons, and components: buttons, inputs, selects, fields with errors, checkbox, card, badge, table (sorting, empty state, phone scrolling), dialog (bottom sheet on phones), toasts, page header, avatar. Preview page `/dev/components` (local only) |
| Setup | `php artisan kiln:install` creates the client's kiln and owner on a new server. The demo seeder (local only, refuses to run in production) creates "Demo Kiln (demo data)" with 5 demo accounts |

## How to check it yourself

1. Start MySQL (Laragon), then run `php artisan serve`, or use the Laragon site.
2. Open http://127.0.0.1:8000. The demo logins and their password are in `database/seeders/DatabaseSeeder.php` (local demo data only).
3. Try the following:
   - switch to اردو;
   - add a user, then edit it, reset its password and deactivate it;
   - log in as `viewer` and confirm the Users and Activity pages are not available;
   - open the app on a phone-width window.

## Test results (real runs, 2026-10-18)

| Check | Command | Result |
|---|---|---|
| Unit + feature tests | `./vendor/bin/pest --testsuite=Unit,Feature` | **167 passed, 0 failed (626 assertions)** |
| Static analysis | `./vendor/bin/phpstan analyse` (Larastan, level 6) | **0 errors** |
| Code style | `./vendor/bin/pint --test` | **passed** |
| Browser tests (Chromium) | `./vendor/bin/pest --testsuite=Browser` | see "Browser tests" below |
| Manual browser check | Built-in browser, desktop 1280px and phone 375px, English and Urdu | Done; issues found and fixed are listed below |

### What the automated tests cover
- **Money (37 tests):**
  - parsing typed amounts and rejecting bad input;
  - exact arithmetic (no float errors);
  - the owner's rates: Pathai 13,800.00, Kamyaar 4,510.00, Nakaasi 3,575.00, sale 75,000.00;
  - decimal tons;
  - half-up rounding;
  - range limits and formatting.
- **Kiln isolation (13 tests):**
  - no data access without a kiln;
  - each kiln sees only its own rows;
  - no creating, updating, moving or deleting across kilns;
  - money columns stored as paisa.
- **Login and sessions (19 tests):**
  - username, email and case handling;
  - wrong password and unknown user get the same message;
  - deactivated users, users without a kiln and inactive kilns are refused;
  - throttling and logout;
  - a user deactivated or removed while logged in is thrown out on their next click.
- **User management (44 tests):** every permission and safety rule above, validation of every field, cross-kiln users giving "not found", and the action refusing unauthorised callers even when the screen is bypassed.
- **Profile, activity log, language, install command, navigation and architecture rules:**
  - an Urdu translation must exist for every label;
  - placeholders must survive translation;
  - debugging leftovers are not allowed;
  - money must never be stored as float.

## Problems found during testing and fixed
1. **Phones:** an invisible screen-reader label in tables escaped its scroll box, so the whole page scrolled sideways. Fixed in the shared table component, so every future table is fixed too.
2. **Urdu:** a phone number shown inside Urdu text appeared reversed ("1234567 0300"). Fixed with direction isolation on mixed text in the activity log.
3. **Urdu readability:** the Urdu text was too small, and English names were rendered with the Urdu font's Latin letters. The Urdu text size is now one step larger, and Latin text and numbers use Inter.
4. **Kiln history:** the kiln's own "created" entry was stored without a kiln. Fixed.
5. **Toasts:** they covered the top bar. They now appear below it.

## Known limitations / not in M0
- **No password reset by email yet:** there is no SMTP account. The owner or manager resets passwords.
- **Light theme only.**
- **Urdu translations need review:** a native speaker, ideally the owner, should check the wording, especially role names like "منشی / اکاؤنٹنٹ". All texts are in `lang/ur.json`.
- **Kiln switcher hidden:** one kiln per installation (D-015). The data structure already supports more.
- **Dashboard is a welcome screen for now:** real figures arrive with the business modules.
