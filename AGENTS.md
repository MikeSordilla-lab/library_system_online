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

## Module Architecture

### Circulation Module (`src/utils/circulation.php`)
Canonical source for circulation business logic. Page controllers delegate to orchestration functions.

**Low-level helpers:**
- `get_loan_period(PDO $pdo): int`
- `get_reservation_expiry(PDO $pdo): int`
- `log_circulation(PDO $pdo, array $params): void`
- `get_unpaid_fines_total(PDO $pdo, int $user_id): float`
- `get_overdue_loan_count(PDO $pdo, int $user_id): int`
- `expire_stale_reservations(PDO $pdo): int`
- `transition_reservation_status(PDO $pdo, int $id, string $from, string $to, array $meta): bool`
- `get_pending_reservation_queue(PDO $pdo): array`

**High-level orchestration (transactional, returns `{success, message, ...}`):**
- `perform_checkout(PDO $pdo, int $user_id, int $book_id, int $actor_id, string $actor_role): array`
- `perform_checkin(PDO $pdo, int $loan_id, int $actor_id, string $actor_role): array`
- `pay_user_fines(PDO $pdo, int $user_id, int $actor_id, string $actor_role): array`

Page controllers using these: `librarian/checkout.php`, `librarian/checkin.php`, `librarian/pay-fine.php`.

### Catalog Module (`src/utils/catalog.php`)
Canonical source for book catalog CRUD logic.

**Validation helpers:**
- `validate_book_fields(array $d): array` — returns error list (empty = valid)
- `validate_cover_upload(array $file): array` — validates MIME, size, image integrity
- `book_title_author_exists(PDO $pdo, string $title, string $author, ?int $exclude_id): bool`

**Cover persistence:**
- `handle_book_cover(PDO $pdo, int $book_id, ?string $data, ?string $mime, bool $remove, int $actor, string $role): ?string`

**High-level CRUD (transactional, returns `{success, errors, ...}`):**
- `add_book(PDO $pdo, array $data, int $actor_id, string $actor_role): array`
- `update_book(PDO $pdo, int $book_id, array $data, int $actor_id, string $actor_role): array`
- `delete_book(PDO $pdo, int $book_id, int $actor_id, string $actor_role): array`

Page controllers using these: `librarian/catalog-add.php`, `librarian/catalog-edit.php`, `librarian/catalog-delete.php`.

### Shim Layer (`includes/`)
The `includes/` directory contains thin shims that `require_once` the canonical `src/utils/` modules for backward compatibility. These shims are safe to use and will be removed once all page controllers have migrated.

| Shim | Delegates to |
|------|-------------|
| `includes/circulation.php` | `src/utils/circulation.php` |
| `includes/constants.php` | `src/config/constants.php` |
| `includes/settings.php` | `src/utils/settings.php` |
| `includes/avatar.php` | `src/utils/avatar.php` |
| `includes/csrf.php` | `src/middleware/csrf.php` |

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
├── utils/            — Helpers, avatar, circulation, settings, catalog, PHPMailer
└── views/            — Layouts & components
    ├── components/   — Sidebar partials
    └── layouts/

includes/             — Legacy shims (delegate to src/); for backward compat
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
Tables: `Users`, `Books`, `Loans`, `Circulation`, `Reservations`, `System_Logs`, `Receipt_Tickets`, `Receipt_Ticket_Logs`

PHPMailer is bundled (no composer). Do not add it via composer.

## Seed Scripts
- `seed-books.php` — original seed script
- `seed-books-50.php` — seeds 50+ diverse books with cover images
