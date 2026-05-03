## Problem Statement

The LibraLink has been audited and found to contain 5 CRITICAL and 8 HIGH severity vulnerabilities that make it unsafe for public hosting. The most dangerous are: an exposed Gmail app password in version control, SQL injection via unescaped LIKE wildcards, OTP brute force vulnerability, predictable receipt number enumeration, and missing rate limiting on all auth endpoints. These vulnerabilities expose the system to mass account compromise, data exfiltration, and abuse as a phishing relay.

## Solution

Address all CRITICAL and HIGH severity vulnerabilities identified in the 2026-05-03 security audit, plus the most impactful MEDIUM findings. The solution follows a phased approach: immediate hardening before any public hosting, then production-grade hardening, then ongoing security improvements.

## User Stories

1. As a system administrator, I want credentials like SMTP_PASS to never be committed to version control, so that attackers cannot harvest them from public repos
2. As a system administrator, I want SQL injection in LIKE queries to be prevented, so that attackers cannot extract data via predicate injection
3. As a system administrator, I want table names in dynamic SQL queries to be validated against an allowlist, so that the receipts system cannot be used as a pivot point for further attacks
4. As a system administrator, I want rate limiting on login, registration, OTP verification, and forgot-password endpoints, so that brute force and automated attacks are throttled
5. As a patron, I want book cover enumeration to return uniform responses, so that attackers cannot map the entire catalog via response timing
6. As a system administrator, I want security HTTP headers set globally on all responses, so that XSS, clickjacking, and MIME sniffing attacks are mitigated
7. As a system administrator, I want the receipt system HMAC key to be required and not fallback to a known default, so that receipt signatures cannot be forged
8. As a librarian, I want receipt creation restricted to admin and librarian roles, so that borrowers cannot forge fine_payment receipts
9. As a patron, I want receipt numbers to be non-guessable, so that I cannot enumerate and view other patrons receipts (horizontal privilege escalation)
10. As a system administrator, I want receipt number enumeration blocked via non-sequential IDs, so that the entire receipt database cannot be scraped
11. As a system administrator, I want avatar deletion to validate paths with realpath(), so that path traversal cannot delete arbitrary files
12. As a system administrator, I want account lockout after consecutive failed login attempts, so that brute force attacks are blocked
13. As a borrower, I want my session cookies to have HttpOnly and Secure flags, so that XSS cannot steal my session and it is not sent over HTTP
14. As a system administrator, I want password complexity enforced server-side, so that users cannot set trivially weak passwords
15. As a system administrator, I want generic error messages for all authentication failures, so that email enumeration is prevented
16. As a system administrator, I want session IDs regenerated after privilege changes, so that session fixation attacks are prevented
17. As a system administrator, I want debug mode to be blocked in production, so that error messages cannot leak server paths and stack traces
18. As a system administrator, I want HTTPS enforced and HSTS headers sent, so that sessions cannot be hijacked on shared networks
19. As a patron, I want the pagination limit on receipt listing to have a maximum cap, so that a single request cannot cause memory/DoS issues
20. As a system administrator, I want CSRF protection on all state-changing GET-triggered actions, so that audit logs cannot be polluted via image tags

## Implementation Decisions

### Modules to be Built/Modified

1. **Rate Limiting Module** (new file)
   - In-memory sliding window rate limiter using PHP sessions
   - Functions: rate_limit_check($action, $max_attempts, $window_seconds), rate_limit_headers()
   - Applied to: login, register, verify (OTP), forgot-password
   - OTP verify: lock after 5 wrong attempts for 15 minutes

2. **LIKE Query Escaping Helper** (modified in receipts.php and catalog.php)
   - Add escape_like_wildcards(string q) helper using addcslashes($q, '%_')
   - Apply in catalog.php line 33-34 and admin/users.php line 1287-1290

3. **Receipt Table Allowlist** (modified in includes/receipts.php)
   - Add allowlist validation in receipt_table_columns(): ['Receipt_Tickets', 'Receipt_Print_Jobs', 'Receipt_Ticket_Logs']
   - Throw InvalidArgumentException on unknown table

4. **Receipt Number UUID Generator** (modified in includes/receipts.php)
   - Replace receipt_generate_no() with bin2hex(random_bytes(16)) format
   - Migration: add receipt_no_alias column for backwards compat, or generate new format on new receipts

5. **HMAC Key Strict Mode** (modified in includes/receipts.php)
   - Throw RuntimeException if key cannot be loaded from APP_KEY env or receipt_hmac_key setting
   - Remove or make RECEIPT_SIGNATURE_FALLBACK_KEY throw

6. **Receipt API Authorization Fix** (modified in api/receipts/create.php)
   - Restrict borrower role from creating receipts for other patrons
   - Borrower can only create receipts where patron_user_id equals actor.id

7. **Path Traversal Protection** (modified in includes/avatar.php)
   - Add realpath() validation in delete_user_avatar()
   - Validate resolved path starts with AVATAR_UPLOAD_DIR

8. **Account Lockout Table** (new migration)
   - Add failed_login_attempts INT DEFAULT 0, locked_until TIMESTAMP NULL to Users table
   - Check locked_until in login before password verify; lock 15 min after 5 failures

9. **Security Headers Middleware** (new file src/middleware/security_headers.php)
   - Set: X-Content-Type-Options: nosniff, X-Frame-Options: DENY, Strict-Transport-Security, Content-Security-Policy
   - Include at top of bootstrap.php

10. **Session Cookie Flags** (modified in bootstrap.php)
    - Add session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict'])

11. **Password Complexity Validation** (new helper applied to register, admin/users.php, admin/change-password.php)
    - Require: 1 uppercase, 1 lowercase, 1 digit, 1 special character, min 8 chars
    - Server-side validation in addition to client-side strength meter

12. **Generic Auth Error Messages** (modified in login.php)
    - Return same "Invalid email or password." for unverified accounts
    - Add timing normalization (random sleep) to normalize response times

13. **Session Regeneration on Privilege Change** (multiple files)
    - Add session_regenerate_id(true) after password change, role change, logout

14. **Debug Mode Production Guard** (modified in config.php)
    - Exit with error if DEBUG_MODE=true and HTTP_HOST not in local/dev allowlist

15. **HTTPS Enforcement** (modified in config.php)
    - In production mode, redirect HTTP to HTTPS
    - Set Strict-Transport-Security header

16. **Book Cover Enumeration Fix** (modified in book-cover-public.php)
    - Return same placeholder response (same HTTP status, same body) for existing and non-existing book IDs
    - Use 200 with placeholder image for both cases

17. **Pagination Limit Cap** (verify in includes/receipts.php)
    - $limit = max(1, min((int) $limit, 500)) already present at line 458 -- verify everywhere

18. **CSRF on GET-triggered Audit Log** (modified in receipt/view.php)
    - Move log_receipt_ticket_event call out of GET handler; only log on POST actions

### Schema Changes

- Users table: add failed_login_attempts INT DEFAULT 0, locked_until TIMESTAMP NULL
- Receipt_Tickets: optional receipt_no_alias for non-sequential IDs (backwards compat)

### API Contract Changes

- api/receipts/create.php: borrower role restricted to self-only receipts
- api/receipts/get.php and api/receipts/reprint.php: same access restrictions, rate limiting
- All API responses: add security headers

## Testing Decisions

Tests should validate external behavior only, not implementation details.

### What Makes a Good Test

- Test that rate limiting actually blocks repeated requests (use a test HTTP client)
- Test that SQL LIKE queries with %, _, \ input are properly escaped and return correct results
- Test that a borrower cannot create a receipt for another patron via the API (expect 403)
- Test that receipt numbers are non-sequential and non-guessable (collision resistance)
- Test that account lockout activates after 5 failed attempts
- Test that generic error messages are returned for all auth failures
- Test that path traversal payloads in avatar deletion are rejected
- Test that security headers are present on all responses

### Modules to Test

1. **Rate Limiter**: Unit test rate limit logic, integration test with auth endpoints
2. **LIKE Escape Helper**: Unit test with edge cases (%, _, \, %%, %_)
3. **Receipt Access Control**: Integration test: borrower can access own receipts, cannot access others
4. **Receipt Number Generation**: Unit test uniqueness and non-sequential property
5. **Path Traversal Validation**: Unit test with traversal payloads

### Prior Art

- Existing tests in the codebase use PHPUnit-style assertions
- Auth endpoint tests would follow patterns from existing integration tests
- Receipt system has existing test patterns in includes/receipts.php functions

## Out of Scope

- PHPMailer retry logic (INFO finding)
- Password history table (LOW -- L-002)
- Emoji removal from search suggestions (LOW -- L-004)
- CSP header refinement (ongoing hardening)
- Automated security scanning setup (ongoing hardening)
- CAPTCHA on registration (ongoing hardening)
- Dependency vulnerability scanning for PHPMailer (ongoing hardening)

## Further Notes

- The Gmail app password must be rotated immediately before any code changes -- it is currently compromised
- Receipt number migration from sequential to UUID requires a data migration step and backwards-compat strategy
- Account lockout requires checking locked_until less than now() on each login
- Rate limiting state stored in PHP sessions means it resets on session destruction -- consider a session-less rate limit table for production
- All security header changes should be tested thoroughly for false positives with the existing CSP -- the current inline styles/scripts may need to be allowlisted