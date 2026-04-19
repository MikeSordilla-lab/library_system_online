# InfinityFree Deployment Checklist

Use this as the practical runbook for this repository's current setup.

## 0) Build a clean deploy zip first (fix for stalled uploads)

If your hosting upload stops partway, do not zip the whole folder directly because it includes `.git` history.

From project root, run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\build-deploy-zip.ps1
```

This creates:

- `deploy/library-system-deploy.zip`

The script excludes non-deployment folders such as `.git`, `.github`, `.vscode`, `docs`, and local test folders, plus sensitive files like `.env*` and `ADMIN-CREDENTIALS.md`.
Upload this generated zip instead of a manual full-folder zip.

## 1) File upload target

- Upload the project into your hosting web root (`htdocs/` or `your-domain/htdocs`).
- Keep `public/` wrappers only as optional compatibility entry points.

## 2) Required configuration

1. Keep your real environment values in `.env.production` (this repo already includes a placeholder template).
2. Update these values from InfinityFree panel/mail provider:
   - `DB_HOST` (usually `sqlXXX.infinityfree.com`)
   - `DB_PORT` (`3306`)
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
   - `BASE_URL` (live URL with trailing slash)
   - `SUPERADMIN_EMAIL`
   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME`
   - `DEBUG_MODE=false`
   - `DEVELOPER_*` metadata values you want shown in the admin About Me page
3. Ensure `.env.mode` is set to `production` on the deployed server.
4. Do not upload any local `.env` variants in your deploy artifact.

Notes:

- Database constants are now centrally defined in `config/database.php`.
- Application bootstrap values come from `config.php`.

## 3) Database import

- Create your MySQL database in InfinityFree panel.
- Import the SQL files from `database/` that exist in this repository, via phpMyAdmin, in this order:
  1. `database/migration-receipts-phase1.sql`
  2. `database/migration-receipts-phase1-safe.sql`
  3. `database/migration-reservations-approval-phase1.sql`
- If you run CLI on a compatible environment, use `php admin/migrations/runner.php list` to view currently registered migrations.

## 4) Security files to keep on server

- `.htaccess` in root (blocks direct `config.php` and all `.env*` file access).
- `.user.ini` in root (keeps errors hidden from visitors).
- `includes/.htaccess` and `database/.htaccess` (deny direct access).
- Keep `database/.htaccess` after upload/extract; do not delete it.

## 5) Files not needed in deployment

- `.git/`, `.github/`
- local IDE and planning artifacts (`.vscode/`, `.specify/`)
- automated local test folders (`tests/`, `testsprite_tests/`)

## 6) Post-upload smoke test

1. Open `BASE_URL` and verify landing page assets load.
2. Open `login.php` and test one valid login.
3. Verify role redirects:
   - admin -> `admin/index.php`
   - librarian -> `librarian/index.php`
   - borrower -> `borrower/index.php`
4. Verify one protected route while logged out returns login redirect/403 behavior.
5. Check one DB write path (e.g., profile update or reservation) and confirm no server errors.

## 7) Troubleshooting quick checks

- DB connection error: verify `DB_HOST/DB_NAME/DB_USER/DB_PASS` exactly.
- Wrong links/assets: verify `BASE_URL` includes correct domain and trailing slash.
- Raw PHP errors visible: verify `.user.ini` exists and `DEBUG_MODE=false`.
