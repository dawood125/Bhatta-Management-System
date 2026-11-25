# M12 — Client changes (2026-11-24)

What the client explained after the first test, and what changed in the system.

## What was built

| Client said | What the system does now |
|---|---|
| One big trolley of soil makes about 2,600 bricks, a normal one about 2,000; soil costs about Rs 3,000 a trolley | **Kiln settings → Materials** holds "Bricks from one unit" and "Bricks from one big trolley". A purchase can choose the trolley size. A big trolley is priced per big trolley and counts as 1.3 normal trolleys in stock |
| Kassu is mixed with the soil; Rs 4,000 a trolley; 20–25 trolleys per 4 lakh bricks | Kassu is set to 17,778 bricks per trolley (the middle: 22.5 trolleys per 4 lakh) and leaves stock at Pathai |
| Rejected raw bricks are reused | Soil and Kassu are taken for **accepted** bricks only |
| Badly fired bricks go back with the next Julai | Nakaasi has **"Badly fired, send back to Julai"**. They wait in the new stage **"Waiting to re-fire"**, and the loading form takes them back in |
| Kamyaar and Nakaasi workers are not paid again | The Kamyaar is paid only for raw bricks loaded. Nakaasi is paid when a brick comes out in a grade, so each brick earns each wage once |
| A machine runs 8 days a month; only its operator is paid, Rs 1,500 a day | New daily-wage category **Machine operator**. Pathai has **Made by: Machine**: choose the operator and he earns one day's wage, once per day (see part 2) |
| Trolleys belong to the bhatta; the driver gets the carriage less diesel and maintenance, monthly | New page **Sales → Driver settlement**: trolleys, diesel and maintenance, and per driver: brought forward + carriage − costs − paid = balance. Pay opens a payment voucher; slips can be printed |

## How the money works
- **Soil and Kassu** move from Material stock to their "… used" expense accounts at average cost, so they now appear in profit and cost per 1,000 bricks.
- **Carriage stays pass-through**, because the driver keeps everything that remains. A trolley cost (TC-…) debits the driver and credits the cash account or the supplier (for a pump on credit). It is not a kiln expense.
- **Re-fired bricks** are not counted as unloaded until they come out in a grade, so production and cost per 1,000 are not counted twice.

## Rules to know
- Pathai is **refused when there is not enough soil or Kassu** in stock. Enter the opening stock or purchase first.
- A material with no "bricks from one unit" figure is not taken out at Pathai.
- Reversing a Pathai entry returns its soil and Kassu.
- A cost is corrected by reversing its TC entry from Transactions.

## Still to confirm with the client
1. If diesel and maintenance are more than the carriage, the driver owes the difference and it carries to the next month (assumed).
2. Big repairs: record them as a **trolley cost** to charge the driver, or as a normal **expense** if the kiln bears them.
3. Does "around 3,000" apply to both trolley sizes? The rate is typed on each purchase, so either works.
4. The Nakaasi wage on a re-fired brick is paid when it finally comes out in a grade (assumed).

## Limits
- The trip form still takes a driver and a typed vehicle number; it does not pick from the trolley list. Settlement is per driver, so the money is not affected.

## Testing
- Full automated suite: 783 tests pass, including 27 new ones in `tests/Feature/Client/ClientRequirementsTest.php`.
- Larastan 0 errors, Pint clean, `ledger:verify` consistent after the browser walkthrough.
- Browser walkthrough on demo data: settings, big-trolley purchase, Pathai, loading, firing, Nakaasi with re-fire, loading the re-fire, trolley, diesel, maintenance and settlement. The new pages were checked in English and Urdu and at phone width.

## Part 2 (2026-11-25, from partner testing)

| Partner's point | What the system does now |
|---|---|
| Workers need an opening balance for the first day | Add/Edit worker has **Opening balance**: amount, "Kiln owes the worker (wages due)" or "Worker owes the kiln (advance)", and an "as of" date |
| Bricks already at the kiln on the first day | Production → Stock adjustment → **Opening stock**, at any stage and grade. Shown with an "Opening stock" badge |
| The machine operator is missing from the Pathai worker list | Pathai has **Made by: Hand / Machine**. Machine lists the daily-wage workers (machine operators first). He earns **one day's wage once per day**; a second machine entry that day, or a day already entered in Work and wages, adds nothing |
| What is "Rs 3,076.92 per trolley"? | Stock is kept in **normal trolleys**, so 2 big trolleys (2 × 2,600 ÷ 2,000) are 2.6 normal ones and Rs 8,000 ÷ 2.6 = Rs 3,076.92. The page now says "per normal trolley (2,000 bricks)", and the purchase form says "per big trolley" or "per normal trolley" under the rate |

### How the money works
- Worker opening balance: credit (wages due) or debit (advance) Wages payable for the worker, against Opening balances. It is not a wage expense, so it does not change this season's labour cost.
- Machine day: debit the machine operator's labour account, credit the operator, linked to the Pathai entry like piece wages. Reversing the entry reverses the wage.

### Rules to know
- The worker's opening date cannot be after his first transaction.
- Machine Pathai needs an operator paid by the day; a piece-rate worker is refused.
- The day check looks at any wages of the operator on that date, so enter his days in **either** Work and wages **or** machine Pathai, not both.

### Testing
- 24 new tests in `tests/Feature/Client/OpeningDataTest.php`; the full suite (808 tests), Larastan and Pint pass.
- Browser check on demo data: worker opening balance (add and edit), machine Pathai (operator list, day wage, "already paid" on a second entry), brick opening stock, and the purchase label.
- The first-day order of entry is in `docs/10-first-day-setup.md`.

## Part 3 (2026-11-25): advance taken back at settlement

**Before:** a settlement always took the whole advance out of the wages. A worker with a Rs 5,000 advance who earned Rs 3,000 got nothing.

**Now:** each worker with an advance has **"Take back from advance"** on the settlement screen, with **All / Half / None** buttons or any amount.
- *All* (the default) works as before.
- *Half* takes half the wages for the advance and pays the other half.
- *None* pays the full wages; the advance stays.
- "Pay now" follows the choice, and can still be lowered. It can never be more than the wages less what is taken back (paying more is a new advance).
- The payslip and the settlement page show **Advance taken back** and **Advance left**.

How the split is worked out (`WorkerLedger::advanceSplit()`): wages due = wages still due from before + earned − deductions − wages already paid in the period + anything earned after it; advance = wages due − the net balance. Old settlements have no split and show as before.

Also: **Driver settlement** is now in the sidebar.

Tests: 12 in `tests/Feature/Client/AdvanceTakeBackTest.php`; the full suite (820 tests), Larastan and Pint pass. Checked in the browser on demo data (All / Half / None and the sidebar link).
