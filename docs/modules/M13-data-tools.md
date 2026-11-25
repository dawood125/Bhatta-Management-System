# M13 — Data tools: reset, import, export, restore (2026-11-25)

The client asked for the data features of the developer's other projects (Moosa Burger, the inventory system): reset, import and export. They are on a new page, **Administration → Data** (permission `data.manage`, the owner by default), plus a Restore button on **Backups**.

## Reset (`App\Data\DataReset`)
| Mode | Deletes | Keeps |
|---|---|---|
| Clear all entries | Sales, deliveries, production, purchases, usage, adjustments, wages, settlements, attendance, trolley costs, expenses, day closes, the journal, document numbers; opening balances set to 0 | Settings, products, materials, rates, users, parties, workers, trolleys, cash/bank accounts |
| Factory reset | Everything of the kiln | The kiln profile and the owner; defaults are created again; other users lose access (a login with no kiln left is switched off) |

- Owner only, password, type `RESET ALL DATA`, then a backup; nothing is deleted if the backup fails.
- Only the current kiln's rows (`kiln_id`). Deletes are plain queries in foreign-key order, because the models refuse to delete posted records on purpose.
- Other users of the kiln are logged out. The activity log keeps its history and records the reset.
- No per-module reset, on purpose (see D-111).

## Import from Excel (`App\Data\Import\*`)
1. **Parties**: Name, Name (Urdu), Type, Phone, Phone 2, Address, Area (created if new), Opening balance, Who owes, As of date.
2. **Workers**: Name, Name (Urdu), Category, Phone, Joined on, Opening balance, Who owes, As of date.
3. **Material opening stock**: Material, Quantity, Value, As of date.
4. **Brick opening stock**: Stage, Grade (ready for sale only), Quantity, As of date.

- Each has a **Template** (example rows and a Help sheet listing the allowed categories, materials, stages and grades).
- Upload (.xlsx or .csv, up to 2,000 rows): every row is checked by really importing it inside a transaction that is rolled back; the preview lists each row as OK or with its problem. **Import** runs again and keeps the result only when every row works.
- Headers match whatever their case or spacing; dates as 25-11-2026, 25/11/2026, 2026-11-25 or an Excel date; blank date = today; Excel's lost leading 0 on phone numbers is put back.
- A party or worker (same category) whose name already exists is refused, so the same file cannot be imported twice.

## Export to Excel (`App\Data\Export\DataExporter`)
- Lists: parties (with balances), workers (with balances), sales, material purchases, transactions (every journal line), material stock (with value and average cost), brick stock.
- An **Excel** button on each list page downloads the whole list, by whoever can see it. **Download all data** on the Data page gives one workbook with a sheet per list (data permission).
- Sales, purchases and transactions accept `?from=YYYY-MM-DD&to=YYYY-MM-DD`.
- Amounts are plain numbers in rupees, so Excel can add them up.

## Restore from the website (`App\Backups\WebRestore`)
- Backups page → **Restore** on any backup (owner only). Password, type `RESTORE`.
- A safety backup first, maintenance mode, restore with files, migrate, ledger check, site open again. If the restore fails the site stays closed and the message names the safety backup to restore.
- Only backups the system made (server or Google Drive), never an uploaded file.
- It replaces the whole database of the installation (one customer per server).

## Testing
- `tests/Feature/Data/DataResetTest.php` (12), `ImportExportTest.php` (13), `WebRestoreTest.php` (6); access map updated. Full suite 851 tests, Larastan and Pint pass. Browser check on demo data: the Data page and every download.
