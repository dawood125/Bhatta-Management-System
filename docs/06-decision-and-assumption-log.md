# 06 — Decision and Assumption Log

## Decisions

| ID | Date | Decision | Made by | Status |
|---|---|---|---|---|
| D-001 | 2026-10-18 | Use **Laravel** for the new system | User | Final |
| D-002 | 2026-10-18 | All planning documents live in `docs/` at the repository root | User | Final |
| D-003 | 2026-10-18 | The saved data in `Refrence Project/` is demo data. It is still never copied into the new project, docs or seed data | User / agent | Final |
| D-004 | 2026-10-18 | Build module by module. Each module is fully tested (automated + browser) and signed off before the next starts | User | Final |
| D-005 | 2026-10-18 | UI stack: Laravel 13.37, Blade + Livewire 4.4 + Alpine + Tailwind 4; tests with **Pest 4** (Pest 5 needs PHP 8.4, local PHP is 8.3) | Agent | Final (built in M0) |
| D-006 | 2026-10-18 | Money stored as integer paisa (`BIGINT`); no floats anywhere | Agent | **Proposed** |
| D-007 | 2026-10-18 | Double-entry journal behind all money screens; posted entries are immutable; corrections by reversal | Agent | **Proposed** |
| D-008 | 2026-10-18 | Brick and material stock kept as movement ledgers; stage balances derived; never negative | Agent | **Proposed** |
| D-009 | 2026-10-18 | Tenancy: Business → Kilns; `kiln_id` on all operational data, enforced by global scope + policies + tests | Agent | **Proposed** |
| D-010 | 2026-10-18 | Production modules are designed from the owner's notes plus your answers, because the reference has no production features | Agent | Final (fact) |
| D-011 | 2026-10-18 | Trading orders (buy from another kiln and resell) are **not in v1**; the design leaves room to add them as a module | User + agent (Q-01) | Final |
| D-012 | 2026-10-18 | Reference "Supplier" = sales agent/dealer with their own customers and cash. v1 has an optional Sales Agent role on customers and sales, and "cash held by person" accounts | Agent from data (Q-02) | Final; commission open |
| D-013 | 2026-10-18 | Carriage collected from the customer is a **pass-through payable to the driver**: not income, not expense. Drivers are paid by voucher; a "drivers due" report shows what each is owed | User (Q-04) | Final |
| D-014 | 2026-10-18 | UI in **English and Urdu** (RTL), language chosen per user; all text in translation files; Urdu font self-hosted; PDFs via **mPDF** (dompdf cannot join Urdu letters) | User (Q-05) | Final |
| D-015 | 2026-10-18 | **One kiln per client installation** (separate database per client). `kiln_id` is kept on all data so multiple kilns per owner can be enabled later; no kiln switcher until needed | Agent recommendation (Q-06) | Final |
| D-016 | 2026-10-18 | Hosting: Contabo VPS. Deploy and test on it early (after M0/M2), not only at the end | User (Q-07) | Final |
| D-017 | 2026-10-18 | No import from the old system; M10 dropped | User (Q-03) | Final |
| D-018 | 2026-10-18 | Auth backend: Laravel Fortify (login throttling, password rules; 2FA possible later) with our own screens | Agent | Final |
| D-019 | 2026-10-18 | Idempotency keys and document-number sequences are built in M2, together with the first real documents (not speculatively in M0) | Agent | Final |
| D-020 | 2026-10-18 | Login with **username or email** (kiln staff often have no email). Forgotten passwords are reset by the owner/manager; email reset is enabled once SMTP exists | Agent | Final |
| D-021 | 2026-10-18 | Default roles: Owner, Manager, Munshi/Accountant, Data entry, Viewer. Each module adds its permissions to the `Permission` enum; `php artisan permissions:sync` applies them | Agent | Final |
| D-022 | 2026-10-18 | Business rules: nobody can change their own role or deactivate themselves; only an owner can create or change an owner; a kiln always keeps at least one active owner | Agent | Final |
| D-023 | 2026-10-18 | Timezone Asia/Karachi. Urdu UI uses Inter for Latin letters and digits and Noto Nastaliq Urdu for Urdu letters (self-hosted fonts, no CDN), one step larger type and taller lines | Agent | Final |
| D-024 | 2026-10-18 | Working product name "Bhatta Manager" (`APP_NAME` in `.env`, so it can be changed per client) | Agent | Provisional |
| D-026 | 2026-10-18 | Production stages are 4 fixed kinds (raw, in firing, fired awaiting Nakaasi, saleable) that kilns can rename but not add, remove or reorder; rejected, sold and broken bricks leave stages through movements (M6) | Agent, using defaults for Q-13 | Final unless Q-13 answer changes it |
| D-027 | 2026-10-18 | Product types: fired grade, raw brick, by-product. Fired grades and raw bricks must use the built-in Brick unit | Agent | Final |
| D-028 | 2026-10-18 | Master data (products, units, areas, expense categories) is archived, never deleted; names are unique per kiln in English and Urdu | Agent | Final |
| D-029 | 2026-10-18 | Currency fixed to PKR and date display DD-MM-YYYY for v1 (no per-kiln setting) | Agent | Final for v1 |
| D-030 | 2026-10-18 | Kiln logo: PNG/JPG/WebP up to 1 MB on the public disk; SVG refused (can carry scripts); edited by the owner only | Agent | Final |
| D-031 | 2026-10-19 | Journal: one entry per transaction, debits = credits, lines one-sided and positive (also a DB CHECK); entries and lines are immutable; corrections are reversal entries with a reason | Agent (from D-007) | Final |
| D-032 | 2026-10-19 | Document numbers are gap-free per kiln, prefix and year (OB, TR, CP, RV …), taken inside the posting transaction under a row lock | Agent (from D-019) | Final |
| D-033 | 2026-10-19 | Every money form carries a one-time idempotency key; reusing it returns the first entry instead of posting again | Agent (from D-019) | Final |
| D-034 | 2026-10-19 | Cash/bank/wallet/person accounts can't go negative unless "allow negative" (overdraft) is on; credit cards are liabilities whose balance is the amount owed | Agent | Final |
| D-035 | 2026-10-19 | Opening balances post against an "Opening balances" equity account; changing one reverses the old entry on its own date and posts the new one | Agent | Final |
| D-036 | 2026-10-19 | System-generated descriptions are not stored; they are built at display time in the viewer's language. Only typed notes are stored | Agent | Final |
| D-037 | 2026-10-19 | Amounts accept both "150,000" and lakh-style "1,50,000" (commas ignored); more than 2 decimals is refused | Agent | Final |
| D-025 | 2026-10-18 | No automated real-browser (Chromium) test suite: it was very slow on the development PC. The Definition of Done uses feature tests (incl. Livewire) plus a manual check in the built-in browser (desktop + phone, English + Urdu) | User | Final |
| D-038 | 2026-10-19 | One party record can have several roles: customer, supplier, driver, sales agent (`party_roles` table). No Marka role in v1 (no trading, D-011); labour contractors are decided in M7 | Agent | Final for v1 |
| D-039 | 2026-10-19 | Party money lives in two control accounts, 1100 Accounts receivable and 2100 Accounts payable. Every line on them names a party; lines on any other account never do. The posting engine refuses violations and `ledger:verify` re-checks them | Agent | Final |
| D-040 | 2026-10-19 | A party's balance is debits minus credits over both control accounts: positive = receivable (they owe us, لینا), negative = payable (we owe them, دینا). Totals net each party first, then add up receivables and payables separately | Agent | Final |
| D-041 | 2026-10-19 | Statement columns are labelled Debit (banam) / Credit (jama), as in local hand ledgers, and every running balance carries a Receivable / Payable / Settled badge | Agent | Final |
| D-042 | 2026-10-19 | "Add party" carries a one-time key (`creation_key`, unique per kiln): submitting the same form twice returns the first party. Two different people may still share a name; the form warns about similar names | Agent | Final |
| D-043 | 2026-10-19 | Only customers have a sales agent (D-012), and it must be an active party with the sales-agent role. An agent can't lose that role while customers still point to them. Parties are archived only at a zero balance, never deleted | Agent | Final |
| D-044 | 2026-10-19 | M3 statements print from the browser with a print layout (no sidebar, kiln name and period on top). The server-side, Urdu-capable PDF is built with invoice PDFs in M8 and then added to statements | Agent | Final |
| D-045 | 2026-10-19 | Permissions: `parties.view` for Owner, Manager, Munshi and Viewer; `parties.manage` (add, edit, opening balances, archive) for Owner, Manager and Munshi. Data entry has no access. Every change is in the activity log, and a changed opening balance keeps both the old entry and its reversal | Agent | Provisional: owner may restrict opening-balance changes to Owner/Manager |
| D-046 | 2026-10-19 | Each party posts to one control account: receivables if it has the customer role, payables otherwise (opening balances and vouchers alike). A customer refund or advance, or a supplier advance, then stays in the same account as the rest of that party's business. Replaces the M3 rule "receivable openings to 1100, payable openings to 2100" | Agent | Final |
| D-047 | 2026-10-19 | Payment and receipt vouchers are journal entries (types `payment` / `receipt`, numbers `PV-…` / `RCV-…`), with no separate voucher table. Line 1 is always the voucher's cash/bank account with the full total; each other line is a party or one of the kiln's own accounts (as in the reference). At most 30 lines | Agent | Final |
| D-048 | 2026-10-19 | Each expense category gets its own expense account (codes 5001–5999), created with its first expense and renamed with the category. An `expenses` row keeps the category, sub-category, account and details; the money side is its `EX-…` entry. Sub-categories are optional and must belong to the category | Agent | Final |
| D-049 | 2026-10-19 | Vouchers and expenses are corrected only by reversal with a reason (the plan's "void"), by Owner or Manager (`transactions.reverse`). New permissions `payments.record` and `expenses.record` go to Owner, Manager and Munshi; viewing uses `accounts.view` | Agent | Final |
| D-050 | 2026-10-19 | Vouchers may not be dated before the opening date of any account or party on them (same rule as cash/bank accounts) | Agent | Final |

## Assumptions (to be confirmed)

| ID | Assumption | Source | Confirm by |
|---|---|---|---|
| A-001 | In reference orders, brick qty is in thousands and rates are per 1,000 bricks | Order data (qty 6 × 22,000 = 132,000) | You |
| A-002 | In the reference, a party `balance` > 0 = we owe them; < 0 = they owe us | Traced ledger rows | — (reference only) |
| A-003 | Currency is PKR with 2 decimals; date format DD-MM-YYYY | Reference screens | Q-05 / M1 |
| A-004 | The reference "Location" is a customer area / route, not a kiln site | Party and order data | Q-02 |
| A-005 | The carriage charged to the customer equals what the driver receives (the bhatta keeps no margin) | Owner's description (Q-04) | Q-27 |
| A-006 | Drivers are independent tractor-trolley owners (parties), not salaried employees | Reference data (435 driver parties) + Q-04 | You |
| A-007 | Default grades and Urdu spellings: Awal اوّل, Doom دوم, Khingar کھنگر, Tile ٹائل, Tooty ٹوٹا, Rora روڑا (sold per trolley), Kachi کچی اینٹ (not for sale) | Owner's notes + reference product list | Owner (Q-16) |
| A-008 | Default stage names: Raw bricks (Pathai), In the kiln (Julai), Fired awaiting Nakaasi, Ready for sale | Owner's notes | Owner (Q-13) |

## Environment notes

- Local tools: PHP 8.3.30 (Laragon), Composer 2.9.5, MySQL 8.4.3 (Laragon), Node 26, npm 11, Python 3.13, git 2.50.
- PHP `zip` and `sockets` extensions were enabled in Laragon's `php.ini` during M0 (needed by Composer/backups and the Pest browser plugin).
- The reference dump was loaded into a local scratch database **`ref_gbricks`** for analysis only. It can be dropped at any time.
