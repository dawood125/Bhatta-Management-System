# M4 — Payments, Receipts and Expenses: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-19
**Branch:** `m4-vouchers` (based on `m3-parties`)

## What M4 delivers

Two new items under **Money** in the sidebar.

| Screen | What you can do | Who |
|---|---|---|
| **Payments and receipts** | All vouchers for a period, with total paid and total received. Filter by type, account, party or note. Quick ranges: today, this month, last month, this year. **Print** gives the "payments and receipts" report | View: Owner, Manager, Munshi |
| **New payment / New receipt** | One cash/bank account and up to 30 lines. Each line is a party (customer, supplier, driver, agent) or one of the kiln's own accounts, with an amount and remarks. Each party's current balance shows as you pick them, and so does the account's balance after the voucher | Owner, Manager, Munshi |
| **Voucher page** | Every line, the note, who recorded it, and its reversal if any. **Print** gives a voucher slip with "Prepared by / Received by / Approved by" lines | View: Owner, Manager, Munshi. Reverse: Owner, Manager |
| **Expenses** | All expenses for a period, the total, and a total per category. Filter by category, account or details. **Print** gives the expense report | View: Owner, Manager, Munshi |
| **Add expense** | Amount, date, category, sub-category (optional), paid from, details. **Save and add another** keeps the date, account and category for the next one | Owner, Manager, Munshi. Reverse: Owner, Manager |

The party picker searches by English name, Urdu name or phone, so it stays quick with a thousand or more customers.

## How the money is kept correct
- **One voucher = one balanced journal entry** (`PV-2026-0001` for payments, `RCV-2026-0001` for receipts). Line 1 is always the voucher's own cash/bank account with the full total; `ledger:verify` checks that.
- **Payment:** money leaves the account. A party line lowers what we owe them (or raises what they owe us).
  **Receipt:** money comes in. A party line lowers what they owe us (or raises what we owe them).
  A line to one of our own accounts moves the money between the two accounts, as the reference system did.
- **Each party has one control account.** Customers stay in *Accounts receivable* and everyone else in *Accounts payable*, for opening balances and vouchers alike. So a refund to a customer, or an advance to a supplier, stays in the same account as the rest of that party's business (see D-046).
- **Expenses** debit the category's own expense account (codes 5001, 5002 … created the first time a category is used, and renamed with it) and credit the cash/bank account (`EX-2026-0001`). Category, sub-category and details are kept for the report; `ledger:verify` checks every expense against its entry.
- **No negative cash, no future dates, no dates before an account's or a party's opening date**, the same rules as M2. The balance check runs under a database lock and rolls the whole voucher back.
- **No double posting:** each form has a one-time key, so a double click returns the first voucher or expense.
- **Nothing is edited or deleted.** A mistake is **reversed** with a reason (Owner or Manager). The voucher and its reversal both stay visible, on the voucher page, the statements and the cash book; totals leave reversed items out.
- **Archived parties, accounts, categories and sub-categories can't be used**, and anything from another kiln gives "not found".

## Agreed acceptance examples — all pass as automated tests
1. A payment voucher from Cash 70,000 paying Supplier A 30,000 and Driver B 5,000 leaves Cash at 35,000 ✔
2. After that voucher Supplier A's payable falls by 30,000 and Driver B's by 5,000 ✔
3. The voucher's journal entry balances ✔

The same example was also run through the screen test (pick the parties, type the amounts, save) and checked by hand in the browser.

## Test results (real runs, 2026-10-19)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **454 passed, 0 failed (1,809 assertions)**, of which 87 are new in M4 (and 1 new party test) |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` on the demo data | **consistent** |
| Manual check in the built-in browser | Desktop and phone, English and Urdu: the acceptance example entered through the payment screen (PV-2026-0001, Cash 70,000 → 35,000), the voucher page, two expenses with "Save and add another", expense totals by category, the voucher list. No page is wider than a phone screen. The print layouts were checked by their print styles, not on paper |

The 87 M4 tests cover:
- the acceptance example, receipts, refunds to customers, lines to the kiln's own accounts, remarks and notes, separate numbering;
- every validation rule, 1 to 30 lines, archived and other-kiln parties/accounts/categories, opening-date and future-date rules;
- the no-negative-cash guard (on the voucher's account and on account lines), overdraft accounts, credit cards;
- double submits, reversals and their effect on every balance, permissions (also when an action is called directly);
- expense accounts per category (created once, numbered 5001, 5002 …, renamed with the category, rolled back on failure);
- the verifier catching a tampered voucher or expense;
- the screens: access per role, 404s, entering vouchers and expenses, errors on the right line, totals that leave out reversed items, filters, reversing from the list and the voucher page, descriptions on statements and cash books.

## Problems found and fixed during M4
1. **Customers and suppliers could split across two accounts.** Under the M3 rule, a customer's advance went to payables while their sales go to receivables, so a refund would have left balances in both. Each party now has one control account (D-046). The M3 test was updated to match.
2. **Statements and cash books said "Accounts payable"** instead of who was paid. Descriptions now name the party ("Paid to …", "Received from …"), and the transactions list shows the party and remarks on each line.
3. **Letters typed straight after opening the party picker were lost** (they arrived before the search box had focus). Typing on the picker now starts the search with those letters.
4. **Old error messages stayed on screen after the field was corrected**, until the next save. In the voucher and expense forms a field's error now clears as soon as it changes.

## Not in M4 (comes with later modules)
- **Receipts allocated to individual invoices:** M8, when invoices exist. Until then a receipt lowers the customer's balance.
- **Daily "drivers due" list for carriage (D-013):** M8, with sales.
- **Expenses on credit (a bill paid later):** with purchases in M5; M4 expenses are paid from an account at once.
- **P&L, balance sheet and daily cash reconciliation:** M9.
- **Amount in words on the printed voucher:** later, if you want it.
