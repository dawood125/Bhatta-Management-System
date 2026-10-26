# 08 — User Acceptance Testing (UAT)

How the system is checked by people before a kiln uses it for real.

| Stage | Who | Where | Goal |
|---|---|---|---|
| 1. Partner test | Your partner (and you) | The test server, with made-up data | Find mistakes and anything confusing, before the owner sees it |
| 2. Owner review | The kiln owner, with you | The test server, after stage 1's fixes | Show the system, collect the owner's feedback and decisions |
| 3. Trial run (later) | The owner, munshi and data entry | The live server, with the kiln's real data | About a week alongside the paper register; the totals must match |

Each stage ends with a short list of problems (see "Reporting a problem"). I fix them, the tests are run again, and the next stage starts.

---

## Before stage 1: prepare the test server

1. Deploy with [07-deployment.md](07-deployment.md). `php artisan kiln:check` must show no **fix** lines.
2. Run `php artisan kiln:install` with a made-up kiln name and an owner login.
3. Log in as the owner and create one user for each role, so every screen can be seen as each role: Manager, Munshi, Data entry, Viewer (Users page). Note the logins in a password manager, not in chat.
4. Use made-up names and amounts only. Never type real customers' names or phone numbers into the test server.

## Stage 1: the partner's test script

Work through the scenarios in order, on a laptop **and** on a phone, once in English and once in Urdu. For each one write **OK**, or describe the problem.

### A. First day: setting up the kiln (as the owner)

| # | Do this | Expect |
|---|---|---|
| A1 | Settings → Kiln profile: name, address, phone, logo | The logo shows on invoices and statements |
| A2 | Settings → Products and grades, Production stages, Units | The default grades and stages are there; add and rename one |
| A3 | Accounts: add "Cash" with an opening balance, and a bank account | Both show on the dashboard (Cash and bank) |
| A4 | Parties: add 3 customers (one with an opening balance they owe), 1 coal supplier, 2 drivers, 1 sales agent | Each shows its balance; the customer's opening balance is under "Others owe us" |
| A5 | Labour → Workers: a Pathai team, a Kamyaar, a Nakaasi team, 2 daily workers. Labour → Rates: a rate for each | Workers listed with their category and rate |
| A6 | Materials: buy coal on credit from the supplier, with a trolley charge | The supplier is owed the total; coal stock and its average cost show |

### B. A normal working day (as the munshi and data entry)

| # | Do this | Expect |
|---|---|---|
| B1 | Production: Pathai (made and rejected) for the Pathai team | Raw stock goes up by the accepted bricks; the team earns wages |
| B2 | Loading, then firing with coal used, then Nakaasi by grade | Bricks move stage by stage; coal stock goes down; stock by grade is ready to sell |
| B3 | New sale for a customer, delivered now, with a driver and carriage, part paid in cash | Invoice number given; stock goes down; the customer owes the rest plus carriage; the driver is owed the carriage |
| B4 | A second sale, not delivered yet; deliver it in two trips | "Waiting for delivery", then "Partly delivered", then "Delivered" |
| B5 | Invoice PDF, in English and in Urdu; email the invoice (if email is set up) | Clear PDF; the email arrives with the PDF attached |
| B6 | Receive a payment from a customer (Payments and receipts) | Their balance goes down; the oldest unpaid sale shows as paid first |
| B7 | Pay the drivers from "Drivers due" | Drivers' balances go to zero |
| B8 | Record two expenses (e.g. diesel, food) | Cash goes down; they appear under Expenses |
| B9 | Daily workers: attendance or work entries | They earn their daily wages |
| B10 | End of day: Reports → Day close: count the cash and close the day | Expected = counted, or the difference is shown; after closing, entries on that day are refused for the munshi |

### C. Corrections

| # | Do this | Expect |
|---|---|---|
| C1 | Reverse a wrong expense (as the manager) | The expense is crossed out with its reversal; cash is back |
| C2 | Cancel an undelivered sale; reverse a delivery | Stock comes back; the customer's balance is corrected |
| C3 | As the owner, reopen the closed day, correct something, close it again | Only the owner can reopen; the reason is recorded |
| C4 | Try to give a wrong amount, an empty form, a date in the future, the same form twice (double click) | A clear message under the field; nothing is saved twice |

### D. End of the week and of the month

| # | Do this | Expect |
|---|---|---|
| D1 | Labour → Settle wages for the week; Print payslips | Each worker's payable is right; one printed payslip per worker |
| D2 | Party statement of a customer and of the supplier; their PDFs | Every sale, payment and charge, with a running balance that ends at their balance |
| D3 | Reports: Profit and loss, Balance sheet, Sales summary, Production summary, Cost per 1,000 bricks, Who owes us | The balance sheet says "Balanced"; the figures agree with what was entered |
| D4 | Dashboard | Today's figures and the charts match the reports |

### E. Who may see what

Log in as each role and check that the menus and buttons match the role:

| Role | Should be able to | Should **not** see |
|---|---|---|
| Owner | Everything, including Backups and reopening days | — |
| Manager | Money, reports, corrections | Backups, reopening a closed day |
| Munshi | Daily production, sales, payments, day close | Profit and loss, balance sheet, settings |
| Data entry | Production entries | Money, sales, reports other than production |
| Viewer | Look at sales, production and parties | Any "New…" or correction button |

A page a role may not open must say "You do not have access to this page", never show the data.

### F. Safety

| # | Do this | Expect |
|---|---|---|
| F1 | As the owner: Backups → Back up now; download it | A new backup in the list; a .zip file downloads |
| F2 | Backups → Test a restore | "Restore test passed" |
| F3 | Leave a page open for a few hours, then save | A clear "page has expired" message, nothing half-saved |
| F4 | Type a wrong password 6 times in a minute | Login is paused for a minute |

## Stage 2: the owner review

A guided tour of about an hour, with the partner's fixes done:

1. A normal day from B1 to B10, entered by you while the owner watches, on a phone.
2. The owner opens the dashboard, a customer's statement and the profit and loss.
3. Questions to ask and write down:
   - Are the grades, stages, units and labour categories the ones the kiln uses?
   - Is anything entered on paper today that the system does not cover?
   - Which reports does the owner look at most? Is anything missing?
   - Who will use the system (munshi, data entry), and on what (phone, laptop)?
   - Open decisions in [05-open-questions.md](05-open-questions.md) that are still on proposed defaults (e.g. Q-40 to Q-42).

## Reporting a problem

One line per problem, in a shared sheet or a message:

| When | Who (role) | Page | What I did | What happened | What I expected | Severity |
|---|---|---|---|---|---|---|

Severity: **critical** (wrong money or stock, data lost, cannot work), **high** (a task cannot be finished), **medium** (works but confusing or slow), **low** (wording, looks).

**Stage 1 is done** when every scenario is OK and there is no open critical or high problem. **Stage 3 is done** when the system's cash, stock and customer balances match the paper register and the cash counted for five days in a row, and the owner signs off.
