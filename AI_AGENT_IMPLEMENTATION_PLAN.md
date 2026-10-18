# AI Coding Agent Instructions — Brick Kiln Management System

## Mission

Plan and implement a configurable brick-kiln management product for Pakistan. The user has provided:
1. `BUSINESS_REQUIREMENTS.md` — discovery notes from a meeting with one kiln owner.
2. A reference software ZIP that the user will extract into a root-level folder named `reference project`.

**The reference project is the primary functional and UI reference for the intended product.** The business requirements document is context about the owner's needs, vocabulary, and example operating practices. Do not treat its incomplete notes as the final implementation blueprint and do not replace the reference workflows with guesses.

Do not begin a large implementation immediately. First inspect the repository and reference project, produce a grounded analysis, and propose a phased implementation plan.

## Phase 1 — Repository and Reference Inspection

1. Inspect the repository root, current application, package manifests, framework versions, scripts, environment-variable examples, tests, and existing conventions.
2. Locate `reference project/`. If it contains a ZIP rather than extracted files, stop and tell the user the exact ZIP path and ask them to extract it; do not attempt destructive operations or silently modify the reference folder. The user has said they may extract it manually.
3. Once extracted, recursively inspect the reference project's structure. Identify its frontend/backend architecture, frameworks, routing, authentication, database, schema/models, migrations, API endpoints, reusable components, styles, assets, and build/test commands.
4. Map its screens and flows, especially:
   - Dashboard and daily production/output
   - Raw brick production and stage transitions
   - Firing and fired-but-not-yet-unloaded inventory
   - Unloading/grade sorting and stock
   - Materials, fuel, purchases, and costs
   - Labour categories, piece-rate calculations, salaries, advances, settlements, contractor/team payments
   - Customers, orders, sales, invoices, receipts, credit balances
   - Sales/material/sack or other ledgers
   - Balance sheet, daily cash/balance reconciliation, expenses, stock valuation
   - Reports, notifications, and email/PDF generation
   - User/role management and multi-kiln configuration
5. Trace important calculations from UI to backend to persistence and reports. Identify relationships and transaction boundaries. Do not infer correctness from a screen alone.
6. If source code is unavailable for a feature, inspect available UI, assets, routes, network/API evidence supplied by the user, and screenshots. Do not claim to have verified internals that are not visible.
7. Create a feature matrix with columns: `Feature`, `Reference evidence`, `Owner requirement`, `Gap/uncertainty`, `Recommendation`, `Files/modules likely affected`.
8. Identify which reference patterns should be reused, which need improvement, and any security, data-integrity, scalability, or maintainability issues.

## Phase 2 — Reconcile Requirements and Ask Focused Questions

Compare the reference project with `BUSINESS_REQUIREMENTS.md`.

- Use the reference project to fill in details missing from the owner's meeting notes wherever the evidence is clear.
- Preserve the owner's explicitly stated business constraints, especially:
  - Rates vary by kiln and must be configurable.
  - The example rates are from one owner and are not national fixed rates.
  - The customer, according to the meeting, arranges and pays for transport of purchased bricks.
  - Fired bricks that have not yet been unloaded must be distinguishable from bricks already unloaded and available for sale.
  - The owner wants an email notification for a new order/sale with a PDF invoice attached.
- Flag contradictions between the reference project and meeting notes instead of silently choosing one.
- Make a list of unanswered questions only after inspecting the reference. Group questions by business-critical, accounting-critical, operational, and cosmetic.
- Ask the user for clarification when a missing answer changes data structure, financial calculations, stock behavior, access control, or workflow. Ask concise, concrete questions and explain why each answer matters.
- Do not invent the meaning of terms such as `Kassu/Kasoo`, `Kamyaar`, `Barai/Burai`, `Julai`, `Sack ledger`, or `Coil cost`. Keep original labels and mark them as unconfirmed until verified.

## Phase 3 — Proposed Architecture and Data Integrity

After inspection, recommend an architecture compatible with the existing repository and reference project. Do not change frameworks or introduce a new stack without a concrete reason and user approval.

Before coding, present:
1. Repository/reference findings.
2. Feature comparison matrix.
3. Proposed architecture and module boundaries.
4. Proposed entity relationship diagram or equivalent data model.
5. Core workflow/state-transition diagram.
6. API/route plan.
7. Authentication, authorization, and tenant isolation plan.
8. Accounting/ledger transaction strategy.
9. Test strategy.
10. Phased implementation plan with dependencies, deliverables, and acceptance criteria.
11. Risks, assumptions, and the questions that must be answered before implementation.

The user expects accurate relationships among production, inventory, sales, payroll, ledgers, and finance. Design these as connected domain records, not disconnected dashboard totals.

### Data-model design considerations

Inspect the reference model first. Then determine the appropriate entities, potentially including:
- Business/Owner and Kiln/Site
- User, Role, Permission, and membership/access assignment
- Material, Unit, Supplier, Purchase, PurchaseLine, StockMovement, MaterialConsumption
- ProductionBatch/Run, ProductionStage, StageMovement, RawBrickEntry, FiringBatch, Unloading/GradingEntry
- BrickGrade/Product, FinishedStockMovement
- Worker, LabourCategory, WorkEntry, RateConfiguration, Salary/PayrollPeriod, Advance, Deduction, Settlement
- Contractor/Mistri, Team, ContractorPayment
- Customer, Order, OrderLine, Invoice, Payment/Receipt, Return/Cancellation
- Expense, Cash/BankAccount, Journal/ledger entry, account mapping, opening balance
- Notification/EmailLog and AuditLog

These are candidate concepts, not mandatory tables. Avoid duplicate entities or unnecessary complexity if the reference project already has a sound model. Explain the chosen relationships and cardinalities.

### Non-negotiable integrity rules to evaluate

- Every business transaction belongs to the correct kiln/tenant.
- Never trust a client-supplied tenant ID without verifying the user's access.
- Record stock movements so quantities can be audited.
- Distinguish raw, in-process/firing, fired-awaiting-unloading, unloaded/graded, reserved, and sold quantities according to the confirmed workflow.
- Prevent sales beyond available stock unless an explicit, approved business rule allows it.
- Make financial and stock-changing operations atomic/transaction-safe where supported by the database.
- Prevent duplicate submissions from creating duplicate sales, payments, payroll, or stock movements.
- Preserve audit history for edits, voids, and adjustments to important records.
- Do not silently delete posted financial transactions; use reversal/void patterns consistent with the reference system.
- Derive reports from the underlying records using consistent rules.
- Avoid counting a contractor/mistri payment both as a lump-sum expense and as individual payroll unless the business accounting design explicitly calls for it.
- Store currency and decimal precision appropriately; use integer minor units or a safe decimal strategy, not binary floating-point for financial arithmetic.
- Define how opening and closing balances, credit sales, customer receipts, advances, expenses, payables, receivables, and stock valuation interact before implementing a balance sheet.
- Test reconciliation invariants and edge cases.

## Phase 4 — UI/UX Direction

The UI should feel deliberately designed, premium, modern, and production-ready—not like a generic AI-generated admin template.

Requirements:
- Review the reference project's visual language and retain useful proven interaction patterns.
- Use a coherent color system with documented design tokens, typography, spacing, border radii, elevation, and states.
- Choose readable professional fonts and consistent high-quality icons from a single suitable icon set.
- Use a clear information hierarchy, polished tables/forms, useful filters/search, understandable status indicators, and consistent page layouts.
- Make frequent tasks fast: record production, move bricks to a stage, record unloading/grades, record a material purchase, pay a worker, and create a sale.
- Use responsive layouts for desktop, tablet, and mobile.
- Add restrained, purposeful transitions and animations; avoid animation that slows down repetitive operational tasks.
- Include loading, empty, validation, success, permission-denied, and error states.
- Ensure accessibility: keyboard focus, labels, contrast, sensible touch targets, and non-color-only status cues.
- Do not use decorative charts that do not answer an operational question.
- Do not invent data in the live application. Seed/demo data must be clearly marked.
- Keep UI components reusable and consistent, but do not overabstract simple screens.

Before finalizing the design system, provide a concise visual direction and a list of key screens. If a design choice would significantly diverge from the reference, explain why.

## Phase 5 — Implementation Sequence

Propose a plan based on actual reference findings. A possible sequence, to be adjusted after inspection:

1. **Foundation:** repository setup, environment validation, coding conventions, database/migrations, tenant/kiln scoping, auth/roles, audit primitives.
2. **Configuration:** kiln profile, materials/units, labour categories/rates, production stages, grades, expense categories, email settings.
3. **Procurement and materials:** suppliers, purchases, charges, stock movements, material cost reporting.
4. **Production:** raw-brick entries, quality/rejection, stage movements, firing batches, fuel consumption, fired-awaiting-unloading quantities.
5. **Unloading and finished stock:** grade sorting, unloading labour, finished stock ledger and reconciliation.
6. **Labour/payroll:** piece-rate earnings, salary-based staff, advances, deductions, settlements, mistri/contractor payments.
7. **Customers and sales:** customer records, orders, invoices/PDFs, payment receipts, credit/receivable tracking, stock reservation/deduction.
8. **Finance and ledgers:** account mapping, transaction posting, daily balance, cash/bank, expenses, receivables/payables, agreed reports and reconciliation.
9. **Notifications:** configurable email trigger, PDF attachment, delivery logs, retry/error handling, and secrets stored securely.
10. **Reports/dashboard:** daily production, stage-wise quantities, stock by grade, sales, labour, material/fuel costs, balances, ledgers.
11. **Quality and release:** automated tests, permissions review, data validation, responsive UI/accessibility, backups, deployment plan, and user acceptance testing.

Do not implement every phase in one giant change. Deliver in reviewable increments, run relevant tests, and report exact changed files and remaining gaps after each phase.

## Phase 6 — Tests and Acceptance

Create or update tests based on the actual stack and existing testing conventions. At minimum, plan tests for:
- Tenant/kiln isolation and role permissions.
- Configurable rates and units.
- Piece-rate calculations per configured unit, advances, deductions, and settlement balances.
- Contractor/mistri payment without accidental double counting.
- Stage transitions and quantities across raw, firing, fired-awaiting-unloading, unloaded/graded, and sold.
- Rejected bricks and quality adjustments.
- Stock availability, reservation/dispatch, cancellations, and returns according to the agreed sales flow.
- Material purchases, usage, and remaining stock.
- Invoice totals, payment balances, and PDF generation.
- Email notification behavior, including failures/retries and attachment presence.
- Ledger posting and opening/inflow/outflow/closing reconciliation.
- Daily and period reports against known test transactions.
- Duplicate submissions and transaction rollback on failure.

Define acceptance criteria with concrete examples and expected totals before building financial features.

## Phase 7 — Working Rules for the Agent

- Start with inspection and planning, not broad code generation.
- Do not overwrite or alter the reference project.
- Do not unzip, delete, rename, or move user files without permission.
- Do not assume that one owner's rates are universal.
- Do not fabricate APIs, source files, schema details, or completed inspections.
- Do not expose secrets from environment files or copy them into reports.
- Do not add dependencies without explaining why and checking compatibility.
- Do not change the stack without evidence and user approval.
- Reuse good reference behavior while documenting any deviations.
- When you cannot inspect a file or feature, state the limitation and request the specific evidence needed (screen, route, API response, schema, or screenshot).
- Keep an assumptions log and decision log.
- Keep all planning documents and generated reports in an agreed documentation folder or repository root; do not scatter files.
- Before major or destructive changes, explain the action and ask for approval.
- Report test commands and actual outcomes. Never say tests passed unless they were run and passed.

## First Response Required From the Agent

After reading this file and `BUSINESS_REQUIREMENTS.md`, the agent should:
1. Confirm which relevant files/folders it can actually see.
2. Inspect the repository and reference project (or identify the exact blocker).
3. Summarize the reference architecture and the most important business workflows it can verify.
4. Produce the feature matrix and proposed implementation plan.
5. List only the questions that remain unresolved after inspection.
6. Wait for user review/approval before starting a large implementation.

Do not start by generating the entire application. The first deliverable is a verified analysis and an implementation plan grounded in the actual reference project.
