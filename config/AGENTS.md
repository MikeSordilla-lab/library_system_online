# Database environment configuration

## Purpose

Database environment configuration.

## Ownership

`database.php` defines `DB_*` constants. Root `config.php` loads the environment and `includes/db.php` opens the PDO connection.

## Local Contracts

- Keep this workspace's local database connection settings in the ignored root `.env`. The config loader checks `.env.local` first in local mode, so leave it absent for this setup. Set the ignored root `.env.mode` to `local` for CLI scripts.
