<?php

/**
 * src/middleware/security_headers.php - Security HTTP Headers
 *
 * Sets standard security headers on all responses.
 * Include at top of bootstrap.php before any output.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; font-src \'self\';');
header('Referrer-Policy: strict-origin-when-cross-origin');