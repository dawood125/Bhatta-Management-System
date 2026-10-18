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

## Assumptions (to be confirmed)

| ID | Assumption | Source | Confirm by |
|---|---|---|---|
| A-001 | In reference orders, brick qty is in thousands and rates are per 1,000 bricks | Order data (qty 6 × 22,000 = 132,000) | You |
| A-002 | In the reference, a party `balance` > 0 = we owe them; < 0 = they owe us | Traced ledger rows | — (reference only) |
| A-003 | Currency is PKR with 2 decimals; date format DD-MM-YYYY | Reference screens | Q-05 / M1 |
| A-004 | The reference "Location" is a customer area / route, not a kiln site | Party and order data | Q-02 |
| A-005 | The carriage charged to the customer equals what the driver receives (the bhatta keeps no margin) | Owner's description (Q-04) | Q-27 |
| A-006 | Drivers are independent tractor-trolley owners (parties), not salaried employees | Reference data (435 driver parties) + Q-04 | You |

## Environment notes

- Local tools: PHP 8.3.30 (Laragon), Composer 2.9.5, MySQL 8.4.3 (Laragon), Node 26, npm 11, Python 3.13, git 2.50.
- PHP `zip` and `sockets` extensions were enabled in Laragon's `php.ini` during M0 (needed by Composer/backups and the Pest browser plugin).
- The reference dump was loaded into a local scratch database **`ref_gbricks`** for analysis only. It can be dropped at any time.
