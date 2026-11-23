# Bhatta Manager — Complete Testing Guide

This guide walks you through **every part of the system**, in the order a kiln really works: setting up the kiln, a full working day, checking the results, correcting mistakes, and the safety features. For each step it tells you **what to click, what to type, and exactly what you should see**.

Every number in the worked example has been checked against the real system by an automated test, so if you see a different number, it is a real problem: please report it.

**Where:** https://43-205-63-227.sslip.io  
**Your login:** the owner creates it for you (see A9). Use made-up names and amounts only; this is a test server.

---

## Contents

0. [Before you start: how the system thinks](#0-before-you-start-how-the-system-thinks)
1. [Part A — Setting up the kiln](#part-a--setting-up-the-kiln-as-the-owner)
2. [Part B — A working day at the test kiln](#part-b--a-working-day-at-the-test-kiln)
3. [Part C — Checking the results](#part-c--checking-the-results)
4. [Part D — Correcting mistakes](#part-d--correcting-mistakes)
5. [Part E — More labour features](#part-e--more-labour-features)
6. [Part F — Closing the day](#part-f--closing-the-day)
7. [Part G — Who may see what (roles)](#part-g--who-may-see-what-roles)
8. [Part H — Urdu, phone and printing](#part-h--urdu-phone-and-printing)
9. [Part I — Safety and administration](#part-i--safety-and-administration)
10. [Part J — Try to break it](#part-j--try-to-break-it)
11. [Part K — The client's changes](#part-k--the-clients-changes-november-2026)
12. [Reporting problems](#reporting-problems)
12. [Summary of expected results](#summary-of-expected-results)

---

## 0. Before you start: how the system thinks

Reading this first makes everything else easier.

**1. Nothing is ever deleted or edited.** A wrong entry is **reversed**: the system adds an opposite entry, and the original stays visible, crossed out as "Reversed". This is how a proper ledger (khata) works and keeps the books honest.

**2. Every rupee is recorded twice (double entry).** When a customer pays Rs 30,000 in cash, the cash goes **up** by 30,000 and what the customer owes goes **down** by 30,000. You never enter both; the system does it. That is why the reports always agree with each other, and why the balance sheet always says "Balanced".

**3. Who owes whom.**
- **They owe us** (receivable, *banam* / لینا): customers who still have to pay.
- **We owe them** (payable, *jama* / دینا): suppliers we have not paid yet, drivers' carriage, workers' wages.

**4. Bricks move through stages** — each step moves them to the next stage:

```
Pathai (made) ──► Raw bricks ──► Loaded ──► In the kiln (Julai) ──► Firing complete ──► Fired, awaiting Nakaasi ──► Nakaasi ──► Ready for sale (by grade) ──► Sold / delivered
```

Only bricks **Ready for sale** can be sold, and the system never lets any stage go below zero.

**5. Wages are earned automatically from production.** Pathai pays the team per 1,000 **accepted** bricks; loading pays the Kamyaar per 1,000 bricks loaded; Nakaasi pays per 1,000 bricks unloaded. Daily workers earn per day, salaried workers per month.

**6. Dates matter.** Entries cannot be dated in the future. After a day is **closed** (Part F), nobody except the owner can add anything dated on or before that day.

**7. Two languages.** Every screen works in English and Urdu (right to left). Each person's choice is remembered.

**Glossary**

| Word | Meaning |
|---|---|
| Pathai | Making raw bricks by hand (by a team or family) |
| Kamyaar | Worker who loads bricks into the kiln |
| Julai | Firing in the kiln |
| Nakaasi | Unloading fired bricks and sorting them by grade |
| Awal / Doom | First / second grade bricks |
| Khingar | Over-burnt bricks; Tooty: broken bricks; Rora: rubble (by the trolley) |
| Kachi | Raw (unfired) brick |
| Munshi | The kiln's clerk / accountant |
| Banam / Jama | Debit (owes us) / credit (we owe) on a party's statement |
| Kola / Bora | Coal / sawdust-type fuel by the bag |

---

## Part A — Setting up the kiln (as the owner)

Log in as the **owner**. Do these in order; Part B uses exactly these names and numbers.

### A1. Kiln profile and logo
**Kiln settings → Kiln profile.**
1. Change the phone and address, press **Save profile**.
2. Choose a logo (a PNG or JPG under 1 MB), press **Save profile**.

✅ The new logo appears in the side menu. Try a PDF file instead of an image: it must be refused with a message.

### A2. The lists the kiln uses
**Kiln settings** has tabs: *Products and grades, Production stages, Units, Areas, Expense categories, Materials*. They are already filled with the usual ones (Awal, Doom, Khingar, Tile, Tooty, Rora, Kachi; Soil, Kassu, Coal, Bora, Tuka, Waste; Diesel, repairs, bills, …).
1. Open each tab and look through it.
2. In **Areas**, add one area, e.g. "Test Town".
3. In **Expense categories**, open "Fuel and vehicles" and check it has "Diesel".

✅ Every list shows English and Urdu names. Try adding a product with a name that already exists: it must be refused.

### A3. The cash account
**Cash and bank → Add account.**
- Type: **Cash in hand**, Name: **Cash**, Balance on opening date: **500,000**, Opening date: **yesterday**. Save.

✅ The list shows *Cash — Rs 500,000*. (Other types exist: bank account, mobile wallet, cash with a person.)

### A4. Parties (customers, supplier, driver)
**Parties → Add party**, four times. For each one set the **opening balance date to yesterday**:

| This party is a | Name | Opening balance |
|---|---|---|
| Customer | **Ali Builders** | **20,000 — They owe us** |
| Customer | **Bilal Traders** | 0 |
| Supplier | **Coal Supplier** | 0 |
| Driver | **Driver Karim** | 0 |

✅ The Parties page shows *Total receivable Rs 20,000*. Ali Builders shows *20,000 owes us*.

### A5. Labour categories and rates
**Labour → Categories and rates.** The categories already exist (Pathai, Kamyaar, Nakaasi, Barai, Mistri, Daily labour). Give four of them a **rate** with **Starts on = yesterday**:

| Category | Rate |
|---|---|
| Pathai | **1,500** per 1,000 bricks |
| Kamyaar | **300** per 1,000 bricks |
| Nakaasi | **250** per 1,000 bricks |
| Daily labour | **1,200** per day |

✅ Each category shows its rate and the date it starts. A rate can also be set for one worker only ("Own rate for a worker").

### A6. Workers
**Labour → Workers → Add worker**, four times:

| Name | Category |
|---|---|
| **Pathai team Rashid** | Pathai |
| **Kamyaar Aslam** | Kamyaar |
| **Nakaasi team Javed** | Nakaasi |
| **Daily worker Naveed** | Daily labour |

✅ All four are listed with balance 0.

### A7. Materials stock
**Materials → Stock** lists the materials with 0 in stock. (Opening stock already at the kiln is entered with **Opening stock / shortage**; we test that in D8.)

### A8. Email (optional, if an email account is ready)
See I4. Without it, invoice emails are not sent; everything else works.

### A9. Users for testing
**Users → Add user**, one per role, so you can test what each person sees (Part G):

| Full name | Username | Role |
|---|---|---|
| Test Manager | manager1 | Manager |
| Test Munshi | munshi1 | Munshi |
| Test Data entry | entry1 | Data entry |
| Test Viewer | viewer1 | Viewer |

Use a different password for each (at least 8 characters, letters and numbers). Keep them in your password manager.

✅ Each user appears with their role. Try a username with capital letters or spaces: it must be refused.

---

## Part B — A working day at the test kiln

Do all of this **today**, in this order, as the **owner** (or the munshi, except where noted). After each step, check the ✅ result before moving on.

### B1. Buy coal on credit, pay the trolley in cash
**Materials → Purchases → New purchase.**
- Supplier: **Coal Supplier**. Paid how: **On credit**.
- Materials: **Coal (Kola)**, Quantity **10** (ton), Rate **30,000**.
- **Add charge**: *Trolley / transport*, Amount **2,000**, paid **from an account → Cash**.
- **Save purchase**.

✅ Expected:
- Total cost **Rs 302,000**. The trolley is part of the coal's cost (landed cost).
- **Materials → Stock**: Coal **10 ton**, value **302,000**, average cost **30,200 a ton**.
- **Parties**: Coal Supplier — **we owe them 300,000**.
- **Cash and bank**: Cash **498,000**.

### B2. Pathai: bricks made
**Production → Pathai (bricks made).**
- Worker paid: **Pathai team Rashid**. Bricks made **10,000**, Rejected **200**. **Save**.

✅ Expected:
- **Production → Brick stock**: Raw bricks (Pathai) **9,800** (10,000 − 200 rejected).
- **Labour → Workers**: Rashid has earned **14,700** (9,800 ÷ 1,000 × 1,500). Open his account: one wages line.

### B3. Load the kiln
**Production → Loaded into the kiln.** Bricks **9,800**, Worker paid: **Kamyaar Aslam**. Save.

✅ Raw bricks **0**, In the kiln (Julai) **9,800**. Aslam earned **2,940** (9,800 ÷ 1,000 × 300).

### B4. Firing complete, with the coal burned
**Production → Firing complete.** Bricks fired **9,800**. **Add fuel**: Coal (Kola) **2** ton. Save.

✅ In the kiln **0**, Fired, awaiting Nakaasi **9,800**. **Materials → Stock**: Coal **8 ton**, value **241,600** (still 30,200 a ton). The 2 tons used (60,400) are now a cost of the kiln.

### B5. Nakaasi: unload and grade
**Production → Nakaasi (unloaded).** Worker paid: **Nakaasi team Javed**.
- **Awal 8,000**, **Doom 1,500**, **Tooty (broken) 300** (total 9,800). Save.

✅ Ready for sale: **Awal 8,000, Doom 1,500, Tooty 300**. Fired, awaiting Nakaasi **0**. Javed earned **2,450** (9,800 ÷ 1,000 × 250).

### B6. A sale taken and partly paid at once
**Sales → New sale.**
- Customer: **Bilal Traders**.
- Grade **Awal**, Quantity **5,000**, Rate **15,000** (per 1,000 bricks). **Add grade**: **Doom**, **1,000**, Rate **12,000**.
- Tick **Bricks taken now**. Driver: **Driver Karim**. Carriage **3,000**. Amount received **50,000**, Received into **Cash**.
- **Save sale**.

✅ Expected:
- An invoice number like **INV-2026-0001**. Total **Rs 87,000** (75,000 + 12,000). Status **Delivered**, payment **Partly paid**.
- **Bilal Traders owes 40,000** (87,000 + 3,000 carriage − 50,000 paid). The carriage is charged to the customer and **owed to the driver**: it is not the kiln's income.
- Ready for sale: Awal **3,000**, Doom **500**.
- Press **Invoice PDF**: a clear invoice with your logo. Switch to Urdu and open it again.

### B7. A sale delivered later, in two trips
**Sales → New sale.** Customer **Ali Builders**, **Awal 2,000** at **15,000**. Do **not** tick "Bricks taken now". Save.

✅ Status **To deliver**. On **Production → Brick stock**, "Free to sell" Awal is 2,000 less: these bricks are reserved for Ali.

On the sale's page press **Record delivery**: Awal **1,200**, Driver **Driver Karim**, Carriage **2,000**. **Save delivery**.

✅ Still **To deliver** (1,200 of 2,000 delivered).

**Record delivery** again: Awal **800**, Driver Karim, Carriage **2,000**.

✅ Status **Delivered**. **Ali Builders owes 54,000** (20,000 opening + 30,000 sale + 4,000 carriage). Ready for sale: **Awal 1,000, Doom 500, Tooty 300**. **Sales → Deliveries** lists all three trips (numbers like DS-2026-0001).

Try to deliver more than was ordered: it must be refused.

### B8. A customer pays (oldest first)
**Payments and receipts → New receipt.** Received into **Cash**. Party **Ali Builders**, Amount **30,000**. **Save receipt**.

✅ Expected:
- **Ali Builders owes 24,000**.
- Open Ali's sale: **Partly paid**, paid **10,000**, still to pay **24,000**. Why only 10,000? A general receipt pays the **oldest debt first**: the 20,000 opening balance, then 10,000 of the sale. (Money taken with **Receive payment** on a sale's own page pays that sale first.)

### B9. Pay the driver
**Sales → Drivers due** shows **Driver Karim 7,000** (3,000 + 2,000 + 2,000). Press **Pay all drivers owed**. The payment form opens filled in; Paid from **Cash**. **Save payment**.

✅ Driver Karim **0**.

### B10. A running expense
**Expenses → Add expense.** Paid from **Cash**, Category **Fuel and vehicles**, Sub-category **Diesel**, Amount **5,000**. Save.

✅ The expense is listed; **By category** shows Fuel and vehicles 5,000.

### B11. A day of daily labour
**Labour → Work and wages → Record work.** Worker **Daily worker Naveed**, **1** day. Save.

✅ Naveed earned **1,200**.

### B12. An advance to a worker
**Labour → Workers → Pathai team Rashid → Give advance**: **5,000** from **Cash**. Save.

✅ Rashid's balance: **9,700** still owed to him (14,700 earned − 5,000 advance).

### B13. Pay everyone's wages (settlement)
**Labour → Settlements → New settlement.** Period **Last 7 days**, Payment date **today**, Paid from **Cash**. Include all four workers; Pay now:

| Worker | Earned | Advances | Pay now |
|---|---|---|---|
| Pathai team Rashid | 14,700 | 5,000 | **9,700** |
| Kamyaar Aslam | 2,940 | 0 | **2,940** |
| Nakaasi team Javed | 2,450 | 0 | **2,450** |
| Daily worker Naveed | 1,200 | 0 | **1,200** |

**Save settlement**.

✅ Total to pay **16,290**. All four balances **0**. **Print payslips** prints one payslip per worker. Try paying a worker more than he is owed: it must be refused ("Give an advance instead").

### B14. Pay part of the coal bill
**Payments and receipts → New payment.** Paid from **Cash**, Party **Coal Supplier**, **100,000**. Save payment.

✅ **Coal Supplier: we owe them 200,000**. **Cash and bank → Cash: Rs 444,710.**

Check the cash yourself: 500,000 − 2,000 + 50,000 + 30,000 − 7,000 − 5,000 − 5,000 − 16,290 − 100,000 = **444,710**.

---

## Part C — Checking the results

Everything below should match **exactly**.

### C1. Dashboard
| Tile | Expected |
|---|---|
| Cash and bank | **Rs 444,710** |
| Others owe us | **Rs 64,000** (Bilal 40,000 + Ali 24,000) |
| We owe others | **Rs 200,000** (Coal Supplier) |
| Wages due | **Rs 0** |
| Sold today | **Rs 117,000** — 2 sales |
| Pathai accepted | **9,800** (10,000 made) |
| Nakaasi (unloaded) | **9,800** |
| Bricks delivered | **8,000** |
| Ready for sale / Free to sell | **1,800** |

Hover over (or tab to) this month in the **Income and costs** chart: it shows the amounts. "Show as a table" shows them as a table.

### C2. Statements
- **Parties → Ali Builders → Statement**: the opening balance 20,000, the sale 30,000, two carriage lines of 2,000, the receipt −30,000, and a running balance that ends at **24,000**. Press **PDF**.
- **Cash and bank → Cash → Cash book**, press **Today**: opening 500,000, every movement of the day with a running balance, closing **444,710**.
- **Labour → Workers → Pathai team Rashid**: earned 14,700, advance 5,000, payment 9,700, balance **0**.
- **Materials → Coal → Stock card**: in 10 (302,000), out 2 (60,400), balance 8 (241,600).

### C3. Reports (Reports page, period = today)
| Report | Expected |
|---|---|
| **Profit and loss** | Income **117,000**; Materials and fuel used **60,400**; Labour **21,290**; Running expenses **5,000**; **Profit 30,310** |
| **Balance sheet** | Shows **"Balanced"**; Cash 444,710; Material stock 241,600; customers who owe us 64,000; suppliers we owe 200,000 |
| **Trial balance** | Total debits = total credits |
| **Cost per 1,000 bricks** | Cost **8,845.92**; sold for **14,625.00**; margin **5,779.08** per 1,000 |
| **Sales summary** | 2 sales; Awal 7,000 for 105,000; Doom 1,000 for 12,000; by customer Bilal and Ali |
| **Production summary** | Made 10,000, accepted 9,800; loaded, fired and unloaded 9,800; Awal 8,000, Doom 1,500, Tooty 300 |
| **Who owes us** | Bilal Traders 40,000; Ali Builders 24,000 (both "up to 30 days") |
| **Material cost report** | Coal used 2 ton, 60,400 |

Note that the opening balance (20,000) is **not** income, and carriage is **not** income: both are correct.

Press **Print** on a report and **PDF** on the profit and loss and balance sheet.

---

## Part D — Correcting mistakes

Manager or owner only, except where noted. After each, check that the effect is exactly undone and the original stays visible, marked **Reversed**.

| # | Do this | Expect |
|---|---|---|
| D1 | **Expenses**: on the diesel expense press **Reverse**, reason "Entered twice" | Cash +5,000 (449,710); the expense is crossed out; profit +5,000 |
| D2 | **Payments and receipts**: reverse the 100,000 supplier payment | Coal Supplier back to 300,000; cash −100,000 |
| D3 | Make a new sale to Bilal: Awal **500** (not delivered), then **Cancel sale** | Status **Cancelled**; the 500 Awal are free to sell again; Bilal's balance unchanged |
| D4 | New sale to Bilal: Awal **600**. Deliver **400**, then **Close the rest** | A credit note (CN-…) for the 200 not delivered; Bilal owes only for 400; status Delivered |
| D5 | On Ali's sale, **reverse** the second delivery (undo button on the delivery row) | 800 Awal back in stock; the 2,000 carriage is cancelled for both Ali and the driver; status back to To deliver |
| D6 | **Parties → Bilal Traders → Write off** 1,000 (**owner only**) | Bilal owes 1,000 less; "Bad debts written off" appears as an expense. The manager does not see the button |
| D7 | **Production → Entries**: reverse a production entry | Its bricks and wages are taken back. Refused if the bricks have already moved on (e.g. reversing the Pathai after loading): "Reverse the later entries first" |
| D8 | **Materials → Stock → Opening stock / shortage**: shortage of Coal **0.5** ton, reason "Counted less" | Coal 7.5 ton; the shortage is a cost at the average price |
| D9 | **Production → Adjust stock**: Tooty, take out **50**, reason "Broken" | Tooty 250 |
| D10 | **Labour → Settlements**: open the settlement and **Reverse** it | The four workers are owed their wages again; cash back |
| D11 | **Cash and bank → Owner capital**: Money out (withdrawal) **10,000** from Cash (**owner only**). Then **Transactions**: find it and **Reverse** it | Cash −10,000, then back; the withdrawal is not a cost in the profit and loss. The reversal shows in the cash book too |

Also try (all must be refused): reversing the same thing twice; a munshi trying to reverse anything (no Reverse buttons); deleting anything (there is no delete).

---

## Part E — More labour features

| # | Do this | Expect |
|---|---|---|
| E1 | Give **Barai** (salaried) a rate of **30,000** per month and add a Barai worker. **Attendance and salaries**: mark him **absent 2 days**, then **Post salaries for (this month)** | Salary **28,000**: the monthly rate less 1/30 for each absent day (1/60 for a half day). It can be posted once per month; reversing it allows posting again |
| E2 | Add a worker in **Mistri (firing team)** (lump sum); on his account **Record contract payment due**, e.g. 250,000 | He is owed 250,000; pay it in a settlement |
| E3 | On a worker's account: **Deduction** 500 ("Broken tools") | His balance goes down 500 |
| E4 | On a worker's account: **Advance paid back** 1,000 into Cash | Cash +1,000; his advance goes down |
| E5 | Give a worker his **own rate** (Categories and rates → Own rate for a worker) | His next wages use his rate, others keep the category rate |
| E6 | Change a rate **starting tomorrow** | Today's entries still use the old rate |

---

## Part F — Closing the day

At the end of the day the munshi counts the cash and closes the day.

1. As the **munshi**: **Day close**. The table shows Cash: opening, money in, money out, **in the books**.
2. Type the cash you counted. Type **10 less** than the book figure first: the difference shows **−10** in red. Then type the exact figure: difference 0.
3. **Close the day** → the dialog lists every account and its difference → confirm.

✅ The dashboard says **"Today is closed."**

4. Still as the munshi, try to add an expense dated today: **refused**, "The books are closed up to (date). Only the owner can add entries on or before that day." The same for production, sales deliveries and payments.
5. As the **owner**: adding an entry today still works. **Reopen** the day with a reason (at least 5 characters). Only the last closed day can be reopened, and only by the owner. The manager does not see Reopen.

---

## Part G — Who may see what (roles)

Log in with each test user from A9 (use a private/incognito window so you stay logged in as the owner in the other). For each role, look at the side menu and try the pages.

| Can… | Owner | Manager | Munshi | Data entry | Viewer |
|---|:-:|:-:|:-:|:-:|:-:|
| Dashboard | ✔ everything | ✔ everything | ✔ all but the charts | ✔ production and stock only | ✔ who owes whom, wages, sales, production — no cash |
| Production: record | ✔ | ✔ | ✔ | ✔ | — (view only) |
| Production: adjust / reverse | ✔ | ✔ | — | — | — |
| Sales: record, deliver, receive | ✔ | ✔ | ✔ | — | — (view only) |
| Sales: cancel, close the rest, reverse delivery | ✔ | ✔ | — | — | — |
| Write off a bad debt | ✔ | — | — | — | — |
| Payments, receipts, expenses | ✔ | ✔ | ✔ | — | — |
| Reverse any transaction | ✔ | ✔ | — | — | — |
| Owner capital in / out | ✔ | — | — | — | — |
| Profit and loss, balance sheet, cost per 1,000 | ✔ | ✔ | — | — | — |
| Day close | ✔ | ✔ | ✔ | — | — |
| Reopen a closed day | ✔ | — | — | — | — |
| Labour rates | ✔ | ✔ | — | — | — |
| Materials: opening stock / shortage | ✔ | ✔ | — | — | — |
| Kiln settings (lists) | ✔ | ✔ | — | — | — |
| Kiln profile, Email settings | ✔ | — | — | — | — |
| Users, Activity log | ✔ | ✔ | — | — | — |
| Backups | ✔ | — | — | — | — |

✅ For each role: the menu shows only what it may use, and typing the address of a forbidden page (e.g. `/reports/profit-and-loss` as munshi) shows **"You do not have access to this page"** in English and Urdu, never the data. No button should appear that leads to a refused page.

---

## Part H — Urdu, phone and printing

| # | Do this | Expect |
|---|---|---|
| H1 | Switch to **اردو** (top of the page or on the login page) | The whole screen turns right-to-left in Urdu; numbers, dates and amounts stay readable left-to-right |
| H2 | Log out and in again, on another device | Your language is remembered |
| H3 | Party names: give a party an Urdu name (Name (Urdu)) | Shown in Urdu screens and Urdu PDFs |
| H4 | Invoice PDF and statement PDF in Urdu | Urdu letters joined correctly, amounts readable |
| H5 | Use the system **on a phone** for a whole day of Part B | Every page fits the screen (no sideways scrolling); menus open with the ☰ button; forms are usable |
| H6 | **Print** any list or report | A clean printout without menus and buttons |

---

## Part I — Safety and administration

### I1. Users
**Users**: edit a user's role; **Deactivate** a user → he cannot log in any more ("This account has been deactivated"); **Activate** again; **Set new password** for a user. The last owner cannot be deactivated.

### I2. Activity log
**Activity log** shows who changed what and when (users, settings, parties, rates, logins, backups downloaded). Filter by person and dates. Entries cannot be edited or deleted.

### I3. My profile
Top right menu → **My profile**: change your name/phone; **Change password** (needs the current one).

### I4. Email settings (owner)
**Kiln settings → Email.** For Gmail: press **Use Gmail**, username and from address = the Gmail address, password = a Gmail **app password** (see the note on the page). **Save**, then **Send test email**.

✅ The test email arrives (check spam too). With a wrong password, the page shows the email server's own error.

Then in **Kiln profile → Sales emails and invoices**: tick "Email each new sale", enter an address, Save. Make a sale: the invoice arrives by email with the PDF. **Sales → Invoice emails** shows each email's status; a failed one can be sent again.

### I5. Backups (owner)
**Backups**:
1. **Back up now** → "Backup made." A new line in the list and in the history.
2. **Download** a backup → a .zip file (it is password-protected; that is correct).
3. **Test a restore** → after a few seconds: "Restore test passed: … tables and … rows came back the same; the books are consistent."
4. The page says when the newest backup was made; the nightly backup runs at 2:00 by itself. Check the next morning that a "Nightly backup" line appeared.

### I6. Google Drive copies (owner, once the developer has set up the Google sign-in)
**Backups → Connect Google Drive** → a code appears → open google.com/device on a phone, enter the code, choose the Google account, **Allow**. The page says "Google Drive is connected". **Copy the newest now** → a "Copy to Google Drive — Done" line, and the file appears in a folder "Bhatta Manager backups" in that Google Drive.

### I7. Logging in safely
- Wrong password **6 times in a minute** → login is paused for a minute.
- Leave a page open for a long time (over 8 hours) and then save → "This page has expired"; nothing is saved halfway.

---

## Part J — Try to break it

Good testers try to make mistakes on purpose. All of these must be **refused with a clear message**, and nothing saved:

- Empty forms; letters in amount fields; negative or zero amounts; amounts with 3 decimals.
- A date in the future; a date before an account's opening date.
- Selling more bricks than are ready for sale; Nakaasi of more bricks than are awaiting it; loading more than the raw stock.
- Paying more than an account has (cash would go below zero).
- Paying a worker more than he is owed in a settlement.
- **Double-click** "Save" quickly on any form: the entry must be saved **once only**.
- Open the same sale in two tabs and record deliveries in both: never more than ordered.
- Change the address bar to a number that does not exist (e.g. `/sales/99999`): "Page not found" in English and Urdu, nothing else.

---

## Part K — The client's changes (November 2026)

Do this part **after** Parts A–J, because it changes how Pathai works from then on.

**K1. Soil and Kassu settings.** Kiln settings → Materials → edit **Soil**: unit *Trolley* (the unit can only change while soil has no stock entries), *Bricks from one unit* **2000**, *Bricks from one big trolley* **2600**. Edit **Kassu**: *Bricks from one unit* **17778**.

**K2. Buy them.** Materials → New purchase: Soil, size **Big trolley**, quantity **10**, rate **3000**; Kassu, quantity **5**, rate **4000**. Expect: total **Rs 50,000**, soil **13.00 trolleys** ("Bought as 10.00 big trolleys"), Kassu 5.00.

**K3. Pathai takes them out.** Pathai: made **4,000**, rejected **200**. The form shows *Uses Soil 1.900 trolley* and *Uses Kassu 0.214 trolley*. After saving, Materials shows soil **11.10** and Kassu **4.79** (rounded on screen).

**K4. Not enough soil.** Try a Pathai of **100,000** bricks. It must be refused, naming the soil, with nothing saved.

**K5. Reverse.** Reverse the K3 Pathai entry. Soil returns to 13.00 and Kassu to 5.00. Enter it again for K6.

**K6. Re-firing.** Load and fire the 3,800 bricks, naming a Kamyaar. At Nakaasi enter Awal **3,500** and *Badly fired, send back to Julai* **300**, naming a Nakaasi worker. Expect: *Waiting to re-fire* **300**, and the Nakaasi worker paid for **3,500**. Load with *Bricks going back in* **300** and raw bricks empty: the Kamyaar earns nothing more. Fire 300, then Nakaasi Awal 300: the worker is paid for **300**.

**K7. Machine operator.** Labour → Categories and rates: set **Machine operator** to **1,500** per day. Add a worker in that category and record **8** days. Expect wages of **Rs 12,000**.

**K8. Driver settlement.** Sales → Driver settlement: add a trolley with its driver. Record two trips with carriage **3,000** each, then *Add diesel or maintenance*: diesel **1,500**, maintenance **500**. Expect that driver's month to show carriage **6,000**, diesel **1,500**, maintenance **500**, and **4,000** more owed than before. **Pay** opens a voucher for the balance; after saving, the balance is **0**. Then add a maintenance cost larger than what the driver is owed: the balance shows *Driver owes the kiln* and carries to the next month.

---

## Reporting problems

For each problem, one line in a shared sheet:

| When | Your role | Page | What you did | What happened | What you expected | Severity | Screenshot |
|---|---|---|---|---|---|---|---|

**Severity:**
- **Critical** — wrong money or stock, data lost, cannot work at all.
- **High** — a task cannot be finished.
- **Medium** — works, but confusing or slow.
- **Low** — wording, spelling, looks.

Also write down anything that is **not wrong but would be easier** another way — those ideas are valuable before we show the owner.

---

## Summary of expected results

After Part B (before Part D):

| What | Expected |
|---|---|
| Cash | **444,710** |
| Ali Builders owes | **24,000** |
| Bilal Traders owes | **40,000** |
| We owe Coal Supplier | **200,000** |
| Driver Karim | **0** |
| All four workers | **0** |
| Coal in stock | **8 ton, value 241,600** (30,200 a ton) |
| Ready for sale | **Awal 1,000, Doom 500, Tooty 300** |
| Sales today | **117,000** (2 sales) |
| Profit today | **30,310** (income 117,000 − materials 60,400 − labour 21,290 − expenses 5,000) |
| Cost per 1,000 bricks | **8,845.92** (sold for 14,625.00, margin 5,779.08) |
| Balance sheet | **Balanced** |

If any of these differ, please report it with a screenshot: these numbers are checked automatically against the system, so a difference means something on the server or in the steps went differently.
