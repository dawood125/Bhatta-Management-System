# M2 — Accounting Core: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-19
**Branch:** `m2-accounting-core` (based on `m1-kiln-settings`)

## What M2 delivers

Under **Money** in the sidebar:

| Screen | What you can do | Who |
|---|---|---|
| **Cash and bank** | All accounts with live balances and the total money available. Add or edit accounts: cash in hand, bank, mobile wallet, cash with a person, credit card. Archive empty accounts | View: Owner, Manager, Munshi. Manage: Owner, Manager |
| **Transfer** | Move money between two of the kiln's accounts | Owner, Manager, Munshi |
| **Owner capital** | Money in (capital) or money out (withdrawal) | Owner only |
| **Cash book** (per account) | Opening balance, every movement with a running balance, closing balance. Quick ranges: today, this month, last month, all | Owner, Manager, Munshi |
| **Transactions** | Every transaction, newest first, with its debit/credit lines. Reverse a transfer or capital entry, with a reason | View: Owner, Manager, Munshi. Reverse: Owner, Manager |
| **Trial balance** | Balances of all accounts on any date, with a clear "Balanced" indicator | Owner, Manager |

Also: `php artisan ledger:verify` re-checks the entire ledger independently (all kilns) and exits with an error if anything is wrong.

## How the money is kept correct
- **Double entry.** Every transaction is one journal entry whose debits equal its credits. Every report reads from those entries; no running total is stored that could drift (the reference system's main fault).
- **Saved entries can't be changed or deleted.** A correction is a *reversal*: a mirror entry with a reason, dated today. The original stays visible, marked "Reversed".
- **The database protects itself too.** Each line must be a positive debit or a positive credit (a CHECK constraint), and money is stored as whole paisa.
- **No double posting.** Each form carries a one-time key, so a double-click or a retried request returns the first entry instead of creating a second.
- **Gap-free numbers per kind and year:** `OB-2026-0001` (opening), `TR-…` (transfer), `CP-…` (capital), `RV-…` (reversal). A failed save rolls its number back.
- **No negative cash.** Cash, bank, wallet and person accounts can't go below zero unless "Allow negative balance" (overdraft) is switched on. The check runs under a database lock, so two people can't spend the same money at the same moment.
- **Opening balances** are entries against an "Opening balances" equity account. Changing one later reverses the old entry on its own date and posts the new one, so history stays exact. An opening date can't be moved after existing transactions.
- **No dates before an account's opening date, and no future dates.**
- **Kiln separation:** an account of another kiln gives "not found", including in the cash book URL.
- **Amounts:** "13,800", "1250.50" and lakh-style "1,50,000" are all understood. More than 2 decimals or text is refused.

## Agreed acceptance examples — all pass as automated tests
1. Opening balances Cash 50,000 + Bank 200,000 → trial balance 250,000 = 250,000 ✔
2. Transfer 20,000 Bank → Cash → Cash 70,000, Bank 180,000, shown in both cash books ✔
3. Reverse that transfer → back to 50,000 / 200,000, with both entries kept ✔
4. The same submission twice → only one transfer ✔

Example 1 and 2 were also checked by hand through the screens. The trial balance shows Cash 70,000 Dr, HBL 180,000 Dr and Opening balances 250,000 Cr, balanced.

## Test results (real runs, 2026-10-19)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **326 passed, 0 failed (1,296 assertions)**, of which 79 are new in M2 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` on the demo data | **consistent** |
| Manual check in the built-in browser | Desktop and phone, English and Urdu: cash and bank cards, add account, transfer, cash book, transactions, trial balance |

The M2 tests cover:
- posting rules (balanced, at least two lines, positive one-sided lines, own-kiln accounts only, no future dates);
- full rollback on failure;
- immutability, including the database refusing a two-sided line;
- numbering per year and per kiln;
- reversal rules (only once, never a reversal of a reversal, never dated before the original);
- the negative-balance guards;
- opening-balance changes;
- cash book running balances and credit-card behaviour;
- the trial balance by date and per kiln;
- the verifier detecting tampered data;
- the screens, permissions per role, and 404 for another kiln's cash book.

## Problems found and fixed during M2
1. **Route order:** URL lookups such as `/accounts/{account}` ran before the kiln was known. The kiln check now runs first, so another kiln's account gives "not found" instead of an error.
2. **Card layout:** edit and archive buttons wrapped onto a second line. They now sit in the card header.
3. **Phone table:** the transactions and cash book description column was squeezed unreadably narrow on phones. It now has a minimum width, and the table scrolls inside its box.
4. **Hidden dialogs:** they were included in the page even for users without permission. Now they're only rendered for people allowed to use them.
5. **Saved descriptions:** system-written descriptions are no longer stored. They're built at display time, so they always appear in the viewer's language; only typed notes are stored.
6. **Midnight flaw:** two date tests computed "tomorrow" when the test file loaded and failed when a run crossed midnight. They now compute the date when each test runs.

## Not in M2 (comes with later modules)
- **Customers, suppliers and their balances:** M3.
- **Payment and receipt vouchers to parties, and expenses:** M4. Each will post through the same engine.
- **P&L, balance sheet and daily cash reconciliation:** M9.
- **Printing / PDF of the cash book:** M9.
