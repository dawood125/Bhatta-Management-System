# First day setup: entering the opening data

Do this once, on the day the kiln starts using Bhatta Manager. Pick **one start date**, for example the 1st of the month, and use it as the "as of" date everywhere below. Enter the figures **as they stood at the end of the day before** that date.

Do the steps in this order. Each later step needs the earlier ones.

**Faster with Excel:** steps 3, 4, 6 and 7 can be done in one go on **Administration → Data → Import from Excel**. Download the template, fill it in, upload it, check the preview, then press Import. Nothing is saved unless every row is correct.

**After testing or training:** Administration → Data → **Clear all entries** removes the test entries and sets every balance to 0, keeping your lists and settings. Then enter the real opening data.

## 1. Kiln settings
- **Kiln settings → Kiln profile**: kiln name, address, phone and logo.
- **Kiln settings → Materials**: for soil, set *bricks from one normal trolley* (for example 2,000) and *from one big trolley* (for example 2,600). For Kassu, set *bricks from one trolley* (for example 17,778).
- **Kiln settings → Products**: check the grades and prices.

## 2. Cash and bank
- **Cash and bank → Add account** for each cash box and bank account.
- **Opening balance** = the money in it on the start date.

## 3. Parties (customers, suppliers, drivers, sales agents)
- **Parties → Add party** for each one.
- **Opening balance**:
  - customer who still owes money → *They owe us*
  - customer who paid in advance → *We owe them*
  - supplier the kiln still has to pay → *We owe them*
  - driver: what the kiln owes him (*We owe them*) or what he owes the kiln (*They owe us*)

## 4. Workers
- **Labour → Categories and rates**: set the rates (Pathai per 1,000, Kamyaar per 1,000, Nakaasi per 1,000, Machine operator per day, and so on).
- **Labour → Add worker** for each worker, family or team.
- **Opening balance**:
  - wages earned but not yet paid → *Kiln owes the worker (wages due)*
  - advance taken and not yet paid back → *Worker owes the kiln (advance)*
  - nothing either way → 0

## 5. Trolleys
- **Sales → Driver settlement → Add trolley**: add each kiln trolley with its driver.

## 6. Material stock
- **Materials → Opening stock / shortage**, choose *Opening stock*, for soil, Kassu, coal and every other material at the kiln: quantity and what it cost.
- Soil and Kassu are counted in **normal trolleys**. One big trolley counts as 1.3 normal ones.

## 7. Brick stock
- **Production → Adjust stock**, choose *Opening stock*, for the bricks at each stage:
  - **Raw**: bricks made and drying, not yet in the kiln
  - **In firing**: bricks in the kiln now
  - **Fired, waiting to unload**
  - **Waiting to re-fire**
  - **Ready to sell**: one entry per grade (Awal, Doom, Khingar…)

## 8. Owner's capital (optional)
- **Cash and bank → Owner capital**, if the owner wants to record money put in or taken out.

## 9. Check
- **Reports → Balance sheet** on the start date: cash, receivables, payables, wages due and stock should match the kiln's own records.
- **Labour → Workers**: each worker's balance matches the register.
- **Parties**: each party's balance matches the register.

A mistake in an opening balance can be fixed later by editing the account, party or worker. The system reverses the old figure and records the new one, and both stay in the history.
