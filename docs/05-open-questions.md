# 05 — Open Questions

## Answers received (2026-10-18)

| ID | Answer | Resulting decision |
|---|---|---|
| Q-01 | Add trading only if the local market needs it; otherwise later, on client request | **Not in v1.** The owner never mentioned trading, and it is the core of a *dealer's* business, not a kiln's. The design keeps it addable later: party roles Marka/Driver exist, and products can be "traded items". See D-011 |
| Q-02 | Unknown; research it | **Answered from the reference data:** every customer (and only customers) belongs to one "Supplier"; every order's customer belongs to that order's supplier; each supplier has a cash account named after them ("cash held by that person") and an expense head named after them. So a reference "Supplier" is a **sales agent / dealer who brings and manages a group of customers and collects cash from them**. v1: optional **Sales Agent** party role on customers and sales (for reports), plus "cash held by person" treasury accounts. Agent commission is not in v1 unless the owner asks. See D-012 |
| Q-03 | No import; the old data was only for reference | M10 removed from the plan |
| Q-04 | The customer gives the transport fare to the bhatta; at the end of the day the bhatta pays each driver | **Carriage is pass-through money:** charged to the customer on the sale and owed to the driver; it is not kiln income or expense. The driver is paid with a payment voucher (daily "drivers due" list). See D-013 |
| Q-05 | English **and Urdu** | Full English/Urdu UI with right-to-left layout for Urdu; per-user language choice; Urdu-capable PDFs. See D-014 |
| Q-06 | Charge for one kiln for now; what do you recommend? | **One kiln per client installation** (own database). The kiln is still recorded on every row, so one owner with 2+ kilns can be supported later without a rewrite. No kiln switcher in the UI until it is needed. See D-015 |
| Q-07 | Contabo server; available for two weeks of testing | Ubuntu/Debian VPS with PHP 8.3 + MySQL/MariaDB + Nginx. **Deploy early** (after M0/M2) to validate the server, instead of waiting for the end. See D-016 |

Further open questions follow. These are the questions still open **after** inspecting the reference database and screens. Each one is tagged with its type and the module that needs the answer. Where it is safe to do so, a **proposed default** is given, so work can continue until you answer.

Tags:
- **[BIZ]** business-critical
- **[ACC]** accounting-critical
- **[OPS]** operational
- **[UI]** cosmetic

## A. Needed now (they affect the overall design)

| ID | Question | Why it matters | Proposed default |
|---|---|---|---|
| Q-01 [BIZ] | The reference is a **trading** system: buy a load from another kiln ("Marka"), a driver carries it, sell to a customer. The owner's notes describe **running our own kiln**. Should the new product do **both** (own production + trading orders), or only own production and sales? | Changes the sales/order data model and the parties needed | Both. Trading orders are kept as a separate order type in M8 |
| Q-02 [BIZ] | In the reference, who is a **"Supplier"**? There are 12; every party and every order is linked to one, and there are cash accounts of type `supplier_account` held by people. Partner, agent, branch, or munshi? | Decides whether it is a party, a branch/kiln, or a sales agent with commission | Unknown. Needed before M3 |
| Q-03 [OPS] | Do you want the **existing data imported** (parties, account balances, history) from the old system? Note: 13 accounts and 18 parties in the old data have balances that don't match their own ledgers | Adds module M10 and a reconciliation step | No import. Start fresh with opening balances |
| Q-04 [BIZ] | **Transport:** the owner said the customer arranges and pays for transport. The reference pays drivers and builds carriage into the sale rate. Which is the default, and should "delivered by us" still be possible per order? | Sales posting and pricing | Default: customer arranges. Optional per-order "delivered by us" with driver + carriage |
| Q-05 [UI] | Language: English only for v1, with all labels ready for an **Urdu** translation later? | Every label goes into translation files from day 1 | English v1, Urdu-ready |
| Q-06 [BIZ] | Can **one owner have several kilns under one login**, and can staff work at more than one kiln? | Tenancy and login design | Yes to both (kiln switcher), but each kiln's data is fully separate |
| Q-07 [OPS] | **Production server:** the same Debian 12 + MariaDB 10.11 server as the current site? Who will host it? | DB compatibility, queue worker for emails, backups | Same type of server; code kept MariaDB-compatible |

## B. Materials (before M5)

| ID | Question | Proposed default |
|---|---|---|
| Q-10 [OPS] | Soil is bought "per foot". Is that cubic feet, a trolley count, or something else? And what exactly is **Kassu/Kasoo**: unit, how it is measured and priced? | Configurable units; nothing assumed |
| Q-11 [ACC] | Trolley and excavator charges: are they part of the material's cost, or a separate expense? | Added to the material cost (landed cost) |
| Q-12 [OPS] | How are **Kola (coal), Bora, Tuka** and waste used and recorded: per day, per firing, or by stock count? Units (ton, bag, trolley)? | Daily consumption entry; weighted-average cost |

## C. Production (before M6)

| ID | Question | Proposed default |
|---|---|---|
| Q-13 [BIZ] | Confirm the stage list and names. Proposed: **Pathai (raw made)** → **Raw ready** → **In firing (Julai)** → **Fired, awaiting Nakaasi** → **Saleable by grade** → **Sold**. Missing or extra stages? | As listed |
| Q-14 [OPS] | How are rejected raw bricks counted, who approves the count, and are rejects reused (e.g. as soil)? | Munshi enters made/rejected; owner/manager role approves |
| Q-15 [OPS] | Firing: does the kiln burn in **batches** (a known number of bricks per firing) or **continuously** (bricks move through sections every day)? How do you count bricks "in fire"? | Daily entries: moved in / completed. No batch IDs unless needed |
| Q-16 [BIZ] | Grades: which of these come out of Nakaasi, and which are saleable: **Awal, Doom, Tile, Khingar, Rora, Tooty, Barsati, Awal Barshi, Doom Barshi**? Is **Kachi** (raw) ever sold? What is "Rora"? | All configurable; Rora added as a by-product until confirmed |

## D. Labour (before M7)

| ID | Question | Proposed default |
|---|---|---|
| Q-20 [ACC] | Pathai rate: per 1,000 accepted bricks (example 1,000–1,500)? Rate per worker, or per team / family? | Per 1,000 accepted, per worker or team |
| Q-21 [ACC] | **Kamyaar**: rate (example 400–420 / 1,000). What is the "commission", and how is it calculated? | No commission until explained |
| Q-22 [ACC] | The two helpers who arrange the bricks: who pays them (the kiln or the Kamyaar), and how much? Same question for the **mistri's 250,000**: per firing, per month, or per season? | Contract with a configurable period |
| Q-23 [ACC] | **Barai/Burai**: salaried, paid monthly or weekly? Salary deducted for absences? | Monthly salary, attendance-based |
| Q-24 [OPS] | Weekly settlement: which day? Paid in cash or from a specific account? | Configurable day; paid from a chosen account |
| Q-25 [ACC] | Advances: is there a maximum? Are advances recovered over several weeks or all at once? | Recovered at the next settlement unless split |
| Q-26 [ACC] | Public reports describe a **Jamadar** (team leader) who recruits brick-making families and gives them advances (*peshgi*). Does this kiln have a Jamadar? Does the kiln pay workers directly, or through the Jamadar? | Pay per worker or per team; a team can have a leader |
| Q-27 [OPS] | Carriage: is it charged per trip or per 1,000 bricks? Is the amount charged to the customer always exactly what the driver gets? | Per-trip or per-1,000, chosen on the sale; customer amount = driver amount |

## E. Sales (before M8)

M8 was built on the proposed defaults below (decisions D-078 to D-087). An answer can still change them.

| ID | Question | Proposed default |
|---|---|---|
| Q-30 [BIZ] | Is stock **reserved when the order is made**, or only **reduced when bricks are picked up**? Can one order be picked up over several trips? | Reserve on confirm; reduce on each dispatch |
| Q-31 [ACC] | Credit sales, advance payments by customers, returns, cancellations, bad debts: which of these happen? | All supported; bad-debt write-off by owner only |
| Q-32 [ACC] | Discounts or rate differences after the sale? | Line discount allowed before posting only |
| Q-33 [UI] | Invoice: what must it show (logo, NTN/STRN, terms, signature)? Numbering format (e.g. `INV-2026-0001`)? | Kiln logo, address, phone; `INV-YYYY-####` |
| Q-34 [OPS] | The invoice email goes to whom, and when: every new order, only confirmed sales, or configurable? | Owner email(s), on confirmed sale, configurable |
| Q-35 [OPS] | Which email provider / SMTP account will be used? | Any SMTP; credentials in `.env` |

## F. Finance and reports (before M9)

| ID | Question | Proposed default |
|---|---|---|
| Q-40 [ACC] | What are **"sack ledger"** and **"coil cost"**? (Sack may be **Bora**, and coil may be **Kola/coal**. To be confirmed.) | Treated as Bora and Kola cost reports until confirmed |
| Q-41 [OPS] | Daily balance: does someone count cash at day end, and should the system lock the day after closing? | Day-close check with a difference report; locked days need the owner role to edit |
| Q-42 [ACC] | Should the balance sheet include the **value of brick stock**? If so, at what cost? | v1: quantity only; valuation later |
