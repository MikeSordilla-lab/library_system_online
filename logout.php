<?php
/**
 * logout.php — Session Destroy & Redirect (US3, FR-027)
 *
 * Logs the LOGOUT event BEFORE destroying the session (actor_id comes from session).
 * Clears $_SESSION, destroys the session, then redirects to login.php.
 */

require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FR-027: Log LOGOUT before destroying the session (actor_id and email still available here)
if (!empty($_SESSION['user_id'])) {
    try {
        log_event(get_db(), 'LOGOUT', (int) $_SESSION['user_id'], null, null, 'SUCCESS', $_SESSION['role'] ?? null, $_SESSION['email'] ?? null);
    } catch (\Throwable $e) {
        error_log('[logout.php] Failed to log LOGOUT event: ' . $e->getMessage());
        // Non-fatal: continue with logout even if logging fails
    }
}

// FR-027: Clear session variables and destroy the session
session_unset();
session_destroy();

// Do not redirect via header, allow the HTML with SweetAlert below to render
// header('Location: ' . BASE_URL . 'login.php');
// exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/head.php'; ?>
</head>
<body>
    <div class="auth-wrap">
        <div class="auth-panel">
            <div class="auth-panel__inner">
                <h1 class="auth-panel__heading">Signing out...</h1>
                <p class="auth-panel__sub">You are being signed out of your library account.</p>
                
                <?php if (empty($_SESSION['user_id'])): ?>
                    <div id="logout-success" data-message="You have been successfully signed out." style="display: none;"></div>
                <?php endif; ?>
                
                <p style="margin-top:1.5rem; text-align:center; font-size:.875rem; color:var(--muted);">
                    <a href="<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>"
                       style="color:var(--ink); font-weight:500; text-decoration:underline;">Go to sign in</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        // Display SweetAlert2 for logout feedback
        document.addEventListener('DOMContentLoaded', function() {
            'use strict';
            
            // Wait for SweetAlert2 to be available
            if (typeof Swal === 'undefined' || typeof sweetAlertUtils === 'undefined') {
                // Fallback to redirect if Swal is not available
                window.location.href = '<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>';
                return;
            }
            
            const successNotice = document.getElementById('logout-success');
            
            if (successNotice) {
                const message = successNotice.getAttribute('data-message');
                if (message) {
                    // Use setTimeout to ensure page is fully loaded
                    setTimeout(async function() {
                        await sweetAlertUtils.showSuccess('Signed Out', message, 2000);
                        // Redirect after SweetAlert closes
                        setTimeout(function() {
                            window.location.href = '<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>';
                        }, 2200);
                    }, 300);
                }
            } else {
                // No success notice found, just redirect
                window.location.href = '<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>';
            }
        });
    </script>
</body>
</html>
