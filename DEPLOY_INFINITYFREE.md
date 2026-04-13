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

The script excludes non-deployment folders such as `.git`, `.github`, `.vscode`, `docs`, and local test folders.
Upload this generated zip instead of a manual full-folder zip.

## 1) File upload target

- Upload the project into your hosting web root (`htdocs/` or `your-domain/htdocs`).
- Keep `public/` wrappers only as optional compatibility entry points.

## 2) Required configuration

1. Copy `.env.sample` to `.env`.
2. Update these values from InfinityFree panel:
   - `DB_HOST` (usually `sqlXXX.infinityfree.com`)
   - `DB_PORT` (`3306`)
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
3. Set `BASE_URL` to your live URL with trailing slash.
4. Set `DEBUG_MODE=false`.

Notes:

- Database constants are now centrally defined in `config/database.php`.
- Application bootstrap values come from `config.php`.

## 3) Database import

- Create your MySQL database in InfinityFree panel.
- Import `database/schema.sql` via phpMyAdmin.

## 4) Security files to keep on server

- `.htaccess` in root (blocks direct `config.php` access).
- `.user.ini` in root (keeps errors hidden from visitors).
- `includes/.htaccess` and `database/.htaccess` (deny direct access).

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
