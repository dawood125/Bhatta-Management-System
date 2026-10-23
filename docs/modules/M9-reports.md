# M9 — Reports and Dashboard: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-23
**Branch:** `m9-reports` (based on `m8-sales`)

## What M9 delivers

### Dashboard
The dashboard now shows the kiln today. Each section appears only for those who may see it: a data-entry user sees production, the owner sees everything.
- **Day close banner:** "Today is not closed yet" with a link to close it, or "Today is closed".
- **Money:** cash and bank (with today's money in and out), what others owe us (لینا), what we owe others (دینا), and wages due.
- **Today:** sold today, Pathai accepted, Nakaasi (unloaded), bricks delivered.
- **Brick stock now:** raw, in the kiln or awaiting Nakaasi, ready for sale, and free to sell.
- **Charts:**
  - **Income and costs, last six months**: a column chart; hover or tab to a month for the amounts, or "Show as a table".
  - **Costs this month**: by kind, with the month's profit.
- Every tile opens its page. Quick buttons: New sale, Record production, Payments and receipts.

### Reports
**Reports** is a new item in the sidebar: one page with every report, grouped by area (Money, Customers and suppliers, Sales, Kiln). Each report is listed only for those who may open it.

| Report | What it shows | Who |
|---|---|---|
| **Profit and loss** (new) | Income and the costs of a period by kind (materials and fuel used, labour, running expenses, other), and the profit; print and PDF | Owner, Manager |
| **Balance sheet** (new) | What the kiln owns, owes and is worth on a date. It always balances, and says so; print and PDF. Bricks in stock are listed in quantity | Owner, Manager |
| **Trial balance** | (M2) now under Reports | Owner, Manager |
| **Day close** (new) | Each cash and bank account's day (opening, in, out, in the books), the cash counted against it with the difference, "Close the day", and the history of closed days | Owner, Manager, Munshi; reopen: Owner |
| **Sales summary** (new) | Sales of a period by grade (ordered, delivered, amount), by customer (billed, still to pay) and by sales agent | Everyone with sales access |
| **Production summary** (new) | Stock of every stage and grade at the start and end of a period with what came in and went out; Pathai made/accepted, loaded, fired, unloaded; Nakaasi by grade; deliveries by grade; day by day | Everyone with production access |
| **Cost per 1,000 bricks** (new) | Materials and fuel, labour and running expenses of a period per 1,000 bricks out of the kiln, next to what 1,000 bricks sold for, and the margin | Owner, Manager |
| **Who owes us** (new) | Every customer who owes the kiln, split by the age of their unpaid sales (up to 30, 31–60, 61–90, over 90 days) | Everyone with party access |
| Cash and bank books, payments and receipts, expenses by category, party statements, deliveries, drivers due, daily production, material and fuel cost (coal and Bora), labour cost | (earlier modules) listed on the Reports page | as before |

## How the figures are kept correct
- **One source.** Every report and every dashboard figure comes from the same journal and stock movements, so the totals always agree with each other and with the trial balance.
- **Profit and loss** counts materials and fuel when they are used, and labour when it is earned. Carriage collected for drivers is neither income nor a cost.
- **Balance sheet:** customers who owe us are an asset and advances from customers a liability; workers' advances are an asset and wages due a liability. The profit to date is part of the owner's equity. Assets always equal liabilities plus equity.
- **Bricks in stock are not valued (Q-42).** Their cost is already in the profit. The balance sheet lists them by quantity. Material stock (coal, Bora, soil…) is an asset at its average cost (M5).
- **Day close (Q-41).** The cash is counted against the books and the day is closed. A difference is shown, not posted: the owner decides what it was (e.g. records a missing expense) before closing. After closing, nothing can be dated on or before that day, except by the owner. This covers money, production, deliveries and reversals; the person sees a clear message. Only the owner can reopen the last closed day, with a reason. Closed days are never deleted.

## Agreed acceptance examples — pass as automated tests
1. **Every report total matches the known test transactions** (sale, receipt, coal bought and used, wages, advance, expenses):
   - profit and loss: income 75,000, costs 46,000, profit 29,000;
   - balance sheet totals: 1,094,000;
   - production: 11,000 made, loaded, fired, unloaded; Awal 8,000 → 3,000 after the delivery;
   - cost per 1,000 bricks: 4,181.82;
   - sales by grade, customer and agent;
   - who owes us, by age ✔
2. **The balance sheet balances** (today and on an earlier date) ✔
3. **P&L net profit = the change in retained earnings** over the period ✔

## Test results (real runs, 2026-10-23, Ubuntu with MariaDB 10.11)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **706 passed, 0 failed (3,838 assertions)**, of which 21 are new in M9 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` on the demo data | **consistent** |
| Migration | the two new day-close tables run forward and back on MariaDB |
| Urdu | every new word and sentence is translated (0 missing) |
| Manual check in the built-in browser | Desktop, tablet and phone, English and Urdu: dashboard (with the chart tooltip and table view), the Reports page, profit and loss, balance sheet, day close (counted, closed, then reopened by the owner), sales summary, production summary, cost per 1,000 bricks, who owes us, and the profit and loss and balance sheet PDFs. No page is wider than a phone screen; in Urdu the chart reads right to left |

The M9 tests cover:
- the three acceptance examples, with every report total checked against the known transactions;
- the balance sheet today and on an earlier date; net profit equal to the change in retained earnings;
- day close: the cash counted against the books, the lock on money, production, deliveries and reversals for everyone but the owner, reopening only the last closed day and only by the owner, closing an earlier day, closing a day twice;
- who may open each report and the PDFs (every role), the Reports page and menu showing only what a user may open, the dashboard showing each role only its own figures, the chart's axis, and the Urdu PDF.

## Problems found and fixed during M9
1. **The dashboard was wider than a phone screen** (by about 54 pixels at 375 wide): the chart's month columns and their labels did not shrink. They do now, in both languages.
2. **The chart's axis went up to 200,000 for a month of 109,000**, which flattened the columns. The axis now picks clean steps that fit the data (here up to 150,000).
3. **The "Close the day" confirmation was empty.** It now lists each account with the amount counted and any difference before you confirm.
4. **A data-entry user's "Bricks delivered" tile opened the deliveries page, which that role may not see** (it showed "Forbidden"). For users without sales access it now opens daily production. Found by the full test run.

## Your decisions
These follow the proposed defaults in 05-open-questions; say if your kiln works differently.
- **"Sack ledger" and "coil cost" (Q-40):** taken as **Bora** and **Coal (Kola)** cost. Both are rows of the material and fuel cost report (M5), listed on the Reports page as "Material and fuel cost".
- **Day close (Q-41):** counted per account; a closed day is locked for everyone but the owner; the owner can reopen the last closed day. Tell me if the munshi should not close days, or if days should close automatically.
- **Brick stock value (Q-42):** quantity only on the balance sheet; costs are counted when spent. A valuation at average cost can be added later if you need it.
- **Cost per 1,000 bricks:** costs of a period divided by the bricks unloaded (Nakaasi) in it. Over a whole month it is a good guide; on a single day it can mislead, because costs also go into bricks still raw or in the kiln.
- **Not in M9:** profit by customer or by supplier (the reference's trading reports), a fixed-asset register, charts on every report, and an automatic day close.

## Not in M9 (comes with M11)
- Performance check with realistic data volumes (about 100,000 ledger rows), backups, deployment, the security review and user acceptance testing.
