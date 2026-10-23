# M3 — Parties and Statements: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-19
**Branch:** `m3-parties` (based on `m2-accounting-core`)

## What M3 delivers

**Parties** is the first item under **Money** in the sidebar.

| Screen | What you can do | Who |
|---|---|---|
| **Parties** | All customers, suppliers, drivers and sales agents with their live balance. Totals at the top: what parties owe the kiln, and what the kiln owes parties. Tabs by type, search by name (English or Urdu) or phone, filter by area and status | View: Owner, Manager, Munshi, Viewer. Manage: Owner, Manager, Munshi |
| **Add / edit party** | Type (one or more of customer, supplier, driver, sales agent), name in English and Urdu, two phones, address, area, sales agent (customers only), note, and an opening balance: amount, "they owe us" or "we owe them", and the date | Owner, Manager, Munshi |
| **Archive / restore** | Hide a party from new entries. Only possible when the balance is zero | Owner, Manager, Munshi |
| **Statement** (per party) | Balance brought forward, every transaction with Debit (banam), Credit (jama) and a running balance, and the closing balance. Any date range, plus This month / Last month / All. **Print** gives a clean page with the kiln name and period | Owner, Manager, Munshi, Viewer |

One person can hold several roles: someone who sells you coal and also buys bricks is one party with one balance.

## How the balances are kept correct
- **Two control accounts.** Everything parties owe sits in *Accounts receivable* (1100); everything the kiln owes sits in *Accounts payable* (2100). *(Changed in M4, D-046: customers always post to receivables and everyone else to payables, whichever way the balance runs.)* Every line on these accounts names its party, and lines on other accounts never do. The posting engine refuses anything else, and `php artisan ledger:verify` checks it again.
- **One balance per party:** debits minus credits. Positive means **Receivable** (they owe us, لینا); negative means **Payable** (we owe them, دینا); zero shows **Settled**. Nothing is stored that could drift: lists, totals and statements all read the journal.
- **Opening balances** post against *Opening balances* equity, exactly like cash and bank accounts. Changing one later reverses the old entry on its own date and posts the new one, so both stay in the history. The opening date can't be moved after the party's existing transactions.
- **No duplicates from a double click.** The "Add party" form carries a one-time key: sending it twice returns the first party and posts the opening balance once. Two different people with the same name are still allowed, and the form warns when similar names already exist.
- **Sales agents** (the reference system's "Supplier", see D-012): only customers have one, and it must be an active party with the sales-agent role. An agent keeps that role while any customer still points to them.
- **Archive, never delete,** and only at a zero balance, so no money can disappear with a party.
- **Kiln separation:** another kiln's party gives "not found", including its statement URL; another kiln's area or agent is refused.
- **Every change is logged** in the activity log, including type (role) changes.

## Agreed acceptance examples — all pass as automated tests
1. A customer with an opening receivable of 10,000 shows a closing balance of 10,000 receivable (Dr) ✔
2. The statement total equals that party's balance in the receivable control account ✔. A second test checks that the sum of all party balances always equals receivables minus payables.

Example 1 was also checked by hand: a demo customer with an Urdu name, phone, area and a 10,000 opening receivable. The list total, the row badge and the statement all show Rs 10,000 receivable, and `ledger:verify` reports the ledger consistent.

## Test results (real runs, 2026-10-19)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **366 passed, 0 failed (1,446 assertions)**, of which 40 are new in M3 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` on the demo data | **consistent** |
| Manual check in the built-in browser | Desktop and phone, English and Urdu: parties list and totals, add party with an Urdu name, statement, print layout |

The 40 M3 tests cover:
- saving parties: roles, Urdu names, phones, areas, sales-agent rules, and validation of every field;
- opening balances in both directions, zero openings, changing the amount and direction, and the opening-date guard;
- ledger rules: party required on receivable/payable lines, refused elsewhere, refused from another kiln, kept on reversals;
- the verifier catching a receivable line without a party and an opening balance that doesn't match its entry;
- statements: balance brought forward, running balance, invalid date range;
- archiving only at zero, a double submit creating one party, permissions per role (also when the action is called directly), and 404 for another kiln's party.

## Problems found and fixed during M3
1. **Double submit:** sending the "Add party" form twice created a second party that shared the first one's opening entry. The form now carries a one-time key, stored on the party, so the second send returns the first party.
2. **Similar-names check** kept running after the dialog closed. It now runs only while the form is open.
3. **Statement description:** opening-balance rows said "Opening balance" twice (badge and text). The repeated text is hidden.
4. **Confusing column names:** "Debit (they owe more)" was replaced with the familiar *banam* / *jama* wording, with a Receivable / Payable badge on each balance.

## Your decision
- **Opening-balance changes by the Munshi.** Right now the Munshi can add and edit parties, including opening balances, because customers are added every day. Every change is logged and the old entry stays visible next to its reversal. If you prefer that only the Owner or Manager can change an opening balance once a party exists, it's a small change.

## Not in M3 (comes with later modules)
- **Receipts from customers, payments to suppliers and drivers:** M4 vouchers, posted through the same engine to the same control accounts.
- **Sales on credit and carriage owed to drivers:** M8.
- **Server-side PDF statement (Urdu-capable) and emailing it:** M8, together with invoice PDFs. M3 prints from the browser.
- **Ageing and receivable/payable reports by area or agent:** M9.
- **Marka (other kilns) and trading:** not in v1 (D-011).
