# Library System Online — Agent Guide

## Stack
- PHP 8.x, MySQL (PDO, utf8mb4), PHPMailer (bundled in `src/utils/phpmailer/`)

## Entry Points & Roles
| Role | Index | Auth |
|------|-------|------|
| Public | `index.php`, `catalog.php`, `login.php`, `register.php` | None |
| Borrower | `borrower/index.php` | `['borrower']` |
| Librarian | `librarian/index.php` | `['librarian']` |

> **Note:** Public entry points in `public/` (e.g., `public/index.php`, `public/catalog.php`) are thin wrappers that `require_once` the root file. The root files remain canonical entry points.

## Config Loading (critical)
The system auto-detects environment via `.env.mode`:
- `local` → `.env.local`
- `production` → `.env.production`
- fallback → `.env`

On unknown hosts (not localhost/127.0.0.1), defaults to `production`.

Config values also live in `$GLOBALS['_APP_ENV']` for shared-host compatibility where `getenv()` may be restricted.

Root `config.php` handles env detection. Main config logic is at `src/config/config.php`.

## Bootstrap
For application pages: `require_once __DIR__ . '/bootstrap.php'`
- Loads from `src/` paths: `src/config/config.php` (→ env), `src/config/constants.php`, `src/config/database.php`, `src/utils/helpers.php`, `src/utils/avatar.php`, `src/utils/circulation.php`, `src/utils/settings.php`, `src/middleware/auth_guard.php`, `src/middleware/csrf.php`
- Starts session, sets CSRF token, loads user context

For scripts/CLI: `require_once __DIR__ . '/includes/db.php'`
- Falls back to `config.php` if not yet loaded, returns PDO via `get_db()`

## Auth Guard Usage
```php
$allowed_roles = ['admin']; // or ['admin', 'librarian'], or [] for any authenticated
require_once __DIR__ . '/includes/auth_guard.php';
```
- Sessions re-validated against DB on every request (suspended/deleted accounts are logged out)
- Role is synced from DB on each request

## DB Migrations
SQL migration files live in `database/`:
```
database/infinityfree-import.sql
database/migration-epic1-borrowing-lifecycle.sql
database/migration-infinityfree-all-in-one.sql
database/migration-receipts-phase1-safe.sql
database/migration-receipts-phase1.sql
database/migration-reservations-approval-phase1.sql
database/migration-reservations-fk-safe.sql
database/migration-reservations-table-create.sql
```

Apply manually via phpMyAdmin or CLI. No migration runner currently — `admin/migrations/runner.php` was removed.

## Default Credentials
- Admin: `admin@library.local` / `admin123` (from `database/infinityfree-import.sql`)
- Change immediately after first login

## Key Paths
```
ROOT_PATH     = project root
SRC_PATH      = ROOT_PATH/src
PUBLIC_PATH   = ROOT_PATH/public
DATABASE_PATH = ROOT_PATH/database
BASE_URL      = auto-detected, must end in /
```

## Key Directories
```
src/
├── api/v1/            — API endpoints (e.g., search-suggestions.php)
├── config/            — Config, constants, database helper
├── middleware/        — Auth guard, CSRF
├── utils/            — Helpers, avatar, circulation, settings, PHPMailer
└── views/            — Layouts & components
    ├── components/   — Sidebar partials
    └── layouts/

includes/             — Legacy includes (kept for backward compat)
assets/
├── css/              — Stylesheets (incl. borrower-redesign.css)
├── js/               — JavaScript
├── images/           — Images
├── fonts/            — Font files
└── avatars/          — Uploaded user avatars
```

## Deployment
- No automated build script currently (previous `build-deploy-zip.ps1` was removed)
- `.htaccess` blocks direct access to `config.php` and all `.env*` files
- `.user.ini` hides PHP errors from visitors
- Config auto-detects HTTPS; set `DEBUG_MODE=false` in production

## Database Schema
Tables: `Users`, `Books`, `Loans`, `Reservations`, `System_Logs`, `Receipt_Tickets`, `Receipt_Ticket_Logs`

PHPMailer is bundled (no composer). Do not add it via composer.

## Seed Scripts
- `seed-books.php` — original seed script
- `seed-books-50.php` — seeds 50+ diverse books with cover images
