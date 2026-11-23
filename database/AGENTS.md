# LibraLink database schema and data setup

## Purpose

LibraLink database schema and data setup.

## Ownership

SQL imports, migrations, diagnostic queries and optional seed data.

## Local Contracts

- For a fresh local XAMPP database, create `library_system` and import `infinityfree-import.sql` into it. The import drops matching tables, so use an empty database.
- After a fresh import, apply `migration-account-lockout.sql`, `migration-renew-count.sql`, and `migration-receipt-no-alias.sql` once to add columns omitted by the base import.
- `library_system.sql` is an ignored local snapshot of the schema and rows. Import it into an empty database when restoring the snapshot. Keep it out of deployment archives.
- Keep local connection settings in the ignored root `.env` and use `.env.mode` set to `local` for CLI scripts.

## Verification

- Run `php tests/borrower_workflow_regression.php` from the project root against the local database. Open the public catalog to verify the Apache connection.
