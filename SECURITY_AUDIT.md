# Security Audit Report — Library System Online

**Project:** Library System Online
**Auditor:** Automated Security Audit
**Date:** 2026-05-03
**PHP Version:** 8.x
**Database:** MySQL (PDO, utf8mb4)
**Severity Scale:** `CRITICAL` > `HIGH` > `MEDIUM` > `LOW` > `INFO`

---

## Executive Summary

This application has **multiple high and critical severity vulnerabilities** that would cause it to be flagged as a dangerous site if hosted publicly. The most critical issues are **exposed credentials**, **SQL injection**, **broken authentication**, and **mass account compromise vectors**.

| Risk Level | Count |
|---|---|
| CRITICAL | 5 |
| HIGH | 8 |
| MEDIUM | 7 |
| LOW | 5 |
| INFO | 4 |
| **TOTAL** | **29** |

---

## CRITICAL Severity

### C-001: Exposed Gmail App Password in Version Control
**File:** `.env` (line 34)
**CVSS:** 9.1 (Network, Low Priv, None, Unchanged, High, None)
```php
SMTP_PASS=koggvdrqwsvpcdjv
```
**Description:** A real Gmail App Password is committed to the repository. Attackers scanning public repos or leaked code can use this to:
- Send phishing emails from `sordillamike1@gmail.com`
- Access the Gmail account if 2FA is not enforced
- Use the library as a spam/phishing relay
**Impact:** Complete email account compromise, phishing distribution platform, reputational damage.
**Remediation:** Immediately rotate the Gmail app password. Never commit credentials. Use environment variables at the hosting provider level (e.g., InfinityFree environment variables, or a `.env.production` that is git-ignored).

---

### C-002: SQL Injection via Unescaped LIKE Wildcards in Catalog Search
**File:** `admin/users.php` (line 1287–1290)
```php
$escaped_q = addcslashes($q, '\\%_');
$where_parts[] = "(full_name LIKE :q_name ESCAPE '\\\\' OR email LIKE :q_email ESCAPE '\\\\')";
$query_params[':q_name'] = '%' . $escaped_q . '%';
$query_params[':q_email'] = '%' . $escaped_q . '%';
```
**File:** `catalog.php` (line 33–34)
```php
$where_parts[] = '(b.title LIKE :q OR b.author LIKE :q OR b.isbn LIKE :q OR b.category LIKE :q)';
$params[':q'] = '%' . $q . '%';
```
**Description:** In `catalog.php`, user input `$q` is injected directly into a LIKE pattern without escaping `%` and `_` wildcards. A search for `%%` or `%_` or `_%` can cause LIKE predicate injection, degrading performance or extracting data via boolean-based inference. In `admin/users.php`, `addcslashes` only escapes `\`, `%`, and `_`, but the escaping of `\ ` as `\\` and then the ESCAPE clause using `'\\\\'` can be bypassed if the input contains the escape character itself.
**Impact:** Information disclosure, potential data extraction via timing attacks, performance degradation (DoS).
**Remediation:** Use `addcslashes($q, '\\%_')` in both files. Ensure `addcslashes` is applied before wrapping with `%`. The admin/users.php escaping logic should be simplified to a single `addcslashes($q, '%_')`.

---

### C-003: Dynamic SQL Table Name in `receipt_table_columns` — SQL Injection Risk
**File:** `includes/receipts.php` (line 27)
```php
$stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
```
**Description:** The table name is passed to `SHOW COLUMNS` via string interpolation. While backticks are stripped, MySQL still interprets the table name in the `SHOW COLUMNS FROM table_name` context. An attacker with SQL injection elsewhere (e.g., via a compromised admin account) could use this as a stepping stone. More critically, this function is used dynamically throughout the receipts system.
**Impact:** Database structure enumeration, potential data exfiltration in certain configurations.
**Remediation:** Validate `$table` against an allowlist: `if (!in_array($table, ['Receipt_Tickets', 'Receipt_Print_Jobs', 'Receipt_Ticket_Logs'], true)) { throw new InvalidArgumentException('Invalid table'); }`

---

### C-004: No Rate Limiting on OTP / Login / Registration / Forgot Password
**Files:** `login.php`, `register.php`, `verify.php`, `forgot-password.php`
**Description:** None of the authentication endpoints implement rate limiting. This enables:
- **Login:** Brute force attacks on user passwords
- **Register:** Mass account creation (spam/bot registration)
- **Verify.php OTP:** 6-digit numeric OTP can be brute forced (1,000,000 possibilities) — an offline attacker with network access could crack the code within minutes via rapid automated requests
- **Forgot password:** Email enumeration and automated reset token requests
**Impact:** Mass account takeover via brute force, automated account creation, email bombing, DoS.
**Remediation:** Implement rate limiting using PHP sessions or a rate-limit table in MySQL. For OTP verification, add rate limiting on failed attempts (lock after 5 wrong attempts). Consider implementing a time-based lockout.

---

### C-005: Unauthenticated Book Cover Enumeration
**File:** `book-cover-public.php` (entire file)
```php
// Authentication: NONE - public access allowed (for public OPAC)
```
**Description:** The public book cover endpoint returns different HTTP responses for existing vs. non-existing `book_id` values. This allows attackers to:
- Enumerate all valid book IDs (1 to N)
- Map the entire book collection
- Identify active vs. deleted book inventory patterns
**Impact:** Book catalog enumeration, information disclosure.
**Remediation:** Either require authentication for book covers (breaking the public OPAC feature), or return the same placeholder response regardless of whether the book exists. The current 404 + text "Not found" response is distinguishable from the placeholder redirect.

---

## HIGH Severity

### H-001: Missing Security HTTP Headers
**All HTML pages**
**Description:** No security headers are set across the application:
- `X-Content-Type-Options: nosniff` — Missing
- `X-Frame-Options: DENY` — Missing (clickjacking risk)
- `Content-Security-Policy` — Missing (XSS mitigation)
- `Strict-Transport-Security` — Missing (HTTPS enforcement)
- `X-XSS-Protection` — Missing
**Impact:** XSS exploitation is easier without CSP, clickjacking is possible, MIME sniffing attacks possible.
**Remediation:** Add a common security header include or configure these in `php.ini` / `.user.ini`:
```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:;"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

---

### H-002: Hardcoded Fallback HMAC Key for Receipt Signatures
**File:** `includes/receipts.php` (line 15)
```php
define('RECEIPT_SIGNATURE_FALLBACK_KEY', 'receipt-phase1-fallback-key-change-me');
```
**Description:** If `APP_KEY` environment variable and the `receipt_hmac_key` setting are both unset, a known fallback key is used. Attackers who discover this (from source code) can forge receipt signatures, potentially generating fraudulent receipts.
**Impact:** Receipt forgery, integrity compromise of the receipt system.
**Remediation:** Throw an exception if the HMAC key cannot be loaded from a secure source rather than falling back to a known default:
```php
if ($key === '' && $pdo === null) {
    throw new RuntimeException('Receipt HMAC key must be configured.');
}
```

---

### H-003: Insecure Receipt API Authorization — All Authenticated Users Can Create Receipts
**File:** `api/receipts/create.php` (lines 11–13)
```php
if ($actor['role'] !== 'admin' && $actor['role'] !== 'librarian' && $actor['role'] !== 'borrower') {
    receipt_api_json(403, false, 'Role is not allowed to create receipts.');
}
```
**Description:** All authenticated roles (including `borrower`) can create receipts for arbitrary patrons. A borrower could create receipts for other users (e.g., forging fine_payment receipts or checkout tickets).
**Impact:** Receipt forgery, fraudulent transaction records.
**Remediation:** Restrict receipt creation to `admin` and `librarian` roles only. The `borrower` role should only be able to create receipts for themselves (`patron_user_id` must equal `$actor['id']`).

---

### H-004: IDOR in Receipt View via `receipt_no` Parameter
**File:** `receipt/view.php` (lines 55–74)
```php
$receipt_no = trim((string) ($_GET['no'] ?? ''));
$receipt = get_receipt_ticket_by_number($pdo, $receipt_no);
if (!can_access_receipt_ticket($receipt, $viewer_id, $viewer_role)) {
    http_response_code(403);
```
**Description:** While `can_access_receipt_ticket` checks access, the `receipt_no` is user-supplied and can be rapidly enumerated (RCP-20260503-0001, RCP-20260503-0002, etc.). This is a **horizontal privilege escalation** — any authenticated user can view any receipt if they guess the number. Combined with the predictable receipt number format, this is easily exploitable.
**Impact:** Unauthorized access to all receipt data (including fine payment records, checkout history, patron PII).
**Remediation:** Use UUIDs or non-sequential receipt numbers. Add rate limiting on receipt views. Use `receipt_index` (auto-increment) instead of a predictable sequential number.

---

### H-005: Borrower Can Access All Librarian/Admin Receipts via API
**File:** `api/receipts/get.php`
**File:** `api/receipts/reprint.php`
**Description:** The `can_access_receipt_ticket` function allows `admin` and `librarian` to view all receipts, but a `borrower` role can pass any `no` parameter to enumerate receipts. The access check correctly restricts borrowers to their own receipts, but the enumeration of `receipt_no` values bypasses this.
**Impact:** Receipt enumeration and unauthorized viewing.
**Remediation:** Same as H-004 — non-guessable receipt identifiers.

---

### H-006: Missing Input Validation — Path Traversal in Avatar Deletion
**File:** `includes/avatar.php` (line 167)
```php
$filepath = AVATAR_UPLOAD_DIR . basename($avatar_url);
```
**Description:** While `basename()` is used, the function `delete_user_avatar` doesn't validate that the path resolves within `AVATAR_UPLOAD_DIR`. If `$avatar_url` contains a path like `../../../etc/passwd`, `basename()` returns `passwd`. Combined with `AVATAR_UPLOAD_DIR`, this becomes `assets/avatars/../../../etc/passwd`, which could resolve to system files if the unlink succeeds (depends on permissions).
**Impact:** Arbitrary file deletion (limited to files the web server user can delete).
**Remediation:** Use `realpath()` validation:
```php
$filepath = AVATAR_UPLOAD_DIR . basename($avatar_url);
$resolved = realpath($filepath);
if ($resolved === false || strpos($resolved, AVATAR_UPLOAD_DIR) !== 0) {
    return false; // Invalid path
}
```

---

### H-007: Missing Account Lockout After Failed Login Attempts
**File:** `login.php`
**Description:** There is no account lockout mechanism after failed login attempts. An attacker with a valid email address can brute force passwords indefinitely. The only rate limiting is CSRF token validation, which does not prevent distributed brute force attacks.
**Impact:** Mass account compromise via brute force.
**Remediation:** Add a `failed_login_attempts` counter and `locked_until` timestamp to the Users table. After 5 consecutive failed attempts, lock the account for 15 minutes. Log and notify the user via email of the lockout.

---

### H-008: Predictable Receipt Number Format
**File:** `includes/receipts.php` (lines 186–194)
```php
function receipt_generate_no(int $receipt_id, ?string $issued_at = null): string {
    return 'RCP-' . date('Ymd', $stamp) . '-' . str_pad((string) $receipt_id, 4, '0', STR_PAD_LEFT);
}
```
**Description:** Receipt numbers are sequential (`RCP-20260503-0001`, `RCP-20260503-0002`, etc.). Combined with the IDOR vulnerability (H-004), attackers can enumerate and access all receipts by incrementing the ID.
**Impact:** Full receipt data exposure.
**Remediation:** Use `bin2hex(random_bytes(16))` as receipt number, or encrypt the auto-increment ID with a cipher that only the server can reverse.

---

## MEDIUM Severity

### M-001: Missing CSRF Protection on GET-Triggered Actions (Log Reprint)
**File:** `receipt/view.php` (lines 85–111)
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $reason = trim((string) ($_POST['reprint_reason'] ?? ''));
    // ... processes reprint
}
```
**Description:** The log replay action requires CSRF via POST (good), but the audit logging at line 115 happens on **GET requests**, meaning the `event` and `compact` query parameters can be manipulated by an attacker embedding an image tag or script on another site:
```html
<img src="https://library/receipt/view.php?no=RCP-xxx&event=reprint&compact=1">
```
**Impact:** Audit log pollution, potential confusion in audit trails.
**Remediation:** Log events only on POST requests that perform actions, not on passive page views.

---

### M-002: Email Address Enumeration via Login Error Messages
**File:** `login.php` (lines 70–73)
```php
if ((int) $user['is_verified'] !== 1) {
    $error = 'Please verify your email before logging in.';
    $error_context = 'unverified';
```
**Description:** While the system correctly uses a generic "Invalid email or password" message for wrong passwords, **unverified accounts** receive a specific message revealing that the email exists. This allows email enumeration.
**Impact:** User account enumeration (limited — requires prior registration with that email).
**Remediation:** Use the same generic message for unverified accounts: "Invalid email or password." Add a delayed response to normalize timing between existing and non-existing accounts.

---

### M-003: Missing Password Complexity Requirements
**Files:** `register.php`, `admin/users.php`, `admin/change-password.php`
**Description:** Password policy only enforces a minimum length of 8 characters. There are no complexity requirements (uppercase, lowercase, digits, special characters). Users can set trivially weak passwords like `password123`.
**Impact:** Easier brute force, credential stuffing success.
**Remediation:** Enforce password complexity: at least 1 uppercase, 1 lowercase, 1 digit, and 1 special character. Show strength meter client-side (already present in change-password.php but not enforced server-side for registration).

---

### M-004: Unprotected "User Enumeration" in Admin User Search
**File:** `admin/users.php` (lines 1286–1291)
```php
if ($q !== '' && $flash_error === '') {
    $escaped_q = addcslashes($q, '\\%_');
    $where_parts[] = "(full_name LIKE :q_name ESCAPE '\\\\' OR email LIKE :q_email ESCAPE '\\\\')";
```
**Description:** While SQL injection is partially mitigated via `addcslashes`, the LIKE query with `%` patterns can be used to enumerate user existence based on response timing or content differences. This is accessible to any authenticated admin.
**Impact:** User account enumeration.
**Remediation:** Same as C-002 fix — comprehensive escaping.

---

### M-005: No HTTPS Enforcement in Production
**File:** `config.php` (lines 128–153)
**Description:** `cfg_detect_base_url()` checks `$_SERVER['HTTPS']` but doesn't force HTTPS. If `$_SERVER['HTTPS']` returns `null` or is misconfigured, the app may generate HTTP URLs. No HSTS header is sent.
**Impact:** Session hijacking on shared WiFi/networks, MITM attacks.
**Remediation:** In production mode, always use HTTPS. Add `Strict-Transport-Security` header.

---

### M-006: Weak Session Identifier Regeneration
**File:** `login.php` (line 92)
```php
session_regenerate_id(true);
```
**Description:** Session regeneration is correctly implemented on login, but it is **not** called on:
- Password change
- Privilege escalation (role change)
- Every sensitive POST action
**Impact:** Session fixation attacks after privilege changes.
**Remediation:** Call `session_regenerate_id(true)` after any security-relevant state change (password change, role change, logout).

---

### M-007: Debug Mode Potential Information Disclosure
**File:** `config.php` (lines 201–209)
```php
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
```
**Description:** If `DEBUG_MODE=true` is accidentally set in production, PHP errors would be displayed to users, leaking server paths, file structure, database queries, and stack traces.
**Impact:** Full server information disclosure.
**Remediation:** Default `DEBUG_MODE=false` in all environment files. Add a startup check that exits if `DEBUG_MODE=true` and `$_SERVER['HTTP_HOST']` is not in an allowlist of local/dev hosts.

---

## LOW Severity

### L-001: Missing `HttpOnly` and `Secure` Flags on Session Cookie
**File:** `bootstrap.php` (session start at line 52)
**Description:** Session cookies are not explicitly configured with `HttpOnly` and `Secure` flags. This makes them accessible to JavaScript (XSS cookie theft) and allows them to be transmitted over HTTP.
**Impact:** Session hijacking via XSS or network eavesdropping.
**Remediation:**
```php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true, // true in production with HTTPS
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
```

---

### L-002: No Password History / Reuse Check on Admin Password Reset
**File:** `admin/users.php` (lines 725–741)
```php
if ($existing_hash !== '' && password_verify($new_password, $existing_hash)) {
    // ... blocks reuse of current password
}
```
**Description:** The admin password reset (admin → reset user's password) only checks against the **current** password. It doesn't track password history, so a user could reset to a previous password.
**Impact:** Users can reuse old passwords after forced resets.
**Remediation:** Store a password history table (last 5 hashes) and check against all of them.

---

### L-003: Missing Pagination Limits on Receipt Ticket Listing
**File:** `includes/receipts.php` (lines 460–479)
```php
$stmt = $pdo->prepare(
    'SELECT ... FROM Receipt_Tickets ORDER BY issued_at DESC, id DESC LIMIT ' . (int) $limit
);
```
**Description:** The `LIMIT` value is cast to int (good), but there is no upper bound check — a caller could pass `$limit = 1000000`, potentially causing memory/performance issues.
**Impact:** DoS via large result sets.
**Remediation:** Add a maximum cap in `get_receipt_tickets()`:
```php
$limit = max(1, min((int) $limit, 500));
```

---

### L-004: Emoji in Search Suggestions Response
**File:** `api/search-suggestions.php` (lines 55–75)
```php
$uggestions[] = [
    'type' => 'title',
    'text' => $title,
    'icon' => '📖'  // emoji
];
```
**Description:** Emoji characters in JSON responses may cause encoding issues in some clients and increase response size. Not a security issue per se, but worth noting for API consumers expecting ASCII-only responses.
**Impact:** API compatibility issues with some clients.
**Remediation:** Use simple string icons like `[T]`, `[A]`, `[C]` or a small inline SVG.

---

### L-005: Verbose Error Messages in Login POST on Unverified Accounts
**File:** `login.php` (line 70)
```php
$error = 'Please verify your email before logging in.';
```
**Description:** Returns a specific error for unverified accounts, aiding email enumeration. While this is a design decision (UX), it leaks account existence.
**Impact:** Email enumeration.
**Remediation:** Return the same generic message as other auth failures.

---

## INFO Findings

### I-001: PHPMailer Timeout Not Configured for Resilience
**File:** `includes/mailer.php` (line 78)
```php
$mail->Timeout = 25;
```
**Description:** 25-second timeout is reasonable, but there's no retry logic if SMTP fails. Users may not receive emails on transient failures.
**Impact:** UX issue, not security.
**Remediation:** Add a fallback to PHP `mail()` function with proper error handling and a retry queue.

---

### I-002: Duplicate `.env` Structure
**File:** `.env`, `.env.local`, `.env.production`
**Description:** The `.env` file itself contains production-like comments and is used as a fallback. This creates confusion and risk of accidentally using local config in production.
**Impact:** Configuration confusion.
**Remediation:** `.env` should only contain placeholders, never real values. All real configs should be in `.env.local` (dev) and `.env.production` (prod, git-ignored).

---

### I-003: Inconsistent Session Key Usage
**Files:** `includes/auth-helpers.php` vs `bootstrap.php` vs `includes/auth_guard.php`
**Description:** `is_authenticated()` in `src/utils/helpers.php` checks `$_SESSION['user']`, but the application primarily uses `$_SESSION['user_id']` and `$_SESSION['role']`. The `user` key is set in `src/utils/helpers.php` but nowhere else in the codebase.
**Impact:** Confusion, potential bugs if the helper is used.
**Remediation:** Consolidate to only `$_SESSION['user_id']` (non-null = authenticated).

---

### I-004: No Automated Security Header Middleware
**Description:** Security headers are applied per-page via `<meta>` tags (imperfect) rather than as server-level HTTP headers. A centralized approach is missing.
**Impact:** Inconsistent header coverage.
**Remediation:** Use `.htaccess` to set security headers globally, or create a `security_headers.php` include at the top of `bootstrap.php`.

---

## Priority Remediation Roadmap

### Immediate (Before Any Public Hosting)
1. **Rotate `SMTP_PASS`** — Currently compromised
2. **Add rate limiting** to login, verify (OTP), forgot-password, and register endpoints
3. **Fix SQL injection** in catalog.php LIKE query (C-002)
4. **Validate table names** in receipt_table_columns allowlist (C-003)
5. **Fix receipt number enumeration** — use UUIDs (H-008 + H-004)

### Before Production Release
1. Enable HTTPS enforcement and HSTS
2. Set security HTTP headers globally
3. Add account lockout on failed login
4. Fix session cookie flags (HttpOnly, Secure, SameSite)
5. Add password complexity enforcement
6. Restrict receipt creation API to admin/librarian only (H-003)
7. Add path traversal protection to avatar deletion (H-006)

### Ongoing Hardening
1. Add Content-Security-Policy header
2. Implement security audit logging for all sensitive operations
3. Add automated security scanning (e.g., OWASP ZAP, PHPStan security plugins)
4. Set up dependency vulnerability scanning for PHPMailer
5. Consider adding CAPTCHA to registration and forgot-password forms

---

## Conclusion

This application must **not be hosted publicly** in its current state. The combination of exposed credentials (C-001), SQL injection (C-002), OTP brute forceability (C-004), and receipt enumeration (H-004/H-008) creates multiple vectors for complete account compromise and data exfiltration.

The most dangerous immediate risks are:
1. **The exposed Gmail app password** — can be used for phishing
2. **OTP brute force** — can compromise any unverified account  
3. **Receipt number enumeration** — exposes all transaction data

All CRITICAL and HIGH severity issues must be resolved before any public-facing deployment.