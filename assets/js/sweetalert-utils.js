/**
 * sweetalert-utils.js — SweetAlert2 Utility Wrapper Functions
 * 
 * Custom theme and reusable functions for consistent alerts across the library system
 * Matches editorial aesthetic: warm palette, editorial theme, modern clarity
 * 
 * Design tokens used:
 * - Primary: gold (#c9a84c), sage (#4a6741), accent (#c8401a)
 * - Neutral: ink (#0f0e0c), paper (#f7f4ee), cream (#ede9e0)
 */

// ============================================================================
// CUSTOM THEME CONFIGURATION
// ============================================================================

const SWAL_THEME = {
  background: '#f7f4ee',      // paper
  color: '#0f0e0c',           // ink
  confirmButtonColor: '#c9a84c', // gold
  denyButtonColor: '#c8401a',    // accent
  cancelButtonColor: '#8a8278',  // muted
};

// ============================================================================
// UTILITY FUNCTIONS FOR COMMON PATTERNS
// ============================================================================

/**
 * Confirmation dialog for destructive actions (delete, suspend, etc.)
 * @param {string} title - Alert title
 * @param {string} message - Alert message/description
 * @param {string} confirmText - Confirm button text (default: "Delete")
 * @param {string} cancelText - Cancel button text (default: "Cancel")
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmDestructive(title, message, confirmText = 'Delete', cancelText = 'Cancel') {
  return Swal.fire({
    title,
    html: message,
    icon: 'warning',
    iconColor: SWAL_THEME.denyButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: confirmText,
    confirmButtonColor: SWAL_THEME.denyButtonColor,
    cancelButtonText: cancelText,
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: true,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel',
      denyButton: 'swal-btn-deny'
    }
  });
}

/**
 * Safe confirmation dialog (non-destructive, standard action)
 * @param {string} title - Alert title
 * @param {string} message - Alert message/description
 * @param {string} confirmText - Confirm button text (default: "Confirm")
 * @param {string} cancelText - Cancel button text (default: "Cancel")
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmAction(title, message, confirmText = 'Confirm', cancelText = 'Cancel') {
  return Swal.fire({
    title,
    html: message,
    icon: 'question',
    iconColor: SWAL_THEME.confirmButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: confirmText,
    confirmButtonColor: SWAL_THEME.confirmButtonColor,
    cancelButtonText: cancelText,
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: false,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel'
    }
  });
}

/**
 * Success notification
 * @param {string} title - Alert title
 * @param {string} message - Alert message (optional)
 * @param {number} timer - Auto-close timer in ms (default: 2000)
 * @returns {Promise} - resolves when alert closes
 */
async function showSuccess(title, message = '', timer = 2000) {
  return Swal.fire({
    title,
    html: message,
    icon: 'success',
    iconColor: SWAL_THEME.confirmButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonColor: SWAL_THEME.confirmButtonColor,
    timer,
    timerProgressBar: true,
    showConfirmButton: false,
    allowOutsideClick: true,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      timerProgressBar: 'swal-progress-bar'
    }
  });
}

/**
 * Error notification
 * @param {string} title - Alert title
 * @param {string} message - Error message (optional)
 * @param {string} confirmText - Button text (default: "OK")
 * @returns {Promise} - resolves when alert closes
 */
async function showError(title, message = '', confirmText = 'OK') {
  return Swal.fire({
    title,
    html: message,
    icon: 'error',
    iconColor: SWAL_THEME.denyButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: confirmText,
    confirmButtonColor: SWAL_THEME.denyButtonColor,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm'
    }
  });
}

/**
 * Information notification
 * @param {string} title - Alert title
 * @param {string} message - Info message (optional)
 * @param {string} confirmText - Button text (default: "OK")
 * @returns {Promise} - resolves when alert closes
 */
async function showInfo(title, message = '', confirmText = 'OK') {
  return Swal.fire({
    title,
    html: message,
    icon: 'info',
    iconColor: SWAL_THEME.confirmButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: confirmText,
    confirmButtonColor: SWAL_THEME.confirmButtonColor,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm'
    }
  });
}

/**
 * Loading state alert (shows spinner, no buttons)
 * @param {string} message - Loading message (default: "Loading...")
 * @returns {Promise} - resolves when Swal.hideLoading() is called
 */
async function showLoading(message = 'Loading...') {
  return Swal.fire({
    title: message,
    icon: 'info',
    iconColor: SWAL_THEME.confirmButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: async (modal) => {
      Swal.showLoading();
    },
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title'
    }
  });
}

/**
 * Two-step action with loading
 * Shows confirmation dialog, then loading state while awaiting promise
 * @param {string} title - Confirmation title
 * @param {string} message - Confirmation message
 * @param {Promise} asyncAction - Promise to execute on confirm
 * @param {string} loadingMessage - Message during loading (default: "Processing...")
 * @param {string} confirmText - Confirm button text (default: "Confirm")
 * @returns {Promise} - resolves with result from asyncAction or false if cancelled
 */
async function confirmAndWait(title, message, asyncAction, loadingMessage = 'Processing...', confirmText = 'Confirm') {
  const result = await confirmAction(title, message, confirmText, 'Cancel');
  
  if (result.isConfirmed) {
    await showLoading(loadingMessage);
    try {
      const actionResult = await asyncAction();
      Swal.hideLoading();
      return actionResult;
    } catch (error) {
      Swal.hideLoading();
      await showError('Error', error.message || 'An error occurred. Please try again.');
      return false;
    }
  }
  
  return false;
}

/**
 * Logout confirmation
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmLogout() {
  return Swal.fire({
    title: 'Sign Out?',
    html: '<p>You will be logged out of your library account.</p>',
    icon: 'question',
    iconColor: SWAL_THEME.confirmButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: 'Sign Out',
    confirmButtonColor: SWAL_THEME.confirmButtonColor,
    cancelButtonText: 'Stay Logged In',
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: false,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel'
    }
  });
}

/**
 * Delete user confirmation (with role context)
 * @param {string} userName - User's name
 * @param {string} userRole - User's role
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmDeleteUser(userName, userRole) {
  return Swal.fire({
    title: 'Delete User?',
    html: `
      <p><strong>${escapeHtml(userName)}</strong></p>
      <p style="font-size: 0.875rem; color: #8a8278; margin: 8px 0 0 0;">
        Role: <strong>${escapeHtml(userRole)}</strong>
      </p>
      <p style="margin-top: 16px; font-size: 0.875rem;">
        This action cannot be undone. All user data will be permanently deleted.
      </p>
    `,
    icon: 'warning',
    iconColor: SWAL_THEME.denyButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: 'Delete User',
    confirmButtonColor: SWAL_THEME.denyButtonColor,
    cancelButtonText: 'Cancel',
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: true,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel'
    }
  });
}

/**
 * Suspend user confirmation
 * @param {string} userName - User's name
 * @param {string} reason - Reason for suspension (optional)
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmSuspendUser(userName, reason = '') {
  let reasonHtml = '';
  if (reason) {
    reasonHtml = `<p style="font-size: 0.875rem; color: #8a8278; margin: 8px 0 0 0;">
      Reason: <strong>${escapeHtml(reason)}</strong>
    </p>`;
  }
  
  return Swal.fire({
    title: 'Suspend Account?',
    html: `
      <p><strong>${escapeHtml(userName)}</strong></p>
      ${reasonHtml}
      <p style="margin-top: 16px; font-size: 0.875rem;">
        The user will no longer be able to access their account until unsuspended.
      </p>
    `,
    icon: 'warning',
    iconColor: SWAL_THEME.denyButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: 'Suspend Account',
    confirmButtonColor: SWAL_THEME.denyButtonColor,
    cancelButtonText: 'Cancel',
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: true,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel'
    }
  });
}

/**
 * Change user role confirmation
 * @param {string} userName - User's name
 * @param {string} oldRole - Current role
 * @param {string} newRole - New role
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmChangeRole(userName, oldRole, newRole) {
  return Swal.fire({
    title: 'Change User Role?',
    html: `
      <p><strong>${escapeHtml(userName)}</strong></p>
      <p style="font-size: 0.875rem; color: #8a8278; margin: 8px 0 0 0;">
        <strong>${escapeHtml(oldRole)}</strong> → <strong>${escapeHtml(newRole)}</strong>
      </p>
      <p style="margin-top: 16px; font-size: 0.875rem;">
        The user's permissions and access level will change immediately.
      </p>
    `,
    icon: 'question',
    iconColor: SWAL_THEME.confirmButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: 'Change Role',
    confirmButtonColor: SWAL_THEME.confirmButtonColor,
    cancelButtonText: 'Cancel',
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: false,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel'
    }
  });
}

/**
 * Resend verification email confirmation
 * @param {string} userEmail - User's email
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmResendEmail(userEmail) {
  return Swal.fire({
    title: 'Resend Verification?',
    html: `
      <p>A new verification email will be sent to:</p>
      <p style="font-weight: 600; color: #c9a84c; word-break: break-all;">${escapeHtml(userEmail)}</p>
    `,
    icon: 'info',
    iconColor: SWAL_THEME.confirmButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: 'Send Email',
    confirmButtonColor: SWAL_THEME.confirmButtonColor,
    cancelButtonText: 'Cancel',
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: false,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel'
    }
  });
}

/**
 * Bulk action confirmation (delete multiple users)
 * @param {number} count - Number of users to delete
 * @returns {Promise} - resolves with {isConfirmed: boolean}
 */
async function confirmBulkDelete(count) {
  return Swal.fire({
    title: `Delete ${count} User${count !== 1 ? 's' : ''}?`,
    html: `
      <p>This action cannot be undone.</p>
      <p style="font-size: 0.875rem; color: #8a8278; margin-top: 12px;">
        All selected users and their data will be permanently deleted.
      </p>
    `,
    icon: 'warning',
    iconColor: SWAL_THEME.denyButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: 'Delete All',
    confirmButtonColor: SWAL_THEME.denyButtonColor,
    cancelButtonText: 'Cancel',
    cancelButtonColor: SWAL_THEME.cancelButtonColor,
    showCancelButton: true,
    focusConfirm: true,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel'
    }
  });
}

/**
 * Login error notification (with helpful context)
 * @param {string} error - Error message
 * @param {string} context - Additional context (e.g., "unverified", "suspended")
 * @param {string} email - User email to resend verification (optional)
 * @param {string} csrfToken - CSRF token for resend form (optional)
 * @param {string} baseUrl - Base URL for links (optional)
 * @returns {Promise} - resolves when alert closes
 */
async function showLoginError(error, context = '', email = '', csrfToken = '', baseUrl = '') {
  let helpText = '';
  
  if (context === 'unverified') {
    const safeEmail = email ? escapeHtml(email) : '';
    const safeBase = baseUrl && typeof baseUrl === 'string' ? baseUrl.replace(/\/+$/, '') + '/' : '/';
    const verifyLink = safeEmail ? `${safeBase}verify.php?email=${encodeURIComponent(safeEmail)}` : `${safeBase}verify.php`;
    const resendForm = safeEmail && csrfToken
      ? `
        <form method="post" action="${safeBase}verify.php" style="margin-top: 12px;">
          <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
          <input type="hidden" name="intent" value="resend">
          <input type="hidden" name="email" value="${safeEmail}">
          <button type="submit" class="swal-btn-confirm" style="background:#c9a84c;color:#0f0e0c;border:none;border-radius:10px;padding:8px 14px;font-weight:600;cursor:pointer;">Resend verification email</button>
        </form>
      `
      : '';
    helpText = `
      <p style="margin-top: 12px; font-size: 0.875rem; color: #8a8278;">Check your email for a verification link or code.</p>
      ${resendForm}
      <p style="margin-top: 10px; font-size: 0.875rem; color: #8a8278;">
        <a href="${verifyLink}" style="color: #c9a84c; font-weight: 600;">Open verification page</a>
      </p>
    `;
  } else if (context === 'suspended') {
    helpText = '<p style="margin-top: 12px; font-size: 0.875rem; color: #8a8278;">Please contact the library for assistance.</p>';
  } else {
    helpText = '<p style="margin-top: 12px; font-size: 0.875rem; color: #8a8278;"><a href="/forgot-password.php" style="color: #c9a84c; font-weight: 600;">Forgot your password?</a></p>';
  }
  
  return Swal.fire({
    title: 'Sign In Failed',
    html: `<p>${escapeHtml(error)}</p>${helpText}`,
    icon: 'error',
    iconColor: SWAL_THEME.denyButtonColor,
    background: SWAL_THEME.background,
    color: SWAL_THEME.color,
    confirmButtonText: 'Try Again',
    confirmButtonColor: SWAL_THEME.denyButtonColor,
    allowOutsideClick: false,
    allowEscapeKey: true,
    customClass: {
      container: 'swal-container',
      popup: 'swal-popup',
      title: 'swal-title',
      htmlContainer: 'swal-html',
      confirmButton: 'swal-btn-confirm'
    }
  });
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Escape HTML special characters to prevent XSS
 * @param {string} text - Text to escape
 * @returns {string} - Escaped text
 */
function escapeHtml(text) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return text.replace(/[&<>"']/g, (char) => map[char]);
}

// Make functions available globally
if (typeof window !== 'undefined') {
  window.sweetAlertUtils = {
    confirmDestructive,
    confirmAction,
    showSuccess,
    showError,
    showInfo,
    showLoading,
    confirmAndWait,
    confirmLogout,
    confirmDeleteUser,
    confirmSuspendUser,
    confirmChangeRole,
    confirmResendEmail,
    confirmBulkDelete,
    showLoginError
  };
}
