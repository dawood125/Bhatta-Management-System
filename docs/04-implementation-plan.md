# 04 — Phased Implementation Plan

We build **one module at a time**. A module is finished only when it passes the full **Definition of Done** below. Then you review it and say "go", and the next module starts. Each module is built on top of a fully tested previous module.

## Definition of Done (applies to every module)

1. **Agreed examples first.** Before any money or stock code, the module's worked examples and expected totals are written into this plan. You confirm them, and they become automated tests.
2. **Database:** migrations with typed foreign keys (`RESTRICT` on financial data), indexes, and `kiln_id` on every operational table.
3. **Logic:** every write goes through a domain Action (transaction + locks + audit log + idempotency).
4. **Screens:** responsive desktop / tablet / mobile layouts, with loading, empty, validation-error, success, permission-denied and error states. Keyboard friendly.
5. **Automated tests (Pest):**
   - Unit tests for every calculation, including edge cases (zero, rounding, large numbers).
   - Feature tests for every action: happy path, validation, permissions, **kiln isolation**, duplicate submission, rollback on failure.
   - Invariant tests: every journal entry balances; stock is never negative; statement totals = journal totals.
6. **Browser check:** my own walkthrough in the built-in browser at desktop and phone widths, in English and Urdu (automated Chromium tests were dropped, D-025).
7. **Quality gates:** the full test suite is green, Larastan shows no errors, and Pint is clean. I report the exact commands and their real output.
8. **Docs updated:** decision log, assumptions, a list of changed files, and known limitations.
9. **Your sign-off**, then on to the next module.

---

## M0 — Foundation and design system
- **Build:**
  - Laravel 13 project in the repository root
  - git repository initialised
  - Laragon MySQL databases `gbricks_dev` / `gbricks_test`
  - `.env.example`
  - Tailwind + Livewire + Vite
  - Pest, Larastan, Pint
- **Core primitives:**
  - Kiln scoping (one kiln per installation, multi-kiln ready)
  - `kiln:install` command for first-time setup (kiln + owner account)
  - Login and logout, password change, login throttling, deactivated users blocked
  - Roles and permissions per kiln; user management
  - Activity log, plus a viewer screen
  - `Money` value object and cast
  - **English / Urdu** with RTL layout, per-user language switch, Urdu font
  - (The idempotency helper and number sequences move to M2, where the first documents use them — D-019)
- **Design system:**
  - Colour, type, spacing, radius and elevation tokens
  - App shell (sidebar, top bar, kiln switcher)
  - Core components: buttons, inputs, selects, money/qty inputs, tables with search, sort and pagination, modals, toasts, badges, empty states
  - A component preview page
- **Acceptance:**
  - Login works.
  - A user sees only kilns they belong to.
  - A user from kiln A gets "not found" for kiln B records (automated test).
  - `Money` arithmetic tests pass, including rounding (for example, 1,200/1,000 × 11,500 = 13,800.00).
  - The UI shell is reviewed by you on desktop and mobile.

## M1 — Kiln setup and master data
- **Build:**
  - Kiln profile (name, address, phone, logo, currency PKR, date format)
  - Units
  - Products / grades (kind: brick grade / by-product / traded item; unit; saleable flag)
  - Production stage names (mapped to the fixed stage kinds)
  - Locations / areas
  - Expense categories and sub-categories
  - Document number formats
  - Seed data: the default grades seen in the reference and in the owner's notes, all clearly editable
- **Acceptance:** settings are stored per kiln and changing them in kiln A does not affect kiln B. Products cannot be deleted once used (only archived).

## M2 — Accounting core and treasury accounts
- **Build:**
  - Chart of accounts (seeded system accounts)
  - Journal posting engine (balanced, immutable, reversal)
  - Cash / bank / wallet / card accounts with opening balances
  - Transfers between accounts
  - Owner capital in/out
  - Cash book per account
  - Trial balance
  - `ledger:verify` command
- **Acceptance examples:**
  - Opening balances: Cash 50,000; Bank 200,000. The trial balance shows Σ debit = Σ credit.
  - Transfer 20,000 Bank → Cash gives Cash 70,000 and Bank 180,000, and both cash books show the row.
  - Voiding that transfer returns the balances to 50,000 / 200,000, with both the original and the reversal visible.
  - Two identical submissions with the same idempotency key create one transfer.

## M3 — Parties and statements
- **Build:**
  - Party master with configurable roles (Customer, Supplier, Kiln/Marka, Driver, Contractor…)
  - Opening balances (receivable or payable)
  - Locations
  - Party statement (all-time and date range), shown as Debit / Credit / running Balance with clear "Receivable / Payable" wording
  - Printable PDF statement
- **Acceptance:**
  - A customer with an opening receivable of 10,000 shows a closing balance of 10,000 Dr.
  - The statement total equals the AR control-account balance for that party.

## M4 — Payments, receipts and expenses
- **Build:**
  - Multi-line **payment voucher** and **receipt voucher** (the reference UX: one account, many lines to parties or to other accounts)
  - Expenses with category / sub-category
  - Void with reason
  - Expense report and payments / receipts report
- **Acceptance:**
  - A payment voucher from Cash 70,000 paying Supplier A 30,000 and Driver B 5,000 leaves Cash at 35,000.
  - After that voucher, Supplier A's payable falls by 30,000 and Driver B's by 5,000.
  - The voucher's journal entry balances.

## M5 — Materials and procurement *(needs Q-10…Q-12)*
- **Build:**
  - Materials (Soil, Kassu, Kola/Coal, Bora, Tuka, waste, other) with units
  - Material suppliers
  - Purchase entries with extra charges (trolley / transport, excavator)
  - Material consumption entries
  - Material stock and weighted-average cost
  - Material cost report
- **Acceptance:**
  - Buying 1,000 units at 50 + 5,000 trolley charge puts 1,000 units in stock and records a cost of 55,000.
  - Consuming 400 units leaves 600 units in stock.
  - Material stock can never go negative.

## M6 — Production and brick stock *(needs Q-13…Q-16)*
- **Build:**
  - Daily raw-brick (Pathai) entry per worker/team (made, rejected, accepted)
  - Stage movements (Kamyaar)
  - Firing entries with fuel consumption
  - "Fired, awaiting Nakaasi" balance
  - Nakaasi / unloading with grade split
  - Stock adjustments with a reason
  - Daily production view (all stages)
  - Stock by grade
  - Discrepancy flags (negative, duplicates, unexplained differences)
- **Acceptance (example):**
  - Day 1: made 12,000, rejected 500, so 11,500 are raw-ready.
  - Moving 11,000 into firing leaves 500 raw-ready and puts 11,000 in firing.
  - Firing complete moves the 11,000 to awaiting Nakaasi.
  - Nakaasi of 11,000 splits into Awal 8,000 / Doom 2,000 / Khingar 700 / Tooty 300. Saleable stock rises by exactly 11,000 and awaiting-Nakaasi falls to 0.
  - Moving more than the available quantity is rejected.

## M7 — Labour and payroll *(needs Q-20…Q-25)*
- **Build:**
  - Workers and labour categories
  - Rate cards with effective dates (per 1,000 bricks, per day, per month, lump sum)
  - Work entries, generated from production entries where linked
  - Attendance for salaried staff
  - Advances and deductions
  - **Weekly / monthly settlement runs** (earned − advances − deductions = payable → paid → remaining)
  - Mistri / team contracts (lump sum to the contractor, **not** counted again as individual payroll)
  - Worker ledger
  - Payslip / receipt PDF
- **Acceptance (examples using the owner's rates, all configurable):**
  - Pathai: 11,500 accepted × 1,200 / 1,000 = 13,800.00. Advance 5,000; settlement pays 8,800; remaining 0.
  - Kamyaar: 11,000 × 410 / 1,000 = 4,510.00.
  - Nakaasi: 11,000 × 325 / 1,000 = 3,575.00.
  - Mistri contract of 250,000 appears once in labour cost, never twice.

## M8 — Sales, invoices, PDF and email *(needs Q-01, Q-04, Q-30…Q-35)*
- **Build:**
  - Customer sales orders (by grade, qty, rate)
  - Stock reservation and/or deduction on dispatch, as agreed
  - Partial dispatch
  - Invoice with gap-free number and PDF
  - Receipts allocated to invoices or kept on account
  - Credit sales and partial payments
  - Cancellation / return with reversal
  - Queued **email to the owner with the invoice PDF attached**, with a delivery log, retry and "resend"
  - **Carriage (D-013):** optional driver + carriage on each sale. The customer is charged the carriage, and it becomes payable to the driver (not income). A daily "drivers due" list feeds the driver payment voucher
  - Optional sales agent on the sale (D-012)
  - Trading orders: **not in v1** (D-011)
- **Acceptance:**
  - An order of 5,000 Awal @ 15,000 / 1,000 = 75,000.00. Dispatch moves Awal stock from 8,000 to 3,000.
  - A receipt of 50,000 leaves 25,000 receivable.
  - An order for more than the saleable stock is refused.
  - The email is logged as sent with the PDF attached. A simulated SMTP failure is logged and retried.

## M9 — Reports and dashboard *(needs Q-40…Q-42)*
- **Build:**
  - All reference reports, rebuilt on the journal (party / account statements, date-wise ledgers, payables / receivables, expense reports, orders / sales reports, summary)
  - Production reports (daily, by stage, by grade)
  - Labour reports
  - Material / fuel cost reports
  - P&L and balance sheet from the trial balance
  - Daily cash reconciliation / day close
  - Dashboard
  - Print / PDF export
- **Acceptance:**
  - Every report total matches the known test transactions.
  - The balance sheet balances.
  - P&L net profit = the change in retained earnings.

## M10 — (dropped) Import from the old system
Not needed (Q-03 / D-017).

## M11 — Hardening, backup, deployment, UAT
- **Build:**
  - Permissions review
  - Security checklist
  - Performance check with realistic data volumes (≈100k ledger rows)
  - Scheduled backups and a restore test
  - Deployment guide for the production server
  - User acceptance testing with you / the owner
  - Final fixes

---

## Questions needed before each module

| Before | Questions (see `05-open-questions.md`) |
|---|---|
| M0 | Q-05 (language), Q-06 (multi-kiln login). Defaults are proposed, so M0 can start now |
| M1 | Q-13, Q-16 (stages and grades). Seed data can be changed later |
| M3 | Q-01, Q-02 (trading scope, meaning of "Supplier") |
| M5 | Q-10, Q-11, Q-12 |
| M6 | Q-13…Q-16 |
| M7 | Q-20…Q-25 |
| M8 | Q-01, Q-04, Q-30…Q-35 |
| M9 | Q-40…Q-42 |
| M10 | Q-03 |
| M11 | Q-07 |
