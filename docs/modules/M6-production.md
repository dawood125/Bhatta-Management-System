# M6 — Production and Brick Stock: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-19
**Branch:** `m6-production` (based on `m5-materials`)

## What M6 delivers

**Production** is the first item of the **Kiln** section in the sidebar, with three tabs.

| Screen | What you can do | Who |
|---|---|---|
| **Brick stock** | How many bricks are at each stage right now: raw (Pathai), in the kiln (Julai), fired and awaiting Nakaasi, ready for sale; and saleable stock by grade (Awal, Doom, Khingar, Tile, Tooty, Rora …) | Everyone |
| **Daily production** | Any day: each stage and grade at the start and end of the day with what came in and went out; Pathai made / rejected / accepted; the day's entries; a "Worth checking" box for possible duplicates and stock adjustments. Previous / next day. Print | Everyone |
| **Entries** | Every production entry for a period, by kind or search (number, team, note). Print | Everyone |
| **Pathai** | Bricks made and rejected by a team on a day; the accepted ones go into raw stock | Owner, Manager, Munshi, Data entry |
| **Loaded into the kiln** | Raw bricks moved into the kiln (Kamyaar) | as Pathai |
| **Firing complete** | Bricks whose firing is finished (now awaiting Nakaasi), and the fuel burnt (coal, Bora …) from material stock | as Pathai |
| **Nakaasi** | Bricks unloaded, split by grade; by-products counted by the trolley (Rora) come with them | as Pathai |
| **Adjust stock** | Correct any stage or grade with a reason (broken, counted more or less) | Owner, Manager |
| **Reverse** | Cancel any production entry with a reason | Owner, Manager |

Each dialog shows how many bricks are available, e.g. "Available in Raw bricks: 11,500", before you save.

## How the brick counts are kept correct
- **Stock is never stored.** Every entry writes movements from one stage to the next; each stage's stock is their sum, so the daily view, the stock page and any later report always agree.
- **No stage can go below zero, on any day.** Loading, firing, Nakaasi and adjustments are refused if they would take more than is there, also for back-dated entries that would make a later day impossible. The check runs under a lock, so two clerks can't move the same bricks at once.
- **Nakaasi adds up.** Grades and broken bricks (Tooty) together are the bricks unloaded and come out of "awaiting Nakaasi"; Rora, counted by the trolley, is added to saleable stock without counting as bricks.
- **Rejected bricks never enter stock**; they stay on the Pathai entry (and later decide what is paid, in M7).
- **Fuel follows the material rules of M5:** taken out at its average cost, never below zero. It belongs to its firing entry: reversing the firing returns the fuel, and the fuel can't be reversed on its own.
- **Nothing is edited or deleted.** A mistake is reversed with a reason (mirror movements dated today); both stay visible. A reversal is refused once the bricks have moved on (e.g. bricks already loaded into the kiln); reverse the later entry first.
- **No double entries from a double click** (one-time key per form). Two entries that look the same on one day are flagged as a possible duplicate, so the owner can check.
- `php artisan ledger:verify` now also checks brick stock: no negative stage, every saleable movement names its grade, and every reversal is complete.

## Agreed acceptance example — passes as an automated test
1. Day 1: made 12,000, rejected 500, so 11,500 are raw ✔
2. Loading 11,000 into the kiln leaves 500 raw and puts 11,000 in the kiln ✔
3. Firing complete moves the 11,000 to awaiting Nakaasi ✔
4. Nakaasi splits them into Awal 8,000 / Doom 2,000 / Khingar 700 / Tooty 300; saleable stock rises by exactly 11,000 and awaiting Nakaasi falls to 0 ✔
5. Moving more than is available is refused ✔

The whole example was also entered by hand through the dialogs in the browser (PT-, LD-, FR-, NK-2026-0001). The stock page and the daily view show the same figures.

## Test results (real runs, 2026-10-19)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **585 passed, 0 failed (2,489 assertions)**, of which 47 are new in M6 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` on the demo data | **consistent** |
| Manual check in the built-in browser | Desktop and phone, English and Urdu: brick stock page, Pathai, loading, firing and Nakaasi dialogs, daily view. No page is wider than a phone screen |

The M6 tests cover:
- the acceptance example; numbering per kind; rejected bricks; every Pathai rule; future dates;
- the never-negative rule, also for back-dated entries; double submits;
- firing with fuel, fuel alone, too much fuel (error on the fuel line, nothing saved), the data entry role recording fuel;
- Nakaasi: more than awaiting, by-products in trolleys, empty grades skipped with errors on the right line, only grades and by-products;
- adjustments and their permissions; reversals (Nakaasi, loading then Pathai, firing with its fuel) and when they are refused;
- write-once entries; the verifier catching an impossible stage and an incomplete reversal; the daily view figures and duplicate flags;
- the screens: who sees which buttons, the dialogs (Pathai, loading, firing with fuel, Nakaasi, adjustment), errors cleared when corrected, the stock page, the daily view with reversal, the entries list, the firing badge on the materials usage list.

## Problems found and fixed during M6
1. **Firing fuel could be reversed twice over.** Fuel recorded with a firing entry could also be reversed from the materials usage list, leaving the firing entry pointing at reversed fuel. That fuel is now marked "Firing FR-…" there and is reversed only with its firing entry.
2. **Nakaasi errors would point at the wrong grade.** Empty grade rows were dropped before checking, so an error on the third row could show on another. Empty rows are now skipped in place.
3. **Who may record fuel.** Data entry users record firing but may not buy or use materials directly; the fuel step now runs under the production permission.
4. **Small display fixes** found in the browser: a stray space in "Ready for sale : Awal", an odd hint wording for trolley by-products, and a right-pointing "loading" icon that pointed the wrong way in Urdu.
5. **Two of my screen tests looked for the wrong text** (the entries filter lists the entry kinds for every role). The buttons now sit in a group labelled "Record production" for screen readers, and the tests check that.

## Your decisions
- **Approving Pathai counts (Q-14).** Proposed was an owner/manager approval of made/rejected counts. v1 records them directly; corrections are reversals. Say if you want an approval step.
- **Firing batches (Q-15).** Daily entries without batch numbers, as proposed. Say if the kiln fires in numbered batches.

## Not in M6 (comes with later modules)
- **Workers and piece-rate pay for Pathai, Kamyaar and Nakaasi:** M7 links workers to these entries (the team name is kept as text until then).
- **Sales taking bricks out of saleable stock (and Kachi sales from raw stock):** M8.
- **Production reports over periods and charts:** M9.
