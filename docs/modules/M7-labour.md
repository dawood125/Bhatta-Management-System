# M7 — Labour and Payroll: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-20
**Branch:** `m7-labour` (based on `m6-production`)

## What M7 delivers

**Labour** is a new item in the **Kiln** section of the sidebar, with five tabs.

| Screen | What you can do | Who |
|---|---|---|
| **Workers** | Every worker, family or team with their category and live balance: "Wages due" (the kiln owes them) or "Advance" (they owe the kiln). Totals of both. Add, edit, archive (only at a zero balance). A warning appears when a similar name already exists | Everyone with labour access; changes: Owner, Manager, Munshi |
| **Worker account** | The worker's khata: what they earned (with bricks or days × rate), advances, deductions and payments, with a running balance; any period; print. Buttons: record work, give advance, advance paid back, deduction, reverse an entry | as above; reverse: Owner, Manager |
| **Work and wages** | All wages of a period (piece work, days, salaries, contracts) with the **labour cost by category**; record work for any worker ("Save and add another" for a day's list). Print as the labour cost report | Everyone with labour access |
| **Attendance and salaries** | Mark a day's attendance of salaried workers (only absences and half days need marking); see each worker's salary for a month and post the month's salaries | Owner, Manager, Munshi |
| **Settlements** | Weekly or monthly pay-out: choose the period, the account and the workers; each worker shows brought forward, earned, advances, deductions and payable, and the amount to pay starts at what is owed. Saved settlements list, one page per settlement with **payslips to print** (one per worker, with signature lines), and reversal | Owner, Manager, Munshi |
| **Categories and rates** | How each kind of labour is paid and its rate card with dates; a worker's own rate (e.g. each Barai's salary); add, rename, archive categories | Owner, Manager |

**Production dialogs** (Pathai, loading, Nakaasi) now have a **"Worker paid"** list of the workers paid for that work. Choosing one shows the rate and the wages before saving, e.g. "Rate Rs 1,200.00 per 1,000 bricks · Wages Rs 13,800.00". Production lists show the worker and the wages on each entry.

New kiln defaults (added to existing kilns by `php artisan kiln:defaults`): the categories **Pathai, Kamyaar, Nakaasi** (per 1,000 bricks, paid from production), **Barai** (monthly salary), **Mistri (firing team)** (contract) and **Daily labour**. **No rates are set:** each kiln enters its own, with the date they start.

## How the money is kept correct
- **One account per worker, inside the books.** "Wages payable" (2200) holds every worker's account; each line on it names the worker, and no other account may. Earnings are credited, advances, deductions and payments debited. Balances, statements and settlements all read the same journal, so they always agree with the trial balance.
- **Labour cost by category.** Each category has its own expense account (e.g. "Pathai wages", 6001…) created with its first wages; renaming the category renames the account.
- **The rate of the day.** A new rate starts on a date; earlier work keeps its old rate, which is copied onto each work entry. A worker's own rate overrides the category's. A rate that wages were worked out with cannot be removed.
- **Wages go with the bricks.** Naming a worker on Pathai pays the *accepted* bricks (rejected ones are never paid); loading pays the bricks loaded; Nakaasi pays the bricks unloaded (all grades and Tooty; Rora by the trolley is not bricks). The bricks and the wages are saved together: without a rate, neither is saved. Reversing the production entry reverses its wages; the wages cannot be reversed on their own.
- **Salaries from attendance.** Monthly salary less 1/30 for each absent day and 1/60 for each half day; days before the joining date are not paid. A salary is posted once a month. After that the month's attendance is locked, until the salary is reversed (and then it can be posted again).
- **Advances and deductions.** An advance comes out of a cash or bank account (never below zero, unless the account allows it) and can be paid back. A deduction needs a reason; it is income for the kiln ("Deductions from workers"), not cash.
- **Settlement maths.** brought forward + earned − advances − deductions = payable. You can pay less than that; you can never pay more than is owed on the payment date. The rest stays on the worker's account. A settlement that pays nothing is still kept (e.g. to show an advance not yet earned back).
- **Mistri contracts are counted once.** The agreed amount is earned by the mistri and paid to the mistri; their team is not on the kiln's payroll, so labour cost shows it once.
- **Nothing is edited or deleted.** Every mistake is a reversal with a reason; both stay visible. A double click never records twice (one-time key per form).
- `php artisan ledger:verify` now also checks labour: worker named on every wages line and on no other line, workers from the same kiln, every work entry matching its journal entry, and every settlement adding up to its payment entry.

## Agreed acceptance examples — pass as automated tests
1. **Pathai:** 11,500 accepted × 1,200 / 1,000 = **13,800.00**. Advance 5,000; the settlement pays **8,800**; remaining **0** ✔
2. **Kamyaar:** 11,000 × 410 / 1,000 = **4,510.00** ✔
3. **Nakaasi:** 11,000 × 325 / 1,000 = **3,575.00** ✔
4. **Mistri contract of 250,000** appears **once** in labour cost, never twice ✔

TEST_RESULTS_PLACEHOLDER

## Problems found and fixed during M7
1. **The ledger check crashed for a worker who owes the kiln.** "Payable − paid" mixed a signed and an unsigned number, which MySQL refuses when the result is negative (an advance not yet earned back). Found by a test; the check now converts the number first.
2. **The rate dialog could quietly set the wrong rate.** Clearing the worker in "Own rate for a worker" would have saved a rate for the whole category. That dialog now insists on a worker.
3. **"Paid" meant two things** (how a category is paid, and the amount paid). The first is now "How paid", in English and Urdu.
4. **Messages pointed to a page that does not exist** ("Labour rates"). They now say "Categories and rates", as the tab is called.

## Your decisions
These follow the proposed defaults; say if your kiln works differently.
- **Kamyaar (Q-21):** paid per 1,000 bricks loaded into the kiln. **No commission** until you explain how it works.
- **The two helpers and the mistri (Q-22):** helpers can be added as daily labour; the mistri's 250,000 is a contract amount recorded whenever it is due (per firing or per month, as you agree with them).
- **Barai (Q-23):** monthly salary, less absences (1/30 per day, 1/60 per half day). Tell me if salaries are weekly or if absences are not deducted.
- **Settlement day (Q-24):** any day; the form proposes the last 7 days, paid from the account you choose.
- **Advances (Q-25):** no maximum; they come off the next settlement unless you pay the worker less.
- **Families and the Jamadar (Q-26):** a worker can be a family or team under its head. If your kiln pays through a Jamadar, tell me and I'll add team leaders.
- **Names:** "Kamyaar", "Barai" and "Mistri" are kept as spoken (Urdu spellings provisional); rename them in Categories and rates.

## Not in M7 (comes with later modules)
- **A generated PDF payslip file:** payslips print from the browser (or "Save as PDF") now; a PDF file comes with the PDF engine of M8 (invoices).
- **Sales and customer invoices:** M8.
- **Labour reports over months with charts, and labour cost per 1,000 bricks:** M9 reports.
