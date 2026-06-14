# Findings & Decisions: Branded Shared Email Templates + SweetAlert Transaction Feedback

## Requirements Captured
- Make every user-facing email polished, branded, consistent, and reliable.
- Preserve existing behavior and delivery semantics.
- Redesign these 7 current email flows:
  1. Registration verification email
  2. Resent verification code email
  3. Password reset email
  4. Reservation approved email
  5. Reservation unavailable email
  6. Account suspended email
  7. Account reinstated email
- Do not add database settings, admin settings, migrations, Composer dependencies, or PHPMailer changes.
- Keep PHPMailer bundled; do not add it via Composer.

## Template Layer Requirements
- Create `includes/email_templates.php`.
- Responsibilities:
  - Build branded HTML layout.
  - Build matching plain-text body.
  - Resolve brand context in this priority order:
    1. `Settings.library_name`
    2. `SMTP_FROM_NAME`
    3. `LibraLink`
  - Use `assets/images/library_logo_cropped.png` via absolute `BASE_URL`.
- Email-client compatibility requirements:
  - Inline CSS.
  - Table layout.
  - No external CSS.
  - No JavaScript.
  - No CSS variables.
  - No required web fonts.
  - Visible text brand fallback if image is blocked.

## Brand/Design Requirements
Use LibraLink design colors from `assets/css/libris.css`:
- ink: `#0f0e0c`
- paper: `#f7f4ee`
- cream: `#ede9e0`
- accent: `#c8401a`
- gold: `#c9a84c`
- sage/success: `#4a6741`

Each email should include:
- Hidden preheader.
- Branded header/logo.
- Clear title.
- Short friendly message.
- CTA button when useful.
- Fallback raw URL when CTA exists.
- Footer saying it is an automated notice.
- Matching plain-text version.

## Mailer Layer Requirements
- Keep `includes/mailer.php` as the delivery layer.
- Keep current delivery functions:
  - `send_smtp_mail()`
  - `send_smtp_mail_once()`
  - `send_php_mail_fallback()`
- Add or centralize named email helpers that build template content, then call `send_smtp_mail()`.
- Preserve existing public function names where practical:
  - `send_reservation_approved_mail()`
  - `send_reservation_unavailable_mail()`
  - `send_account_status_email()`

## Caller Refactor Targets
Replace inline email HTML in:
- `register.php`
- `public/verify.php`
- `forgot-password.php`

Refactor admin direct-PHPMailer logic in:
- `includes/admin.php`

Goal: all app emails go through the shared mailer/template path.

## Flow-Specific Content Requirements
| Flow | Content Rules |
|------|---------------|
| Verification email | Large 6-digit code; CTA: Verify email; fallback URL; do not claim expiry; security note to ignore if they did not register |
| Resent verification code | Same style as verification; subject indicates new verification code |
| Password reset | CTA: Reset password; fallback URL; states expires in 1 hour; security note to ignore/contact library if not requested |
| Reservation approved | CTA: View my reservations; include book title, author, pickup deadline, next steps |
| Reservation unavailable | Neutral tone; CTA: Browse catalog; explain copies are currently committed and borrower can reserve again later |
| Account suspended | No CTA; calm explanation; tell user to contact library if they believe it is an error |
| Account reinstated | CTA: Sign in; simple confirmation |

## Preview/Test Requirements
- Add `tests/email_template_preview.php`.
- Preview renders every email variant without sending.
- Preview must be guarded so it only works locally/debug mode, not in production.
- Add tests for:
  - Template renders HTML.
  - Template renders plain text.
  - CTA URL appears.
  - Verification code appears.
  - User/book names are escaped safely.
  - Subjects are header-cleaned.
  - Existing send function names still exist.
  - Fallback mail test still passes.
- Likely files:
  - `tests/email_templates_test.php`
  - `tests/mailer_fallback_test.php`

## Prior Local Context From Existing Planning Files
- Earlier completed work targeted only `send_reservation_approved_mail()` in `includes/mailer.php`.
- Earlier findings indicated:
  - Reservation approval POST handling is in `librarian/reservations.php`.
  - `librarian/reservations.php` calls `send_reservation_approved_mail(...)` after successful `transition_reservation_status(...)`.
  - `send_reservation_approved_mail` and reservation notification helpers are defined in `includes/mailer.php`.
  - `borrower/my_books.php` has a Ready for Pickup section and is a suitable reservation-approved CTA target.
  - There was no centralized reusable email template layer at that time.
  - `public/verify.php` built HTML email strings inline.
- Current `git status --short` before updating this plan showed:
  - Modified: `includes/mailer.php`
  - Untracked: `findings.md`, `progress.md`, `task_plan.md`

## Discovery Log
### Initial grep/test inventory
- Current user-facing email flows found by grep:
  - Registration verification: `register.php` builds HTML/plain text and calls `send_smtp_mail()`.
  - Resent verification code: `public/verify.php` builds HTML/plain text and calls `send_smtp_mail()`.
  - Password reset: `forgot-password.php` builds HTML/plain text and calls `send_smtp_mail()`.
  - Reservation approved/unavailable: `librarian/reservations.php` calls helpers in `includes/mailer.php`.
  - Account suspended/reinstated: `admin/users.php` calls `send_account_status_email()` from `includes/admin.php`.
- Direct PHPMailer usage outside bundled library and `includes/mailer.php` appears in `includes/admin.php` for account status emails.
- Existing PHP tests are standalone scripts in `tests/`; current mail-related test is `tests/mailer_fallback_test.php`.

### Mailer/admin baseline
- `includes/mailer.php` already defines delivery helpers `send_php_mail_fallback()`, `send_smtp_mail_once()`, and `send_smtp_mail()` with SMTP-to-PHP-mail fallback behavior.
- `includes/mailer.php` already includes `mailer_clean_header_value()` and `mailer_format_datetime()` helpers.
- `includes/mailer.php` currently has reservation-specific helpers; the approved helper has a custom inline branded design from earlier work, while unavailable remains plain paragraphs.
- `includes/admin.php` directly requires PHPMailer classes, configures SMTP, and sends account suspended/reinstated mail directly; this is the only confirmed app-level direct PHPMailer usage outside the mailer layer so far.

### Registration/resend baseline
- `register.php` already includes `includes/mailer.php` and only sends verification email for borrower registrations; librarian registration remains pending admin approval without verification email.
- `register.php` currently builds a `BASE_URL . 'verify.php?email=...'` CTA, HTML body with large OTP, plain-text body, subject `Verify your LibraLink account`, then calls `send_smtp_mail()`.
- `public/verify.php` already includes `includes/mailer.php`; resend flow updates `Users.verification_token`, builds the same verify CTA, subject `Your new LibraLink verification code`, then calls `send_smtp_mail()`.
- Verification code expiry is not enforced by these email flows, matching the no-expiry-claim requirement.

### Password reset/reservation caller baseline
- `forgot-password.php` already includes `includes/mailer.php`; for verified users it stores `password_reset_token` and `password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR)`, builds `BASE_URL . 'reset-password.php?token=...'`, then calls `send_smtp_mail()` with subject `Reset your LibraLink password`.
- Password reset behavior currently says the link expires in 1 hour in both HTML and plain text, matching the new content requirement.
- `librarian/reservations.php` already sends reservation emails only after committing reservation state changes, preserving non-fatal email failure behavior via logs/flash messages.
- Reservation-approved helper receives borrower email/name, title, author, and `expires_at`; unavailable helper receives borrower email/name and title for auto-rejected competing reservations.

### Settings/tests baseline
- `includes/settings.php` provides `get_setting(PDO $pdo, string $key, string $default = '')`, backed by the `Settings` table and an in-request cache.
- Template brand lookup can safely attempt `get_db()` + `get_setting()` inside a try/catch, falling back to `SMTP_FROM_NAME` and then `LibraLink` for CLI/test environments without a database.
- Existing mailer tests define constants before requiring `includes/mailer.php`; new email template tests should do the same to avoid depending on local `.env`/database state.
- `tests/mailer_fallback_test.php` uses a small local `assert_true()` helper and exits non-zero on failures; follow this style for `tests/email_templates_test.php`.

### Config/search baseline
- `config.php` defines `DEBUG_MODE`; preview guard can combine `DEBUG_MODE` with localhost/IP checks for defense-in-depth.
- Environment detection treats `localhost`, `127.0.0.1`, and `::1` as local hosts.
- A first direct-PHPMailer grep used an over-escaped regex and failed; retry with fixed-string/simple-pattern searches instead of repeating the same command.
- Fixed-string PHPMailer search confirms direct app-level PHPMailer instantiation remains only in `includes/admin.php` and `includes/mailer.php`; `includes/admin.php` should be refactored to remove direct PHPMailer usage.
- Fixed-string `send_smtp_mail(` search confirms direct sends in `register.php`, `public/verify.php`, and `forgot-password.php`, plus helper calls inside `includes/mailer.php`.
- `config.php` production mode may redirect non-HTTPS requests when loaded from a web request, but CLI tests can define constants before requiring mailer/template files to avoid config/bootstrap side effects.
- Seed SQL includes `Settings.library_name = 'LibraLink'`; admin settings and receipts already use the key, so using `library_name` for email brand context is aligned with existing data.
- `includes/db.php` only defines `get_db()`/`log_event()` after loading config if needed; template brand lookup should avoid requiring it unless needed and catch `Throwable` because incomplete DB config can exit if `get_db()` is called in the wrong environment.
- CTA targets confirmed: verify `BASE_URL . 'verify.php?email=...'`, reset `BASE_URL . 'reset-password.php?token=...'`, reservation approved `BASE_URL . 'borrower/my_books.php'`, reservation unavailable `BASE_URL . 'catalog.php'` or borrower catalog; use public `catalog.php` to avoid assuming auth for unavailable email, reinstated `BASE_URL . 'login.php'`.
- `admin/users.php` loads `includes/db.php` and `includes/admin.php` before calling `send_account_status_email()` for suspend/reinstate; moving that function into/through `includes/mailer.php` is safe if `includes/admin.php` requires the mailer layer.

## Implementation Notes
- `includes/email_templates.php` now owns shared rendering/builders; `includes/mailer.php` should only call these builders and pass subject/html/text to delivery helpers.
- Registration and resend callers can be reduced to generating their existing verify URL and calling named helper functions, preserving token generation, DB writes, logging, and user-visible errors.
- Password reset caller can likewise keep token persistence/expiry behavior and replace only inline body construction with `send_password_reset_mail()`.
- `includes/admin.php` can retain all admin helper functions while requiring the mailer layer for `send_account_status_email()`; removing the direct PHPMailer block eliminates app-level direct PHPMailer usage outside `includes/mailer.php`.

## Verification Findings
- PHP syntax checks passed for all modified application, preview, and test files.
- `tests/email_templates_test.php` passed and covers shared HTML/text rendering, CTA/code presence, HTML escaping, header cleaning, and helper function existence.
- `tests/mailer_fallback_test.php` still passes after requiring the new template layer from `includes/mailer.php`.
- Fixed-string PHPMailer constructor search now finds app-level instantiation only in `includes/mailer.php`.
- Fixed-string `send_smtp_mail(` search now shows direct sends only inside `includes/mailer.php` helper functions.
- `includes/admin.php` is required by admin pages after `includes/auth_guard.php`/`includes/db.php`, so loading the mailer layer there uses the already-loaded application config path.

## Image Loading Bug Findings
- User reports images do not load in received email.
- Current template logo uses a remote absolute `BASE_URL . 'assets/images/library_logo_cropped.png'` image URL.
- Likely root cause: recipients/email clients cannot fetch local/private/non-public `BASE_URL` images (for example `localhost`), and many clients proxy/block remote images.
- Deterministic regression seam: prepare generated HTML for SMTP delivery and assert that the logo is rewritten to a CID reference with a local embedded image attachment candidate, while fallback/preview can keep remote URLs.
- Added failing regression loop in `tests/email_templates_test.php`; current failure is `Mailer should expose inline-image preparation for SMTP delivery.`
- Ranked hypotheses:
  1. Remote logo URL is not reachable by recipient email clients because `BASE_URL` may be localhost/private or image loading is proxied/blocked. Prediction: embedding the logo as a CID attachment for PHPMailer SMTP makes generated SMTP HTML independent of remote image fetches.
  2. The logo path/URL is wrong. Prediction: local file existence or generated URL assertions would fail; current local file exists and generated URL is deterministic.
  3. Email clients block all images by default. Prediction: CID embedding improves clients that allow inline attachments, but some clients may still require user image permission; visible text brand fallback remains necessary.
- Local logo file exists at `assets/images/library_logo_cropped.png` and is a PNG image, so CID embedding can use the bundled asset directly.
- Bundled PHPMailer supports `addEmbeddedImage($path, $cid, $name, $encoding, $type, $disposition)` and throws if the file is not accessible.
- Implemented fix: `send_smtp_mail_once()` now prepares SMTP HTML by replacing the logo URL with `cid:libralink-logo` and attaching `assets/images/library_logo_cropped.png` inline through PHPMailer.
- PHP `mail()` fallback keeps the remote logo URL because it does not build multipart/related inline attachments; if embedding fails, PHPMailer falls back to the original remote-logo HTML instead of sending a broken CID reference.
- Regression now passes: `tests/email_templates_test.php` asserts CID rewriting, local logo existence, and embedded image metadata.

## SweetAlert Transaction Feedback Requirements
- User requested that after a successful transaction, such as a librarian approving a reservation, the UI should show a successful SweetAlert.
- Initial target: librarian reservation approval success path.
- Prefer a reusable flash-message integration if present, so future successful librarian transactions can reuse the same popup behavior.
- Preserve existing transaction behavior and do not show success when the database transition fails.

## SweetAlert Discovery Log
### Initial search inventory
- `includes/head.php` already includes SweetAlert2 CSS/JS and `assets/js/sweetalert-utils.js` by default unless a page sets `$includeSweetAlert = false`.
- `assets/js/sweetalert-utils.js` provides the existing reusable wrapper functions.
- Existing pages use body data attributes or hidden flash marker elements to bridge PHP messages into SweetAlert scripts.
- `librarian/reservations.php` already sets `$_SESSION['flash_success'] = 'Reservation approved...'` only after `transition_reservation_status(...)` succeeds; it also sets `flash_info` for email-warning/concurrency paths and `flash_error` for failures.
- `librarian/reservations.php` currently renders visible `.flash` elements for success/error/info but has no page-specific SweetAlert popup.
- Many librarian pages use the same `$_SESSION['flash_success']` / `flash_error` convention, so a shared helper could be useful, but the first safe target is the reservations page.

### Focused file reads
- `includes/head.php` confirms SweetAlert2 is loaded by default, including `sweetalert-utils.js`, unless `$includeSweetAlert` is explicitly set false.
- `librarian/reservations.php` does not set `$includeSweetAlert = false`, so SweetAlert2 and `window.sweetAlertUtils` should be available on that page.
- `sweetalert-utils.js` exposes `window.sweetAlertUtils.showSuccess(title, message, timer)` and other helpers; `showSuccess` uses themed SweetAlert2 and auto-closes by default.
- `librarian/reservations.php` already reads `flashSuccess`, `flashError`, and `flashInfo`, unsets the session values, and renders visible flash fallback messages. Adding data attributes plus a small script can trigger SweetAlert without changing POST/transaction behavior.
- The smallest first change is page-local: only show SweetAlert when `$flashSuccess !== ''` on `librarian/reservations.php`, using JSON encoding to safely pass text into JavaScript and retaining the visible `.flash-success` fallback.

### Existing flash popup patterns
- No generic PHP flash-to-SweetAlert helper was found.
- `librarian/catalog.php` already uses the same pattern needed here: render a hidden `data-message` element next to the visible flash fallback, then a page script calls `sweetAlertUtils.showSuccess('Success', message, 3000)` after load.
- `admin/users.php` and `assets/js/admin-account.js` use similar hidden-element/data-message patterns.
- Decision: follow the existing `librarian/catalog.php` pattern in `librarian/reservations.php` instead of introducing a new shared helper for one requested page. This keeps the change minimal and consistent.

## SweetAlert Implementation Notes
- `librarian/reservations.php` now renders a hidden `#reservations-flash-success` element with the same success text as the visible fallback flash.
- A DOMContentLoaded handler reads that marker and calls `window.sweetAlertUtils.showSuccess('Success', escapedMessage, 3000)`.
- The handler waits for DOMContentLoaded so the deferred `sweetalert-utils.js` loaded from `includes/head.php` has executed first.
- The visible `.flash-success` remains unchanged for accessibility and no-JavaScript fallback.
- No POST/transaction code changed; approval success still depends on the existing `$changed` result after the database transition/commit.

## SweetAlert Verification Findings
- `php -l librarian/reservations.php` passed.
- Static search confirmed the new `reservations-flash-success` marker and SweetAlert call are present in `librarian/reservations.php`.
- `git diff --check` passed.

## Open Discovery Items
- None for the initial requested reservation-approval success popup.

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| `session-catchup.py` first attempt used the WindowsApps `python3` alias and failed because Python was not installed there | Retried with `/c/Python314/python`; script ran successfully and emitted no catchup output |

## Resources
- Project guide: `AGENTS.md` from harness context.
- Planning skill: `C:\Users\Mike\.agents\skills\planning-with-files\SKILL.md`.

## Visual/Browser Findings
-

---
*Update this file after every 2 view/browser/search operations and before major implementation decisions.*
