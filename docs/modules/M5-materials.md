# M5 — Materials and Procurement: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-19
**Branch:** `m5-materials` (based on `m4-vouchers`)

## What M5 delivers

A new **Kiln** section in the sidebar with **Materials**, plus a **Materials** tab in Kiln settings.

| Screen | What you can do | Who |
|---|---|---|
| **Stock** | Every material with the quantity in stock, its average cost per unit and its value, and the total value of material stock | View: Owner, Manager, Munshi, Viewer |
| **New purchase** | Date; on credit (added to the supplier's account) or paid now from an account; supplier (searchable); bill or vehicle number; up to 20 materials with quantity and rate; up to 10 extra charges (trolley/transport, excavator, loading, other), each paid with the goods, owed to another party such as the trolley driver, or paid from an account. The screen shows each line's cost per unit including charges | Owner, Manager, Munshi |
| **Purchases** | Purchases for a period with totals for goods, charges and total cost. Filter by material, search by number, supplier or bill. **Print** gives the purchase report | View: as Stock |
| **Purchase page** | Materials, rates, charges and who was paid, the landed cost per unit. **Print** gives a purchase slip with signature lines. **Reverse** with a reason | Reverse: Owner, Manager |
| **Usage** | Record what was taken from stock (e.g. the day's coal and Bora), several materials at once; each line shows the stock left and what it will cost. List and total cost per period, filter by material, print, reverse | Record: Owner, Manager, Munshi. Reverse: Owner, Manager |
| **Stock card** (per material) | Stock brought forward, every purchase, use, shortage and reversal with the running quantity and value, stock at the end. Print | View: as Stock |
| **Cost report** | Per material for the period: stock at the start, bought (with charges), used, shortage, stock at the end, in quantity and value. "Used" is what each material cost the kiln: the owner's "coal cost" and "Bora cost" | View: as Stock |
| **Opening stock / shortage** | Opening stock already at the kiln, with what it cost. Shortage or wastage found when counting, with a reason | Owner, Manager |
| **Kiln settings → Materials** | Add, rename and archive materials, with their unit and type (raw material, fuel, other) | Owner, Manager |

Starting materials for every kiln (all editable): Soil (Matti) in cubic feet, Kassu by the trolley, Coal (Kola) by the ton, Bora by the bag, Tuka and waste fuel by the trolley. These units follow the proposed defaults for Q-10 and Q-12 until you confirm them.

## How stock and costs are kept correct
- **Stock is never stored.** Every purchase, use, shortage and reversal is one movement with a quantity and a value; stock is their sum. The value side always equals the "Material stock" account in the books, and `ledger:verify` checks that entry by entry.
- **Landed cost.** Extra charges are added to the materials' cost, spread over the purchase lines by value, to the exact paisa (any rounding paisa goes to the lines with the largest share).
- **Weighted average cost.** Material taken out is valued at the stock's current average. Taking out the last of a material takes out all of its value, so no paisa is ever left behind.
- **Where the money goes.** A purchase raises "Material stock" and what the kiln owes the supplier (or lowers the cash). Using material moves its cost to that material's own "… used" expense account (5501 onwards), so the profit and loss shows coal, Bora and soil separately. Shortages go to "Material shortages".
- **Stock can never go negative**, on the entry's date or any later day: a back-dated usage that would make an earlier day impossible is refused. The check runs under a lock, so two people can't use the same stock at once.
- **Exact quantities.** Quantities are kept as whole thousandths and shown with the unit's decimal places (whole bags, 2 decimals for cubic feet, 3 for tons). Typing more decimals than the unit allows is refused, not rounded. A unit can't lose decimal places, and a material can't change its unit, once stock is recorded in it.
- **Nothing is edited or deleted.** A mistake is reversed with a reason, which also returns the stock. A reversal is refused when the material has been used since, or when what is left would carry no sensible value; you then reverse that usage first, or record a shortage.
- **No double posting:** each form carries a one-time key.
- **Kiln separation:** another kiln's purchase or material gives "not found".

## Agreed acceptance examples — all pass as automated tests
1. Buying 1,000 units at 50 plus a 5,000 trolley charge puts 1,000 units in stock at a cost of 55,000 ✔
2. Using 400 units leaves 600 units in stock (worth 33,000; the 400 cost 22,000) ✔
3. Material stock can never go negative ✔ (also on earlier dates)

All three were also done by hand in the browser: PU-2026-0001 (Soil 1,000 cft at 50 plus 5,000 trolley, Rs 55 per cft), then usage of 400 cft (Rs 22,000). The stock page shows 600 cft worth Rs 33,000, and the cost report agrees.

## Test results (real runs, 2026-10-19)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **538 passed, 0 failed (2,216 assertions)**, of which 84 are new in M5 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` on the demo data | **consistent** |
| Manual check in the built-in browser | Desktop and phone, English and Urdu: new purchase with a charge, purchase page, usage dialog, stock page, cost report. No page is wider than a phone screen |

The M5 tests cover:
- the acceptance example; charges to the supplier, another party or an account; paid-now purchases; the exact split of charges;
- weighted average cost, the last unit taking the last paisa, several materials per usage entry and their expense accounts;
- the never-negative rule, also for back-dated usage; opening stock and shortages;
- reversals of purchases, usage and opening stock, and when they are refused; double submits;
- every validation rule, unit decimals, archived and other-kiln materials, unit and material changes, archiving only at zero stock;
- permissions per role (also when an action is called directly), write-once records, the verifier catching tampered stock;
- the screens: access per role, 404s, entering a purchase and usage, errors on the right line, totals that leave out reversed entries, the stock card, the cost report, settings.

## Problems found and fixed during M5
1. **Usage account names could be too long.** A 100-character material name plus " used" would not fit the account name. Material names are now limited to 90 characters.
2. **Very large purchases could overflow** while spreading charges over the lines (the multiplication can pass 64-bit numbers). The split now uses big-number arithmetic.
3. **Totals vanished while typing.** The purchase form hid the goods total until every line was complete. It now totals the complete lines as you go.
4. **Material tabs and the reverse dialog** relied on page details that are not available during live updates. They now receive what they need directly, so the active tab and the dialog stay right after every update.
5. **Two earlier tests updated** after the first full run, both for correct new behaviour: the trolley unit can no longer be archived while Kassu, Tuka and waste fuel use it, and the new "Material shortages" account (5900) is a system account, not an expense category.

## Your decisions
- **Units of soil, Kassu, Tuka, waste and Bora (Q-10, Q-12).** Set as cubic feet, trolley and bag for now; each kiln can change a unit until stock is recorded in it.
- **Charges in the material cost (Q-11).** Trolley and excavator charges are part of the material's cost, as proposed. Say if you would rather see them as separate expenses.

## Upgrading an existing installation
After deploying M5: `php artisan migrate`, then `php artisan kiln:defaults` (adds the default materials and the new accounts to existing kilns), then `php artisan permissions:sync`.

## Not in M5 (comes with later modules)
- **Fuel used per firing:** M6 records firing entries and their fuel through the same usage logic.
- **Paying suppliers and the trolley driver:** already possible with M4 payment vouchers.
- **Stock value in the balance sheet and P&L:** M9 (the books already hold it).
- **"Found extra stock" correction:** not in v1 (D-056).
