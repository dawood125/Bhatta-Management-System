# M1 — Kiln Settings and Master Data: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-18
**Branch:** `m1-kiln-settings` (based on `m0-foundation`)

## What M1 delivers

All of it is under **Administration → Kiln settings**, with one tab per area:

| Tab | What you can do | Who |
|---|---|---|
| **Kiln profile** | Kiln name and address in English and Urdu, phone, email, logo (PNG/JPG/WebP, max 1 MB; the logo shows in the sidebar). Currency is fixed to PKR | Owner only |
| **Products and grades** | Add or edit grades, raw bricks and by-products: English + Urdu name, type, unit, "can be sold", display order. Archive / restore | Owner, Manager |
| **Production stages** | Rename the 4 fixed stages (English + Urdu). Each stage shows what it means | Owner, Manager |
| **Units** | Add or edit units (name, symbol, decimal places). Archive / restore | Owner, Manager |
| **Areas** | Customer towns / routes in English and Urdu. Archive / restore | Owner, Manager |
| **Expense categories** | Categories and their sub-categories in English and Urdu. Archive / restore | Owner, Manager |

Every list has search (English or Urdu) and an Active / Archived / All filter. Each change is written to the activity log, which now shows readable names for these records.

### Default data for every kiln (all editable; provisional until the owner confirms Q-13 / Q-16)
- **Units:**
  - Brick (built-in, whole numbers)
  - Ton
  - Kilogram
  - Maund
  - Cubic foot
  - Trolley
  - Bag
  - Litre
  - Piece
- **Products:**
  - Awal / اوّل, Doom / دوم, Khingar / کھنگر, Tile / ٹائل (fired grades)
  - Tooty / ٹوٹا (by-product, counted in bricks)
  - Rora / روڑا (by-product, counted in trolleys)
  - Kachi / کچی اینٹ (raw brick, not for sale)
- **Stages:**
  1. Raw bricks (Pathai)
  2. In the kiln (Julai)
  3. Fired, awaiting Nakaasi
  4. Ready for sale
- **Expense categories (with sub-categories):**
  - Fuel and vehicles
  - Repairs and maintenance
  - Utility bills
  - Food and mess
  - Office
  - Government fees and taxes
  - Miscellaneous

A new kiln gets these through `kiln:install`. An existing kiln (e.g. a server set up during M0) gets them with `php artisan kiln:defaults`, which only adds what is missing and never changes your edits.

## Rules enforced (and tested)
- **Archive, never delete.** Archived items disappear from new entries, but old records that use them stay correct.
- **Names are unique within a kiln**, in English and in Urdu, ignoring letter case. Another kiln may use the same names.
- **Brick grades and raw bricks must be counted in bricks.** By-products may use any unit.
- **The built-in Brick unit** can be renamed, but it can't be archived and its decimals can't change (bricks are whole numbers).
- **A unit can't be archived while active products use it.**
- **Stages can only be renamed.** Their kind and order are fixed, because stock rules in M6 depend on them, e.g. "Fired, awaiting Nakaasi" can never be sold.
- **Kiln separation:** a unit, category or product id from another kiln is rejected as "not found" or "invalid", whether picked on screen or sent by hand.
- **Logo upload:** only real images up to 1 MB. Old logo files are deleted only after the new one is saved, and a failed save deletes the new upload. SVG is refused, because SVG files can carry scripts.

## Test results (real runs, 2026-10-18)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **247 passed, 0 failed (989 assertions)**, of which 79 tests are new for M1 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| Manual check in the built-in browser | Desktop and phone, English and Urdu: products list, expense categories, stages, adding an area with an Urdu name |

## Problems found and fixed during M1
1. **Crash on a non-image logo:** choosing a PDF as the logo and pressing Save crashed the page (it tried to preview the PDF as an image). It now shows a clear error instead. This was caught by an automated test.
2. **Urdu sub-name alignment:** the small Urdu name under an English name was aligned to the wrong side. It is now isolated inline.
3. **Settings tabs on phones:** they showed a clumsy scrollbar, and the selected tab could be off-screen. The scrollbar is hidden and the selected tab scrolls into view.
4. **Activity log:** it showed new records as "Area #1". It now shows "Area: Sheikhupura" (in Urdu when Urdu is selected), and says "Archived" rather than "Deactivated" for list items.

## Not in M1 (by design)
- **Document number formats:** moved to M2, together with the first real documents (D-019).
- **Material list** (soil, Kassu, coal, Bora…): comes in M5 with purchases and stock.
- **Labour categories and rates:** come in M7.
- **Changing a product's type or unit once it has been used:** M6 will lock this as soon as stock entries exist.
