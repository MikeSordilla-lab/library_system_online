# Library System Online — Agent Guide

## Stack
- PHP 8.x, MySQL (PDO, utf8mb4), PHPMailer (bundled in `src/utils/phpmailer/`)

## Entry Points & Roles
| Role | Index | Base Auth |
|------|-------|-----------|
| Public | `index.php`, `catalog.php`, `login.php`, `register.php` | None |
| Borrower | `borrower/index.php` | `['borrower']` |
| Librarian | `librarian/index.php` | `['librarian']` |
| Admin | `admin/index.php` | `['admin']` |

## Config Loading (critical)
The system auto-detects environment via `.env.mode`:
- `local` → `.env.local`
- `production` → `.env.production`
- fallback → `.env`

On unknown hosts (not localhost/127.0.0.1), defaults to `production`.

Config values also live in `$GLOBALS['_APP_ENV']` for shared-host compatibility where `getenv()` may be restricted.

## Bootstrap
For application pages: `require_once __DIR__ . '/bootstrap.php'`
- Loads `config.php` (→ env), `constants.php`, `db.php`, helpers, middleware, starts session, sets CSRF token

For scripts/CLI: `require_once __DIR__ . '/includes/db.php'`
- Loads `config.php` if not yet loaded, returns PDO via `get_db()`

## Auth Guard Usage
```php
$allowed_roles = ['admin']; // or ['admin', 'librarian'], or [] for any authenticated
require_once __DIR__ . '/includes/auth_guard.php';
```
- Sessions re-validated against DB on every request (suspended/deleted accounts are logged out)
- Role is synced from DB on each request

## DB Migrations
```bash
php admin/migrations/runner.php list   # show available
php admin/migrations/runner.php all    # run all
php admin/migrations/runner.php receipts-phase1  # run specific
```
Aliases work: `receipts`, `ticket`, `tickets` → `receipts-phase1`

## Default Credentials
- Admin: `admin@library.local` / `admin123` (from `database/infinityfree-import.sql`)
- Change immediately after first login

## Key Paths
```
ROOT_PATH = project root
SRC_PATH  = ROOT_PATH/src
PUBLIC_PATH = ROOT_PATH/public
DATABASE_PATH = ROOT_PATH/database
BASE_URL  = auto-detected, must end in /
```

## Deployment
- Build deploy zip: `powershell -NoProfile -ExecutionPolicy Bypass -File .\build-deploy-zip.ps1`
- Deploy zip excludes `.git`, `.github`, `.kilo`, `.agents`, local test folders
- `.htaccess` blocks direct access to `config.php` and all `.env*` files
- `.user.ini` hides PHP errors from visitors
- Config auto-detects HTTPS; set `DEBUG_MODE=false` in production

## Database Schema
Tables: `Users`, `Books`, `Loans`, `Reservations`, `System_Logs`, `Receipt_Tickets`, `Receipt_Ticket_Logs`

PHPMailer is bundled (no composer). Do not add it via composer.