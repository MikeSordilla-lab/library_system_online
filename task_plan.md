# Task Plan: Branded Shared Email Templates + SweetAlert Transaction Feedback

## Goal
Make every user-facing email look polished, branded, consistent, and reliable while preserving existing behavior.

Follow-up goal: after successful librarian transactions, especially approving a reservation, show a polished SweetAlert success confirmation instead of relying only on inline/standard flash text.

## Current Phase
Complete

## Scope
Redesign these 7 current email flows:
1. Registration verification email
2. Resent verification code email
3. Password reset email
4. Reservation approved email
5. Reservation unavailable email
6. Account suspended email
7. Account reinstated email

Out of scope:
- New database settings
- Behavior changes
- PHPMailer or Composer changes
- Database migrations
- New admin settings

Follow-up SweetAlert scope:
- Add a SweetAlert success popup after successful librarian reservation approval.
- Prefer a reusable flash-to-SweetAlert path if the app already uses session flash messages for librarian transactions.
- Avoid database migrations and avoid changing transaction semantics.
- Keep non-JavaScript/inline flash behavior as a graceful fallback where practical.

## Phases

### Phase 0: Plan Handoff & Working Memory Setup
- [x] Restore existing planning context
- [x] Run session catchup where possible
- [x] Capture agreed expanded plan in planning files
- [x] Confirm no implementation in this session
- **Status:** complete

### Phase 1: Discovery & Baseline Mapping
- [x] Locate all 7 current email flows and callers
- [x] Map current function names, subjects, HTML bodies, plain-text bodies, and delivery path
- [x] Identify direct PHPMailer usage outside the mailer layer
- [x] Confirm URL targets for CTAs
- [x] Document findings in `findings.md`
- **Status:** complete

### Phase 2: Shared Email Template Layer
- [x] Create `includes/email_templates.php`
- [x] Build branded HTML email layout with inline CSS and table structure
- [x] Build matching plain-text body support
- [x] Resolve brand context in priority order: `Settings.library_name`, `SMTP_FROM_NAME`, `LibraLink`
- [x] Use `assets/images/library_logo_cropped.png` via absolute `BASE_URL`
- [x] Include visible text brand fallback if images are blocked
- **Status:** complete

### Phase 3: Mailer Layer Helpers
- [x] Keep `send_smtp_mail()`, `send_smtp_mail_once()`, and `send_php_mail_fallback()` as delivery functions
- [x] Add/centralize named email helpers that build template content then call `send_smtp_mail()`
- [x] Preserve existing public function names where practical:
  - `send_reservation_approved_mail()`
  - `send_reservation_unavailable_mail()`
  - `send_account_status_email()`
- [x] Header-clean subjects before sending
- **Status:** complete

### Phase 4: Caller Refactors
- [x] Replace inline verification email HTML in `register.php`
- [x] Replace inline resent verification email HTML in `public/verify.php`
- [x] Replace inline password reset email HTML in `forgot-password.php`
- [x] Refactor admin direct-PHPMailer logic in `includes/admin.php`
- [x] Ensure all app emails go through the shared mailer/template path
- **Status:** complete

### Phase 5: Flow-Specific Content
- [x] Verification email: 6-digit code, verify CTA, fallback URL, no expiry claim, ignore-if-not-registered note
- [x] Resent verification email: same style as verification, subject indicates new code
- [x] Password reset: reset CTA, fallback URL, states 1-hour expiry, security note
- [x] Reservation approved: view reservations CTA, book title, author, pickup deadline, next steps
- [x] Reservation unavailable: neutral tone, browse catalog CTA, copies currently committed explanation
- [x] Account suspended: no CTA, calm explanation, contact library if error
- [x] Account reinstated: sign-in CTA, simple confirmation
- **Status:** complete

### Phase 6: Preview Tool
- [x] Add `tests/email_template_preview.php`
- [x] Render every email variant without sending
- [x] Guard preview so it only works locally/debug mode, not in production
- **Status:** complete

### Phase 7: Regression Tests
- [x] Add/update `tests/email_templates_test.php`
- [x] Keep/update `tests/mailer_fallback_test.php`
- [x] Test template renders HTML
- [x] Test template renders plain text
- [x] Test CTA URL appears
- [x] Test verification code appears
- [x] Test user/book names are escaped safely
- [x] Test subjects are header-cleaned
- [x] Test existing send function names still exist
- [x] Test fallback mail path still passes
- **Status:** complete

### Phase 8: Verification & Delivery
- [x] Run PHP syntax checks on modified PHP files
- [x] Run email template tests
- [x] Run fallback mail test
- [x] Check for direct PHPMailer usage outside mailer layer
- [x] Review focused diffs
- [x] Summarize changed files, tests, and behavior-preservation notes
- **Status:** complete

### Phase 9: Email Logo Image Loading Fix
- [x] Build a deterministic regression loop for the missing email image symptom
- [x] Confirm root cause and choose a minimal reliable fix
- [x] Embed the shared logo for PHPMailer SMTP messages while preserving fallback behavior
- [x] Update preview/tests as needed
- [x] Run syntax checks and regression tests
- [x] Document findings and delivery notes
- **Status:** complete

### Phase 10: SweetAlert Transaction Feedback Discovery
- [x] Locate existing SweetAlert/SweetAlert2 assets or CDN usage
- [x] Locate flash-message/session-notification helpers and layouts
- [x] Inspect librarian reservation approval POST/redirect flow
- [x] Decide the smallest reusable integration point
- **Status:** complete

### Phase 11: SweetAlert Success Renderer Implementation
- [x] Add or reuse a safe SweetAlert2 include on affected pages/layouts
- [x] Render session success flashes as SweetAlert success popups with safely escaped HTML
- [x] Preserve existing visible flash messages as a no-JavaScript fallback if already present
- **Status:** complete

### Phase 12: Reservation Approval Wiring
- [x] Ensure reservation approval sets a success flash after the database transaction succeeds
- [x] Ensure approval failure/caution paths do not show success popups
- [x] Keep email-notification warning details intact if applicable
- **Status:** complete

### Phase 13: Verification & Delivery
- [x] Run PHP syntax checks on modified files
- [x] Search for affected alert/flash usage to avoid regressions
- [x] Summarize changed files and manual verification steps
- **Status:** complete

## Design Rules
- Use LibraLink design colors from `assets/css/libris.css`:
  - ink: `#0f0e0c`
  - paper: `#f7f4ee`
  - cream: `#ede9e0`
  - accent: `#c8401a`
  - gold: `#c9a84c`
  - sage/success: `#4a6741`
- Email markup requirements:
  - inline CSS
  - table layout
  - no external CSS
  - no JavaScript
  - no CSS variables
  - no required web fonts
- Every email should include:
  - hidden preheader
  - branded header/logo
  - clear title
  - short friendly message
  - CTA button when useful
  - fallback raw URL when CTA exists
  - footer saying it is an automated notice
  - matching plain-text version

## Definition of Done
- All 7 emails share one polished design
- No direct PHPMailer usage remains outside the mailer layer
- Existing flows still behave the same
- Plain-text bodies exist for every email
- Preview page shows all variants
- Tests pass
- No database migration required
- No new admin settings required

## Key Questions
1. Where exactly are all 7 email flows generated and sent today?
2. Which functions must remain public for compatibility with current callers?
3. What exact CTA URL is safest for each flow?
4. How should brand context read `Settings.library_name` without coupling templates too tightly to database availability?
5. What direct PHPMailer usages exist outside `includes/mailer.php` and bundled library code?

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use a shared `includes/email_templates.php` layer | Keeps presentation consistent while preserving the mailer as delivery layer |
| Keep `includes/mailer.php` as delivery/helper orchestration layer | Avoids PHPMailer/composer changes and preserves current architecture |
| Do not add database/admin settings | User explicitly scoped this out |
| Do not claim verification code expiry | The app does not currently enforce OTP expiry |
| Add preview under `tests/` and guard it for local/debug only | Lets designs be reviewed without sending email or exposing preview in production |
| Preserve public send helper names where practical | Minimizes caller risk and behavior changes |
| Embed the email logo for PHPMailer SMTP delivery instead of depending on remote image loading | Email recipients cannot load `localhost`/private `BASE_URL` images; CID embedding is more reliable while keeping remote URL fallback for PHP `mail()` |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| `python3` WindowsApps alias failed while running `session-catchup.py` | 1 | Retried with `/c/Python314/python`, which executed successfully with no output |
| `rg` regex for PHPMailer/direct mail usage failed due to backslash escaping (`Unicode property not found`) | 1 | Retried later with fixed-string/simple-pattern searches instead of the failing regex |
| `tests/email_templates_test.php` failed with `Mailer should expose inline-image preparation for SMTP delivery.` | Red test | Added SMTP inline-image preparation and PHPMailer CID embedding; test now passes |
| `session-catchup.py` via `python3` hit the WindowsApps alias again while starting the SweetAlert task | 1 | Retried with explicit `/c/Python314/python`; catchup completed with no output |

## Notes
- Implementation completed for the shared email template layer, mailer helpers, caller refactors, preview tool, and regression tests.
- `git status --short` before implementation showed pre-existing modified `includes/mailer.php` plus untracked planning files.
- Final verification passed: syntax checks, email template test, mailer fallback test, preview smoke test, direct PHPMailer search, direct send search, and `git diff --check`.
- Optional follow-up: open `tests/email_template_preview.php` locally to visually inspect every rendered email before committing.
- Phase 9 added after user reported email images do not load; diagnose loop should validate CID embedding for SMTP mail without changing database/admin settings.
- Phase 10–13 added after user requested SweetAlert success feedback for successful librarian transactions. `librarian/reservations.php` now shows a SweetAlert success popup for `flash_success` messages while keeping the visible flash fallback.
