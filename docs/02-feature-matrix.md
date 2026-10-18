# 02 — Feature Matrix

**Legend for "Reference evidence":**
- ✅ = a screen and tables exist and hold data
- ⚠️ = built but unused or partial
- ❌ = absent

Module codes (M0–M11) refer to `04-implementation-plan.md`.

| Feature | Reference evidence | Owner requirement (BR doc) | Gap / uncertainty | Recommendation | Module |
|---|---|---|---|---|---|
| Login, users, rights | ✅ users + spatie permissions; per-user module checkboxes | §9, §11: users, roles, permissions | No per-kiln access | Roles + permissions scoped per kiln (spatie "teams"); ready-made roles (Owner, Manager, Munshi/Clerk, Viewer) that can be edited | M0 |
| Multi-kiln / tenant isolation | ❌ single company | §9: product for many kilns | Can one owner run several kilns from one login? (Q-06) | Business → Kilns; every record has `kiln_id`; a kiln switcher for users with access to several | M0 |
| Audit history | ❌ | §4, §11 | — | Activity log on every financial, stock and settings change; void/adjust always needs a reason | M0 |
| Kiln profile and settings | ❌ | §9: name, address, currency, date format, invoice branding | — | Settings screen per kiln | M1 |
| Units of measure | ❌ (qty has no unit) | §6: configurable units | Soil "per foot" unit unclear (Q-10) | Units table (bricks, 1,000 bricks, ton, kg, cft, trolley, bag…) with conversion where meaningful | M1 |
| Products / grades | ✅ `product_types` (AWAL, DOOM, KHINGAR, TILE, KACHI, TOOTY…) | §3.6: Awal, Dom, Tile, Khingar, Rora | Is "Rora" a grade? Are Tooty/Barsati grades or by-products? (Q-16) | Configurable products with kind (brick grade / by-product / traded material), unit and sale flag | M1 |
| Production stages | ❌ | §3, §4: raw → moved → firing → fired-awaiting-Nakaasi → unloaded/graded → saleable → sold | Exact stage list and names (Q-13) | Configurable stage names on top of fixed system stage *kinds* that drive the logic | M1, M6 |
| Locations | ✅ `locations` (used on parties and orders) | — | Meaning: customer areas *(inferred)* | Keep as party/sales area | M1 |
| Cash / bank accounts | ✅ 41 accounts incl. cards, wallets, cash-with-person | §8: daily balances, cash/bank | Stored balances drift (see 01 §5) | GL-backed treasury accounts; opening balances via journal | M2 |
| Account transfers | ✅ voucher lines of type "Account" | §8 | — | Transfer screen + voucher line | M2, M4 |
| Capital add / withdraw | ⚠️ screens exist, never used | §8: balance sheet | — | Owner capital in/out posted to equity | M2 |
| Fixed assets | ⚠️ screens exist, never used | §8: balance sheet | Needed in v1? | Simple asset register posted to the GL (no depreciation in v1) | M9 (optional) |
| Parties: customers | ✅ 1,028 customers, opening balance, location | §7 | — | Customer master + statement | M3 |
| Parties: kilns/"Marka" and drivers | ✅ 136 markas, 435 drivers | Not mentioned by owner | Needed only if trading is in scope (Q-01) | Party roles are configurable; Marka/Driver available as roles | M3 |
| "Supplier" grouping | ✅ 12 suppliers; parties and orders linked to one | — | Meaning unclear: partner/agent/branch? (Q-02) | Decide after your answer | M3 |
| Material suppliers | ❌ | §3.1, §6: soil, Kassu, coal… suppliers | — | Supplier party role + purchase documents | M3, M5 |
| Party ledger / statements | ✅ party statements, date-wise | §7, §8 | Sign convention confusing in reference | Statement = journal lines on AR/AP for that party, shown as Debit/Credit/Balance with clear Dr/Cr labels | M3 |
| Payment voucher (multi-line) | ✅ heavily used | §5, §8 | — | Keep the UX; post one balanced journal entry per voucher | M4 |
| Receipt voucher (multi-line) | ✅ heavily used | §7 | — | Same as above; receipts can be allocated to invoices | M4, M8 |
| Expenses with categories | ✅ 4,182 expenses; category / sub-category | §6 | — | Keep; categories map to GL expense accounts | M4 |
| Material purchases | ❌ | §3.1, §6: soil per foot, Kassu, trolley, excavator | Units / pricing (Q-10, Q-11) | Purchase document with lines + extra charges; stock in | M5 |
| Material / fuel stock and consumption | ❌ | §6: Kola, Bora, Tuka, waste | Consumption basis (per day / per firing?) (Q-12) | Material stock movements; consumption entries; weighted average cost | M5 |
| Raw-brick production (Pathai) | ❌ | §3.2: piece-rate, rejects unpaid | How rejects are recorded and approved (Q-14) | Daily work entry per worker/team: made, rejected, accepted | M6 |
| Movement to next stage (Kamyaar) | ❌ | §3.3 | Rate basis, commission, helpers (Q-21, Q-22) | Stage-movement entry linked to labour | M6, M7 |
| Firing (Julai) | ❌ | §3.5: fuel, mistri team | Batch vs continuous; how quantities in fire are counted (Q-15) | Firing entries + fuel consumption | M6 |
| Fired, awaiting Nakaasi | ❌ | §4: must be its own state | — | System stage; cannot be sold | M6 |
| Nakaasi / unloading + grading | ❌ | §3.6: PKR 325 / 1,000 example; grade split | Grade definitions (Q-16) | Unloading entry splits qty by grade into saleable stock; labour earned | M6, M7 |
| Brick stock by stage and grade | ❌ (no stock at all) | §4: daily view, reconciliation | — | Stock-movement ledger; never negative; discrepancy flags | M6 |
| Piece-rate labour | ❌ | §5 | Rates per category (Q-20…Q-23) | Rate cards per labour category with effective dates | M7 |
| Salary staff + attendance | ⚠️ built, unused | §5 (Barai/Burai salaried) | Salary period (Q-23) | Reuse the reference payroll design, fixed (integer money, one source of truth) | M7 |
| Advances and deductions | ✅ (7 advances) | §5 | — | Worker advances (asset) settled in weekly/monthly settlement | M7 |
| Weekly settlement | ❌ | §3.2, §5 | Settlement day (Q-24) | Settlement run per cycle: earned − advances − deductions = payable | M7 |
| Mistri / team contract | ❌ | §3.5: example PKR 250,000 lump sum | Basis / period (Q-22) | Contractor role; contract payments; never also counted as individual payroll | M7 |
| Sales / orders from own stock | ❌ (only buy-and-sell trading orders) | §7 | Reserve at order or deduct at dispatch (Q-30) | Order → dispatch → invoice flow; stock checks | M8 |
| Trading orders (buy from another kiln & resell) | ✅ core of reference (14k orders) | Not mentioned | **In or out of scope? (Q-01)** | If in: keep the reference order form, posted correctly | M8 |
| Delivery / transport on sales | ✅ business pays driver; carriage built into sale rate | §7: **customer** arranges and pays transport | **Contradiction** (Q-04) | Default: customer arranges (no carriage). Optional per order: "delivered by us" with driver + carriage | M8 |
| Invoice + PDF | ❌ | §7 | Invoice fields / numbering (Q-33) | Immutable numbered invoice; PDF via dompdf; kiln branding | M8 |
| New-order email with PDF | ❌ | §7 | Recipients / trigger (Q-34) | Queued email + log + retry + resend | M8 |
| Credit sales / partial payments | ✅ (customer balances) | §7 | Returns, cancellations, bad debts (Q-31) | Receivables per invoice + on-account; void/credit note | M8 |
| Dashboard | ✅ today's figures + charts | §2, §4 | — | Operational dashboard: today's production by stage, stock by grade, cash position, receivables/payables | M9 |
| Daily balance reconciliation | ❌ (balances only) | §8: opening + in − out = closing | Process (Q-41) | Daily cash book per account + day-close check | M9 |
| Ledger reports | ✅ many | §8 | "Sack ledger", "Coil cost" meaning (Q-40) | All reference reports, rebuilt on the journal | M9 |
| P&L and balance sheet | ⚠️ simplified, cannot balance | §8 | Stock valuation (Q-42) | Real trial balance → P&L → balance sheet from the journal | M9 |
| Backup | ✅ download | §11 | Retention / offsite (Q-07) | spatie/laravel-backup: scheduled + download | M11 |
| Import old data | — | — | Wanted? (Q-03) | Optional importer with reconciliation report | M10 |
| Languages | English only | §10: consider Urdu later | (Q-05) | English first; every label in translation files so Urdu can be added | M0 |
