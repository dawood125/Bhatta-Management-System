# Brick Kiln Management System — Business Requirements

**Document status:** Discovery draft based on the owner's interview  
**Product scope:** A configurable software product intended for brick kilns (bhatta) in Pakistan  
**Important:** Rates and practices below are examples reported by one kiln owner. They are not national standards and must not be hard-coded.

## 1. Product Vision

Build a reliable, configurable brick-kiln management system that helps kiln owners monitor production from raw-brick preparation through firing, unloading, stock, sales, labour payments, materials, ledgers, and financial reports.

The product is intended for multiple brick kilns. Each kiln must be able to configure its own rates, labour categories, process details, materials, and accounting settings without changing application code.

A separate existing software project is available in the repository under `reference project/` (the user will extract its ZIP). The reference software is the primary product/functional reference. These interview notes describe the owner's requirements and business context; they are not a complete implementation specification and must not override evidence found in the reference project.

## 2. Business Context and Goals

The owner wants to:
- See daily production and the status of bricks across each production stage.
- Know how many bricks are raw, sent to the next process, currently being fired, already fired but not yet unloaded, unloaded/graded, ready for sale, and sold.
- Manage labour types, piece-rate work, salary-based staff, advances, weekly settlements, and contractor/team payments.
- Track material purchases and fuel/material costs.
- Manage customers, sales, stock deductions, invoices, ledgers, balances, and reports.
- Reconcile daily balances and understand the money and stock currently available.
- Receive an email when a new sale/order is created, with a PDF invoice attached.

## 3. Production Workflow Reported by the Owner

Treat this as the initial workflow to verify against the reference project and actual users. Names are transcribed phonetically and may require confirmation.

1. **Material procurement**
   - Soil is purchased using a per-foot unit, as described by the owner.
   - Transport/trolley charges and excavator charges may also be incurred.
   - The owner mentioned a material pronounced “Kassu/Kasoo” that is mixed with soil. Its exact identity, unit, quantity method, and pricing basis are unconfirmed.
   - Material units, rates, suppliers, purchase entries, and related charges should be configurable.
2. **Raw-brick making and laying**
   - Workers make raw (unfired) bricks and lay them out to dry/set.
   - The owner described piece-rate payment based on the number of acceptable bricks made/laid.
   - Poor-quality or rejected raw bricks are not counted for payment, according to this owner's practice.
   - The owner's example rate was approximately PKR 1,000–1,500 per 1,000 bricks. This is approximate and must be configurable.
   - Workers may receive advances before the weekly settlement. The remaining amount is settled, reportedly each week.
3. **“Kamyaar” worker / movement to the next stage**
   - The owner described a labour category pronounced “Kamyaar” that moves raw bricks to the next stage/area.
   - The reported example rate was PKR 400–420 per 1,000 bricks, with a possible commission component that was not fully explained.
   - Two additional workers help arrange/place the bricks in the required order for the next stage. Their pay basis/rate was not confirmed.
   - The exact spelling, responsibilities, payment calculation, and whether commission applies must be confirmed from the reference project or owner.
4. **“Burai/Barai”**
   - The owner described “Barai/Burai” workers or a mason role. The role is reportedly salary-based.
   - Exact duties, staffing, salary period, and the correct local term require verification. Do not assume that it means a specific kiln engineering task solely from the word.
5. **Firing / “Julai”**
   - Bricks are fired/burned in the kiln.
   - The owner mentioned fuel/materials including “Kola” and “Bora” as primary items, and “Tuka” and waste material as other items. Exact material names and units need confirmation.
   - The owner described lines/sections around the kiln and a process in which bricks are arranged/covered and fire is applied. The precise physical workflow is not fully known.
   - One “mistri” (supervisor/contractor) manages a team of approximately five or six workers (number can vary). The kiln pays the mistri an example amount of PKR 250,000, and the mistri distributes it to their team. Payment period and whether this is per firing, batch, month, or another interval are not confirmed.
   - The team size, payment basis, fuel consumption, and firing/batch records must be configurable and confirmed.
6. **Unloading / “Nakaasi”**
   - Workers remove fired bricks from the kiln and place them outside/into stock for sale.
   - The owner's example rate is PKR 325 per 1,000 bricks unloaded.
   - This is one owner's rate, not a fixed national rate.
   - The owner described output grades/types as “Awal,” “Dom,” “Tile,” “Khingar,” and “Rora.” Preserve these labels as provisional categories until spelling, definitions, and whether all are saleable grades are confirmed.
   - Quantities by grade should be recorded when bricks are unloaded/sorted.

## 4. Production and Inventory Requirements

The system should provide a daily production view showing:
- Number of raw bricks made/laid.
- Number accepted and rejected, where the kiln tracks quality.
- Number moved to the next stage.
- Number currently in firing.
- Number fired but not yet unloaded (“fired, awaiting nakaasi”).
- Number unloaded and sorted by grade.
- Current saleable stock by grade.
- Number sold and remaining stock.

Use explicit stage transitions and maintain an auditable quantity history. Do not treat “fired” and “unloaded/available for sale” as the same status. Stock should only become available for sale at the business-defined stage, to be confirmed against the reference application.

Quantities must reconcile. The system should flag discrepancies, unexplained adjustments, negative stock, duplicate entries, and invalid stage movements. Support correction entries with a reason and audit history rather than silently overwriting transactions.

## 5. Labour, Payroll, and Advances

The product should support configurable labour categories and payment methods, including:
- Piece-rate labour (rate per 1,000 bricks or another configurable unit).
- Salary-based workers/mistris.
- Team/contractor payments where a mistri receives a lump sum and distributes it to their own team outside the kiln's direct payroll.
- Worker advances, deductions, outstanding balance, and settlement history.
- Weekly or other configurable settlement cycles.
- Quality/rejection rules where applicable.
- Worker/team records and payment receipts.

The system must distinguish:
1. Amount owed/earned by a worker,
2. Advance already paid,
3. Additional deductions/adjustments,
4. Amount paid at settlement,
5. Remaining payable balance.

Do not double-count the mistri's lump-sum payment as both a contractor expense and individual worker payroll unless the actual accounting model explicitly requires it.

## 6. Materials, Fuel, and Expense Tracking

Track purchase/usage and cost for configurable materials, including the provisionally named:
- Soil
- Kassu/Kasoo (identity and unit unconfirmed)
- Kola
- Bora
- Tuka
- Waste material
- Other kiln-specific materials

Each material should have configurable units, purchase rates, suppliers, opening stock (if needed), purchases, usage/consumption, and current balance. Support related expenses such as trolley/transport and excavator charges. These procurement/production costs belong to the kiln's records unless later confirmed otherwise.

Do not assume the customer pays for kiln materials. The reported customer-paid transport arrangement applies to delivery of sold bricks, as described below.

## 7. Sales, Customers, Orders, and Invoices

The owner expects customer and sales management:
- Customer records and sales history.
- Sales/order entry with brick grade/type, quantity, rate, total, payment status, and date.
- Invoice generation, preferably as a PDF.
- Sale should reduce the relevant saleable stock.
- Support payment collection and customer balance/ledger tracking.
- Show order details and financial totals clearly.
- Send the owner an email notification when a new order/sale is recorded, attaching the generated PDF invoice.

**Transport rule reported by the owner:** The customer arranges and pays for the trailer/tractor/trolley/truck used to take the bricks to the customer's destination. Therefore, do not automatically include delivery/transport charges as a kiln expense or kiln-paid service. If the reference software supports optional delivery fields, inspect their semantics and keep them configurable; do not contradict the owner's described default.

Clarify whether a sale/order can be created before physical pickup, whether stock is reserved at order time or deducted at dispatch, how partial payments/credit sales work, and whether the email goes out for every order, only confirmed sales, or a configurable event.

## 8. Ledgers, Finance, and Reports

The owner mentioned:
- Sales ledger.
- “Coil cost” ledger/cost (term uncertain; could be a transcription of a material/cost term and must be verified).
- Bora cost.
- Sack ledger (exact meaning uncertain).
- Balance sheet / overall balance.
- Daily balances and reconciliation.
- Remaining stock.
- Production/output reports.

The system should offer well-defined ledgers and reports for transactions, income, expenses, payables, receivables, stock, and production. The exact chart of accounts, accounting method, report layout, and meaning of “sack ledger” and “coil cost” must be discovered from the reference software and/or confirmed with the owner.

Financial reports must be based on consistent underlying transactions, not manually calculated unrelated totals. The system should reconcile opening balance + inflows - outflows = closing balance for the relevant cash/bank account and period, with clear treatment of credit sales, customer receipts, expenses, advances, payroll, and stock valuation. Do not claim statutory accounting compliance until requirements are verified.

## 9. Multi-Kiln Configuration

This is a product for multiple kilns, not a single owner's custom tool. Each kiln/business should be able to configure:
- Kiln name, address, and business details.
- Currency (default likely PKR, but verify), date format, and locale.
- Materials and units.
- Labour categories, rate basis, rates, salary cycles, advances, and settlement rules.
- Production stages and grade names.
- Fuel and other cost categories.
- Invoice numbering, branding, and invoice fields.
- Notification recipients and email preferences.
- Accounting/reporting settings.
- Users, roles, and permissions.

Rates and categories must be data/configuration, not constants embedded in code. Ensure records are tenant/kiln scoped so one kiln cannot see or change another kiln's data. Confirm whether one owner can manage multiple kilns under one account and whether staff can work across locations.

## 10. User Experience and Operational Needs

The interface should be professional, attractive, fast, and easy for real kiln operators to understand. Common tasks such as entering production, paying labour, recording a purchase, and making a sale should be straightforward. Provide clear tables, readable typography, meaningful icons, accessible contrast, useful empty/loading/error states, and restrained purposeful animations.

The application should support responsive use on desktop and mobile-sized screens. Consider Urdu/local terminology only after language requirements are confirmed. English is the current planning-document language; it does not imply the application must be English-only.

## 11. Security, Auditability, and Reliability

At minimum, evaluate and plan for:
- Authentication and role-based permissions.
- Tenant/kiln data isolation.
- Server-side validation and authorization.
- Audit history for financial, stock, and configuration changes.
- Safe handling of invoice PDFs and email configuration/secrets.
- Backups and recovery.
- Error logging and useful error messages.
- Protection against duplicate submissions and inconsistent financial transactions.
- Tests for stock, payroll, ledgers, and reconciliation.

The exact deployment, hosting, backup retention, email provider, authentication method, and compliance needs are not yet specified.

## 12. Reference Project and Source of Truth

The user has an extracted or to-be-extracted ZIP in a root-level folder named `reference project`. The reference software is highly important and is expected to be the closest guide to the intended product.

The implementation agent should inspect the reference project's screens, workflows, data models, relationships, calculations, reports, ledgers, payroll, sales, and UI. Use it to resolve details missing from these notes. These notes capture the owner's discussion; they should not be treated as the complete build plan or as permission to ignore the reference project.

Do not copy secrets, credentials, real customer data, or proprietary assets into the new project. Confirm that the user is authorized to reuse the reference material and follow applicable licensing/IP constraints.

## 13. Open Questions / Unconfirmed Details

Do not silently guess these. Inspect the reference project first and then ask the user only for unresolved decisions that materially affect implementation:
1. Exact spelling/meaning/unit and usage of Kassu/Kasoo.
2. Exact spelling and duties of Kamyaar, Barai/Burai, Julai, and Nakaasi.
3. Whether labour rates are per 1,000 bricks, per day, per batch, per week, or another period in each case.
4. Exact basis and period for the PKR 250,000 mistri/team payment.
5. How piece-rate quality rejection is recorded and approved.
6. What commission applies to Kamyaar, and how it is calculated.
7. Exact roles and pay for the two workers helping with brick placement.
8. Fuel/material units, stock consumption, and costing method.
9. Definitions and accounting meaning of “sack ledger” and “coil cost.”
10. Grade definitions and whether “Tile,” “Khingar,” and “Rora” are product grades, by-products, or other categories.
11. Whether stock is reserved on order or deducted at dispatch/pickup.
12. Credit sales, partial payments, customer advances, returns, cancellations, and bad debts.
13. Cash/bank accounts, opening balances, chart of accounts, and balance-sheet/report definitions.
14. Email recipients, email provider, and event that triggers an invoice notification.
15. Multi-kiln account ownership, roles, and access boundaries.
16. Required languages, hosting, offline/poor-connectivity behavior, and backup expectations.

## 14. Acceptance Outcomes

A first release should not be considered complete until the agreed scope supports:
- Configurable kiln-specific rates, categories, stages, and units.
- Traceable production quantities through raw, firing, fired-awaiting-unloading, unloaded/graded, saleable, and sold states.
- Labour earnings, advances, settlements, and contractor payments without double counting.
- Material purchases/usage and associated costs.
- Customer sales and invoice PDF generation.
- Correct stock deduction/reservation according to the confirmed sales workflow.
- Consistent ledgers and financial summaries that reconcile to underlying transactions.
- New-order email notification with PDF attachment, if configured.
- Clear reports for daily production, stock by grade, sales, labour/payables, materials/costs, and financial balances.
- Tenant isolation, permissions, audit history, and automated tests for critical calculations.
- A UI reviewed against the reference project's intended workflows and the user's premium-quality design expectations.
