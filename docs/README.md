# Project Documentation — Brick Kiln Management System

| Document | Contents |
|---|---|
| [01-reference-analysis.md](01-reference-analysis.md) | What the reference "Bricks ERP" really is, its schema and flows, and the data-integrity problems found |
| [02-feature-matrix.md](02-feature-matrix.md) | Every feature: reference evidence vs. owner requirement vs. recommendation |
| [03-architecture-and-data-model.md](03-architecture-and-data-model.md) | Stack, modules, tenancy, money handling, double-entry accounting, stock ledger, ERD |
| [04-implementation-plan.md](04-implementation-plan.md) | Modules M0–M11, Definition of Done, acceptance examples |
| [05-open-questions.md](05-open-questions.md) | Questions still open, grouped by the module that needs them |
| [06-decision-and-assumption-log.md](06-decision-and-assumption-log.md) | Decisions made, assumptions to confirm, environment notes |
| [modules/M0-foundation.md](modules/M0-foundation.md) | M0 report: login, roles, kiln separation, English/Urdu, design system |
| [modules/M1-kiln-settings.md](modules/M1-kiln-settings.md) | M1 report: kiln profile, products/grades, stages, units, areas, expense categories |
| [modules/M2-accounting-core.md](modules/M2-accounting-core.md) | M2 report: double-entry journal, cash/bank accounts, transfers, owner capital, cash book, trial balance |
| [modules/M3-parties.md](modules/M3-parties.md) | M3 report: customers, suppliers, drivers, sales agents, opening balances, party statements |
| [modules/M4-payments-and-expenses.md](modules/M4-payments-and-expenses.md) | M4 report: payment and receipt vouchers, expenses by category, reversals, payments/receipts and expense reports |
| [modules/M5-materials.md](modules/M5-materials.md) | M5 report: materials, purchases with extra charges, usage, stock at average cost, stock card, material cost report |
| [modules/M6-production.md](modules/M6-production.md) | M6 report: Pathai, loading, firing with fuel, Nakaasi by grade, brick stock by stage and grade, daily production view |
| [modules/M7-labour.md](modules/M7-labour.md) | M7 report: workers and categories, rate cards with dates, wages from production, attendance and salaries, advances and deductions, settlements with payslips, labour cost |
| [modules/M8-sales.md](modules/M8-sales.md) | M8 report: sales with stock reservation, deliveries with carriage, payments per sale, credit notes and write-offs, invoice PDF in English and Urdu, invoice emails with retries, drivers due, statement PDF |
| [modules/M9-reports.md](modules/M9-reports.md) | M9 report: dashboard, reports page, profit and loss, balance sheet, day close with lock, sales and production summaries, cost per 1,000 bricks, who owes us |
| [07-deployment.md](07-deployment.md) | Putting the system on a server: hosting, install, HTTPS, worker and scheduler, Google Drive copies, updating, restoring backups |
| [08-uat.md](08-uat.md) | User acceptance testing: the partner's test script, the owner review, reporting problems |
| [modules/M11-hardening.md](modules/M11-hardening.md) | M11 report: permissions review, security, backups with Google Drive copies and restore drill, email settings in the app, speed with years of data, setup check, deployment and UAT |

Source documents: `../BUSINESS_REQUIREMENTS.md`, `../AI_AGENT_IMPLEMENTATION_PLAN.md`, `../Refrence Project/`.
