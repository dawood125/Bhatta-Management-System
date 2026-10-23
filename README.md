# Bhatta Manager — Brick Kiln Management System

A configurable management system for brick kilns (bhatta) in Pakistan: production, brick stock, labour and payroll, materials, sales and invoices, and double-entry accounts. It is available in **English and Urdu**.

Planning, decisions and module reports are in [`docs/`](docs/README.md).

## Stack
- Laravel 13 (PHP 8.3+), MySQL 8 / MariaDB 10.11
- Livewire 4 + Alpine.js, Tailwind CSS 4, Vite
- Pest 4 (unit and feature tests), Larastan, Pint

## Local setup
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed     # local demo data only ("Demo Kiln (demo data)")
npm run build
php artisan serve
```

## Setting up a real client (server)
```bash
php artisan migrate --force
php artisan kiln:install       # asks for the kiln name and the owner's login
```
Never run the demo seeder in production (it refuses to).

## Quality checks
```bash
./vendor/bin/pest              # all tests (uses the gbricks_test MySQL database)
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```

## Rules every module follows
- Money is stored as integer paisa (`App\Support\Money`), never as floats.
- Kiln-owned data uses the `BelongsToKiln` trait. Queries outside a kiln context fail loudly.
- All writes go through Action classes (transaction, authorisation, audit log).
- Every label goes through `__()` and has an Urdu translation in `lang/ur.json` (a test enforces this).
