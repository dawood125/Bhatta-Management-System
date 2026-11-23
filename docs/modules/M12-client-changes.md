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
| A machine runs 8 days a month; only its operator is paid, Rs 1,500 a day | New daily-wage category **Machine operator**. Set the rate once and record the days in Labour → Work and wages. Machine-made Pathai is entered with no worker, so no piece wage is paid |
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
