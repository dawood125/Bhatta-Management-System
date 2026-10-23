# M8 — Sales, Invoices, PDF and Email: Module Report

**Status:** built and tested, waiting for your review and sign-off.
**Date:** 2026-10-23
**Branch:** `m8-sales` (based on `m7-labour`)

## What M8 delivers

**Sales** is a new item in the **Kiln** section of the sidebar, with four tabs.

| Screen | What you can do | Who |
|---|---|---|
| **Sales** | Every sale of a period with its customer, grades, total, what is **still to pay** and two badges: delivery (To deliver / Delivered / Cancelled) and payment (Unpaid / Partly paid / Paid). Filters by delivery and payment; totals: sold, still to be paid, sales waiting for delivery | Everyone with sales access; new sale: Owner, Manager, Munshi |
| **New sale** | Customer (searchable; their sales agent is suggested), date, and each grade with quantity, **rate per 1,000 bricks** (per trolley for Rora), optional discount and amount. Each grade shows **how many are free to sell**. Optionally in the same step: **bricks taken now** (driver, vehicle, carriage) and **money received now** (amount, account) | Owner, Manager, Munshi |
| **Sale page** | Ordered / delivered / still to deliver per grade; every delivery; what the customer owes for this sale and has paid; the customer's balance. Buttons: **Invoice PDF**, **Email invoice**, **Receive payment**, **Record delivery**; corrections: **Close the rest**, **Cancel sale**, **Reverse** a delivery | view: everyone; record: Owner, Manager, Munshi; corrections: Owner, Manager |
| **Deliveries** | Every trip of a period: customer, bricks, vehicle and driver, carriage; totals of trips, bricks and carriage | Everyone with sales access |
| **Drivers due** | The trips of a day per driver with their carriage, and what each driver is owed now. **Pay** opens a payment voucher with the driver and amount filled in; **Pay all drivers owed** does it for everyone | view: everyone; pay: Owner, Manager, Munshi |
| **Invoice emails** | Each invoice email: to whom, when, how many attempts, the error of a failed one, and **Send again** | Everyone with sales access |

Other screens changed:
- **Brick stock** shows, per grade, **Reserved for sales** and **Free to sell** next to the stock.
- **Daily production** lists the day's **deliveries to customers** (they also leave "Ready for sale").
- **Party statement**: a **PDF** button (the statement as an Urdu-capable PDF, D-044), and for the owner a **Write off** button on customers who owe money (bad debt).
- **Kiln profile**: a new card **Sales emails and invoices**: switch on "Email each new sale", the owner's addresses (up to 5), and a note printed on every invoice (terms, bank details).

New accounts (added to existing kilns by `php artisan kiln:defaults`): **4100 Brick sales** (income) and **5910 Bad debts written off** (expense).

## How a sale works
1. **The sale (INV-…).** When it is saved, the customer owes the total (a credit sale) and its bricks are **reserved**: they are still in stock but no longer free to sell. A sale for more than is free to sell is refused, with the numbers ("Only 3,000 free to sell (in stock 8,000, waiting for other sales 5,000)").
2. **Deliveries (DS-…).** Each trip takes bricks out of stock and they stop counting as reserved. A sale can be collected over many trips; more than is left to deliver is refused, and so is a back-dated trip that would leave a day below zero. When everything is delivered the sale shows "Delivered".
3. **Carriage (D-013).** Only when the kiln arranged the transport: the trip names the driver and the carriage. The customer owes it and the driver is owed it; it is **not income and not expense**. The carriage entry has the same number as the trip. Drivers are paid with a payment voucher from **Drivers due**.
4. **Payments.** Money received with the sale, or with **Receive payment** on its page, **pays that sale first**. Every other payment from the customer (a receipt voucher, an advance) pays their **oldest unpaid sales first**, as in a hand ledger. Nothing is "allocated" by hand and nothing is stored that could disagree with the customer's statement.
5. **Corrections.** Nothing is edited or deleted:
   - **Cancel sale**, while nothing was delivered: the invoice is reversed and its bricks are free again. Money received stays on the customer's account.
   - **Close the rest**, after part was delivered: a **credit note (CN-…)** takes the value of what was not delivered off what the customer owes. A line's discount is shared between delivered and undelivered bricks to the exact paisa.
   - **Reverse a delivery** (also how returned bricks are recorded): the bricks go back into stock and are to be delivered again; the carriage is cancelled. Refused once the sale was closed.
   - **Write off** (owner only): what a customer will never pay becomes the expense "Bad debts written off" (WO-…), at most what they owe. It can be reversed from Transactions if they pay after all.
6. **The invoice PDF**, in English or Urdu (the viewer's language): kiln logo, name, address and phone; the customer; each grade with quantity, rate, discount, amount and delivered; carriage; bricks not delivered; total, paid and still to pay; the customer's balance; the deliveries; the kiln's invoice note; signature lines. Urdu PDFs are right to left (font: D-079).
7. **Invoice emails.** With sales emails switched on, every new sale is emailed to the owner with the invoice PDF attached, in the language of the person who recorded the sale. The email goes through the queue: it is logged first, then sent. If the mail server fails, the error is logged and it is tried again (3 attempts: now, after 1 minute, after 5 minutes). **Send again** creates a new email.
8. `php artisan ledger:verify` now also checks sales: each sale matches its invoice entry and lines; line amounts; each delivery's stock movements, reversal and carriage; never more delivered than ordered; cancellations and credit notes; and money received for a sale is a receipt from its customer.

## Agreed acceptance examples — pass as automated tests
1. **An order of 5,000 Awal @ 15,000 / 1,000 = 75,000.00.** Delivery moves Awal stock from **8,000 to 3,000** ✔
2. **A receipt of 50,000 leaves 25,000 receivable** ✔
3. **An order for more than the saleable stock is refused** ✔
4. **The email is logged as sent with the PDF attached. A simulated mail server failure is logged and retried** (the second attempt sends it) ✔

The flow was also done by hand in the browser on the demo data:
- a sale refused for more than the free stock;
- sale INV-2026-0002 for 2,000 Awal;
- a trip of 1,500 with driver and carriage 2,000 (DS-2026-0002);
- Rs 20,000 received on the sale's page (now "Partly paid");
- the rest closed with credit note CN-2026-0001 for 7,500;
- the drivers-due list showing Rs 8,000 owed to the driver;
- the invoice PDF in English and Urdu;
- an invoice email sent through the real queue with its PDF attached.

## Test results (real runs, 2026-10-23, Ubuntu with MariaDB 10.11)
| Check | Result |
|---|---|
| `./vendor/bin/pest` (all modules) | **685 passed, 0 failed (3,531 assertions)**, of which 48 are new in M8 |
| `./vendor/bin/phpstan analyse` (Larastan level 6) | **0 errors** |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan ledger:verify` on the demo data | **consistent** |
| Migration | runs forward and back on MariaDB (it changes the M6 brick movements table, so this was checked first) |
| Manual check in the built-in browser | Desktop, tablet and phone, English and Urdu: sales list, new sale, sale page with its dialogs, deliveries, drivers due, invoice emails, kiln profile, party statement, brick stock, daily production, the payment voucher filled in from drivers due. No page is wider than a phone screen. Invoice and statement PDFs checked as images in both languages |

The M8 tests cover:
- the four acceptance examples;
- stock: reservation by open sales, several trips, never more than ordered, back-dated trips that would leave a day below zero, a trip dated before its sale, raw bricks from the raw stage, Rora by the trolley;
- money: carriage owed to the driver with shared DS numbers, carriage without a driver, a driver who is the customer, a sale delivered and paid at once, paid / unpaid oldest first with an opening balance and an advance, money received for a sale paying it first, a reversed receipt, money received for a cancelled sale;
- corrections: cancel (refused once delivered), close the rest with the discount shared, reverse a delivery (refused once closed), write-off (owner only, at most what is owed, reversible);
- rules: discounts, duplicate grades, zero totals, dates, customer and agent roles, the same form sent twice, permissions of every role, another kiln's customers and products, the new accounts, descriptions, sales that cannot be changed or deleted, `ledger:verify` on tampered data;
- emails: sent with the PDF attached, a mail server failure logged and retried, emails switched off, sending again, cancelled sales, the language, the settings and their checks;
- every sales screen, the PDFs (view, download, Urdu, access) and what a viewer and the munshi may not see.

## Problems found and fixed during M8
1. **A payment taken on a sale's page did not pay that sale.** With "oldest first" only, Rs 20,000 received on INV-0002 went to the older INV-0001, and INV-0002 still showed "Unpaid". Now money received for a sale pays that sale first (D-083). Found in the browser check.
2. **Urdu PDFs reversed dates and phone numbers** ("2026-10-23", "0000000-0300") and dropped the last letter of "روپے". Numbers are now always set left to right, and the currency word is set in the Urdu font. Urdu text in PDFs was also made larger, because Lateef runs small.
3. **New-sale form at laptop width**: the grade column was squeezed to nothing next to quantity, rate, discount and amount. The row is now two columns up to a wide screen. The sale page header and its two columns also wait for a wide screen now.
4. **"Closed by … on 23-10-2026"** showed the date reversed inside the Urdu sentence; the date is now set left to right.
5. **Quotes inside a dialog's description** broke the page template (party statement); typographic quotes are used now.

## Your decisions
These follow the proposed defaults in 05-open-questions; say if your kiln works differently.
- **Reserve on sale, reduce on delivery (Q-30):** a sale reserves its bricks; they leave stock with each trip; several trips per sale.
- **Credit sales, advances, returns, cancellations, bad debts (Q-31):** all supported as above. Returns are a reversed delivery, then "Close the rest". Bad-debt write-off is for the owner only.
- **Discounts (Q-32):** a discount per grade, only when the sale is entered. No change of rate afterwards: cancel and enter again, or close the rest.
- **Invoice (Q-33):** logo, address, phone; number `INV-YYYY-####`; your own note at the bottom. Tell me if you need NTN/STRN or other fields.
- **Who gets the email, and when (Q-34):** the addresses in the kiln profile, for every new sale when switched on, and on request. Switched off until you set it.
- **Email provider (Q-35):** any SMTP account. Its details go in `.env` on the server, never in the code. Until then emails are written to the log file. The server also needs a **queue worker** running (part of the deployment guide in M11).
- **Carriage (Q-27):** typed per trip; the customer is charged exactly what the driver gets.
- **Walk-in customers:** every sale needs a customer. For one-off cash buyers, add one party (e.g. "Cash customer") and use it.
- **Raw bricks (Kachi):** not for sale by default. If you mark Kachi saleable in Kiln settings, it is sold from the raw stage.

## Not in M8 (comes with later modules)
- **Sales reports over months, sales by agent and by grade, and charts:** M9 reports.
- **Agent commission:** not in v1 unless you ask (D-012).
- **Trading orders** (buy from another kiln and resell): not in v1 (D-011).
- **Production server setup** (queue worker, SMTP, backups): M11.
