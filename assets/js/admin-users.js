/**
 * admin-users.js — User Management Page Interactions
 *
 * Handles:
 * - Bulk selection toolbar with eligibility-aware state
 * - Slide-out manage panel with focus trapping
 * - Create-user modal with client validation
 * - Inline action forms (deactivate/reactivate, credentials, verification, role, password, delete)
 * - Keyboard shortcuts (Cmd+K/Ctrl+K search, Cmd+N/Ctrl+N new user, / focus search)
 * - Flash message display via sweetAlertUtils
 * - Focused-row highlight on POST return
 * - Onboarding banner (localStorage dismissed state)
 * - Search auto-submit with debounce
 * - Per-page selector in pagination
 */

(function () {
  'use strict';

  const liveAnnouncer = document.getElementById('users-live-announcer');
  const searchInput = document.getElementById('users-search-input');
  const searchForm = searchInput ? searchInput.closest('form') : null;
  const createUserTrigger = document.getElementById('create-user-trigger');
  const createUserEmptyState = document.getElementById('create-user-empty-state');
  const perPageSelect = document.getElementById('users-per-page-select');

  const bulkForm = document.getElementById('users-bulk-form');
  const selectAll = document.getElementById('users-select-all');
  const rowChecks = Array.from(document.querySelectorAll('.users-select-row'));
  const selectedCount = document.getElementById('users-selected-count');
  const bulkStatus = document.getElementById('users-bulk-status');
  const bulkToolbar = document.getElementById('users-bulk-toolbar');
  const bulkAction = document.getElementById('bulk_action');
  const bulkApply = document.getElementById('apply-bulk-action');
  const bulkSelectedContainer = document.getElementById('users-bulk-selected-container');

  const managePanel = document.getElementById('users-manage-panel');
  const manageOverlay = document.getElementById('users-manage-overlay');
  const manageCloseBtn = document.getElementById('users-manage-close');
  const manageTriggers = Array.from(document.querySelectorAll('.users-manage-trigger'));
  const manageName = document.getElementById('users-manage-name');
  const manageEmail = document.getElementById('users-manage-email');
  const manageRoleChip = document.getElementById('users-manage-role-chip');
  const manageVerifiedChip = document.getElementById('users-manage-verified-chip');
  const manageStatusChip = document.getElementById('users-manage-status-chip');
  const manageJoined = document.getElementById('users-manage-joined');

  const credentialsForm = document.getElementById('users-manage-credentials-form');
  const verificationForm = document.getElementById('users-manage-verification-form');
  const roleForm = document.getElementById('users-manage-role-form');
  const passwordForm = document.getElementById('users-manage-password-form');
  const deleteForm = document.getElementById('users-manage-delete-form');
  const activateForm = document.getElementById('users-manage-activate-form');
  const deactivateForm = document.getElementById('users-manage-deactivate-form');
  const roleHelp = document.getElementById('users-manage-role-help');
  const verificationHelp = document.getElementById('users-manage-verification-help');
  const roleSelect = document.getElementById('users-manage-role');
  const verificationSelect = document.getElementById('users-manage-verification');

  const createModalOverlay = document.getElementById('create-user-modal-overlay');
  const createModal = document.getElementById('create-user-modal');
  const createModalClose = document.getElementById('create-user-modal-close');
  const createModalCancel = document.getElementById('modal-cancel-btn');
  const createForm = document.getElementById('create-user-form');
  const createSubmitBtn = document.getElementById('modal-submit-btn');
  const createFormError = document.getElementById('modal-form-error');
  const createRoleSelect = document.getElementById('modal-role');
  const createVerifiedCheckbox = document.getElementById('modal-is_verified');

  const ONBOARDING_KEY = 'libris_users_onboarding_dismissed';
  const onboardingBanner = document.getElementById('users-onboarding-banner');
  const onboardingDismiss = document.getElementById('dismiss-onboarding');

  const panelState = {
    isOpen: false,
    row: null,
    trigger: null,
  };

  let hasInitializedBulkState = false;
  let scrollLocks = 0;
  let searchDebounceTimer = null;
  const SEARCH_DEBOUNCE_MS = 300;

  function getSweetAlertUtils() {
    return (typeof window !== 'undefined' && window.sweetAlertUtils) ? window.sweetAlertUtils : null;
  }

  function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, function (char) {
      var map = { '&': '&', '<': '<', '>': '>', '"': '"', "'": '&#039;' };
      return map[char] || char;
    });
  }

  function announce(message) {
    if (!liveAnnouncer || !message) {
      return;
    }
    liveAnnouncer.textContent = '';
    window.setTimeout(function () {
      liveAnnouncer.textContent = message;
    }, 40);
  }

  function lockBodyScroll() {
    scrollLocks += 1;
    document.body.style.overflow = 'hidden';
  }

  function unlockBodyScroll() {
    scrollLocks = Math.max(0, scrollLocks - 1);
    if (scrollLocks === 0) {
      document.body.style.overflow = '';
    }
  }

  function setSubmitPending(form, pendingText) {
    if (!form) {
      return;
    }
    var submit = form.querySelector('button[type="submit"]');
    if (!submit) {
      return;
    }
    submit.disabled = true;
    if (!submit.dataset.originalText) {
      submit.dataset.originalText = submit.textContent;
    }
    submit.textContent = pendingText || 'Working...';
  }

  function resetSubmitState(form) {
    if (!form) {
      return;
    }
    var submit = form.querySelector('button[type="submit"]');
    if (!submit) {
      return;
    }
    submit.disabled = false;
    if (submit.dataset.originalText) {
      submit.textContent = submit.dataset.originalText;
    }
  }

  function roleRequiresVerification(role) {
    return role === 'admin' || role === 'librarian';
  }

  function roleDisplayLabel(role) {
    if (role === 'admin') {
      return 'Admin';
    }
    if (role === 'librarian') {
      return 'Librarian';
    }
    return 'Borrower';
  }

  function isRoleAction(actionValue) {
    return actionValue === 'role_admin' || actionValue === 'role_librarian' || actionValue === 'role_borrower';
  }

  function selectedRoleFromAction(actionValue) {
    if (actionValue === 'role_admin') {
      return 'admin';
    }
    if (actionValue === 'role_librarian') {
      return 'librarian';
    }
    if (actionValue === 'role_borrower') {
      return 'borrower';
    }
    return '';
  }

  function formatBulkActionLabel(actionValue, selected) {
    if (actionValue === 'activate') {
      return 'Activate ' + selected + ' user' + (selected === 1 ? '' : 's');
    }
    if (actionValue === 'deactivate') {
      return 'Deactivate ' + selected + ' user' + (selected === 1 ? '' : 's');
    }
    if (actionValue === 'role_borrower') {
      return 'Set ' + selected + ' user' + (selected === 1 ? '' : 's') + ' as Borrower';
    }
    if (actionValue === 'role_librarian') {
      return 'Set ' + selected + ' user' + (selected === 1 ? '' : 's') + ' as Librarian';
    }
    if (actionValue === 'role_admin') {
      return 'Set ' + selected + ' user' + (selected === 1 ? '' : 's') + ' as Admin';
    }
    return 'Apply action';
  }

  function resolveSelectedUsers(roleValue) {
    return rowChecks
      .filter(function (box) {
        return box.checked;
      })
      .map(function (box) {
        return {
          id: box.value,
          verified: box.getAttribute('data-verified') === '1',
          element: box,
        };
      })
      .filter(function (user) {
        if (!roleValue || !roleRequiresVerification(roleValue)) {
          return true;
        }
        return user.verified;
      });
  }

  function updateBulkState() {
    if (!selectedCount || !bulkAction || !bulkApply) {
      return;
    }

    var selected = rowChecks.filter(function (box) { return box.checked; }).length;
    var hasAction = bulkAction.value !== '';
    var targetRole = selectedRoleFromAction(bulkAction.value);
    var isRoleBulk = isRoleAction(bulkAction.value);
    var eligibleCount = isRoleBulk ? resolveSelectedUsers(targetRole).length : selected;
    var blockedCount = isRoleBulk ? Math.max(0, selected - eligibleCount) : 0;
    var canSubmit = selected > 0 && hasAction && (!isRoleBulk || eligibleCount > 0);

    selectedCount.textContent = selected === 0
      ? 'No users selected'
      : selected + ' user' + (selected === 1 ? '' : 's') + ' selected';

    bulkApply.disabled = !canSubmit;
    bulkApply.textContent = formatBulkActionLabel(bulkAction.value, isRoleBulk ? eligibleCount : selected);

    rowChecks.forEach(function (box) {
      var row = box.closest('tr.users-row');
      if (!row) {
        return;
      }
      var isIneligible = isRoleBulk && box.checked && box.getAttribute('data-verified') !== '1';
      row.classList.toggle('users-row--selected', box.checked);
      row.classList.toggle('users-row--ineligible', isIneligible);
      row.setAttribute('aria-selected', box.checked ? 'true' : 'false');
    });

    if (bulkToolbar) {
      bulkToolbar.classList.toggle('users-bulk-toolbar--has-selection', selected > 0);
    }

    if (bulkStatus) {
      if (selected === 0) {
        bulkStatus.textContent = 'Select users to begin.';
      } else if (!hasAction) {
        bulkStatus.textContent = selected + ' user' + (selected === 1 ? '' : 's') + ' selected. Choose an action.';
      } else if (isRoleBulk && blockedCount > 0) {
        bulkStatus.textContent = eligibleCount + ' eligible, ' + blockedCount + ' not verified and will be skipped.';
      } else {
        bulkStatus.textContent = 'Ready: ' + formatBulkActionLabel(bulkAction.value, isRoleBulk ? eligibleCount : selected) + '.';
      }
    }

    if (selectAll) {
      var total = rowChecks.length;
      selectAll.checked = total > 0 && selected === total;
      selectAll.indeterminate = selected > 0 && selected < total;
    }

    if (hasInitializedBulkState) {
      announce(selected + ' users selected.');
    }
    hasInitializedBulkState = true;
  }

  function getFocusable(container) {
    if (!container) {
      return [];
    }
    return Array.from(
      container.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter(function (el) {
      return !el.hasAttribute('hidden') && el.getAttribute('aria-hidden') !== 'true';
    });
  }

  function trapFocus(e, container) {
    if (e.key !== 'Tab') {
      return;
    }
    var focusables = getFocusable(container);
    if (focusables.length === 0) {
      e.preventDefault();
      return;
    }

    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    var active = document.activeElement;

    if (e.shiftKey && active === first) {
      e.preventDefault();
      last.focus();
      return;
    }

    if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function readRowData(row) {
    return {
      id: row.getAttribute('data-user-id') || '',
      name: row.getAttribute('data-user-name') || '',
      email: row.getAttribute('data-user-email') || '',
      role: row.getAttribute('data-user-role') || 'borrower',
      roleLabel: row.getAttribute('data-user-role-label') || roleDisplayLabel(row.getAttribute('data-user-role') || 'borrower'),
      roleDescription: row.getAttribute('data-user-role-description') || '',
      roleRequiresVerified: row.getAttribute('data-user-role-requires-verified') === '1',
      verified: row.getAttribute('data-user-verified') === '1',
      suspended: row.getAttribute('data-user-suspended') === '1',
      isProtected: row.getAttribute('data-user-protected') === '1',
      createdText: row.getAttribute('data-user-created') || 'Unknown date',
    };
  }

  function updateManageRoleHelp() {
    if (!roleForm || !roleSelect || !roleHelp) {
      return;
    }
    var selectedOption = roleSelect.options[roleSelect.selectedIndex];
    var roleDescription = selectedOption ? (selectedOption.getAttribute('data-role-description') || '') : '';
    var roleLabel = selectedOption ? (selectedOption.getAttribute('data-role-label') || roleSelect.value) : roleSelect.value;
    var requiresVerified = selectedOption ? selectedOption.getAttribute('data-requires-verified') === '1' : false;
    var currentVerified = roleForm.getAttribute('data-current-verified') === '1';

    var text = roleDescription;
    if (requiresVerified) {
      text += (text ? ' ' : '') + (currentVerified ? 'Verification is required for this role.' : roleLabel + ' requires verification first.');
    }
    roleHelp.textContent = text || 'Role permissions update immediately after saving.';
  }

  function updateManageRoleOptionPolicy() {
    if (!roleForm || !roleSelect) {
      return;
    }
    var currentVerified = roleForm.getAttribute('data-current-verified') === '1';
    var currentRole = roleForm.getAttribute('data-current-role') || '';

    Array.from(roleSelect.options).forEach(function (option) {
      var requiresVerified = option.getAttribute('data-requires-verified') === '1';
      option.disabled = requiresVerified && !currentVerified && option.value !== currentRole;
    });
  }

  function updateManageVerificationState() {
    if (!verificationForm || !verificationSelect || !verificationHelp) {
      return;
    }
    var currentRole = verificationForm.getAttribute('data-role') || (roleForm ? (roleForm.getAttribute('data-current-role') || 'borrower') : 'borrower');
    var roleLabel = verificationForm.getAttribute('data-role-label') || roleDisplayLabel(currentRole);
    var requiresVerified = verificationForm.getAttribute('data-role-requires-verified') === '1' || roleRequiresVerification(currentRole);
    var notVerifiedOption = verificationSelect.querySelector('option[value="0"]');
    if (notVerifiedOption) {
      notVerifiedOption.disabled = requiresVerified;
      if (requiresVerified && verificationSelect.value === '0') {
        verificationSelect.value = '1';
      }
    }
    verificationHelp.textContent = requiresVerified
      ? roleLabel + ' accounts must remain verified.'
      : 'Borrower accounts may be verified later.';
  }

  function hydrateManagePanel(row) {
    if (!row || !credentialsForm || !verificationForm || !roleForm || !passwordForm || !deleteForm || !activateForm || !deactivateForm) {
      return;
    }

    var data = readRowData(row);

    manageName.textContent = data.name || 'User account';
    manageEmail.textContent = data.email || '';
    manageRoleChip.textContent = data.roleLabel;
    manageJoined.textContent = 'Joined ' + data.createdText;

    manageRoleChip.className = 'badge';
    if (data.role === 'admin') {
      manageRoleChip.classList.add('badge-blue');
    } else if (data.role === 'librarian') {
      manageRoleChip.classList.add('badge-amber');
    }

    manageVerifiedChip.className = 'badge users-state';
    manageVerifiedChip.textContent = data.verified ? 'Verified' : 'Not verified';
    manageVerifiedChip.classList.add(data.verified ? 'badge-green' : 'badge-red', data.verified ? 'users-state--ok' : 'users-state--warn');

    manageStatusChip.className = 'badge users-state';
    manageStatusChip.textContent = data.suspended ? 'Deactivated' : 'Active';
    manageStatusChip.classList.add(data.suspended ? 'badge-red' : 'badge-green', data.suspended ? 'users-state--warn' : 'users-state--ok');

    credentialsForm.querySelector('input[name="user_id"]').value = data.id;
    credentialsForm.querySelector('input[name="full_name"]').value = data.name;
    credentialsForm.querySelector('input[name="email"]').value = data.email;
    credentialsForm.setAttribute('data-user-name', data.name);
    credentialsForm.setAttribute('data-current-name', data.name);
    credentialsForm.setAttribute('data-current-email', data.email);

    verificationForm.querySelector('input[name="user_id"]').value = data.id;
    verificationSelect.value = data.verified ? '1' : '0';
    verificationForm.setAttribute('data-user-name', data.name);
    verificationForm.setAttribute('data-role', data.role);
    verificationForm.setAttribute('data-role-label', data.roleLabel);
    verificationForm.setAttribute('data-role-requires-verified', data.roleRequiresVerified ? '1' : '0');
    verificationForm.setAttribute('data-current-verified', data.verified ? '1' : '0');

    roleForm.querySelector('input[name="user_id"]').value = data.id;
    roleSelect.value = data.role;
    roleForm.setAttribute('data-user-name', data.name);
    roleForm.setAttribute('data-current-role', data.role);
    roleForm.setAttribute('data-current-verified', data.verified ? '1' : '0');

    passwordForm.querySelector('input[name="user_id"]').value = data.id;
    passwordForm.querySelector('input[name="new_password"]').value = '';
    passwordForm.querySelector('input[name="confirm_password"]').value = '';
    passwordForm.setAttribute('data-user-name', data.name);

    deleteForm.querySelector('input[name="user_id"]').value = data.id;
    var deleteConfirm = deleteForm.querySelector('input[name="delete_confirm"]');
    if (deleteConfirm) {
      deleteConfirm.checked = false;
    }
    deleteForm.setAttribute('data-user-name', data.name);
    deleteForm.setAttribute('data-user-role', data.roleLabel);

    activateForm.querySelector('input[name="user_id"]').value = data.id;
    activateForm.querySelector('input[name="user_name"]').value = data.name;
    deactivateForm.querySelector('input[name="user_id"]').value = data.id;
    deactivateForm.querySelector('input[name="user_name"]').value = data.name;

    activateForm.hidden = !data.suspended;
    deactivateForm.hidden = data.suspended;

    var controls = managePanel.querySelectorAll('input, select, button');
    controls.forEach(function (control) {
      if (control === manageCloseBtn) {
        return;
      }
      if (control.closest('.users-manage-focus-guard')) {
        return;
      }
      control.disabled = data.isProtected;
    });
    managePanel.classList.toggle('users-manage-panel--protected', data.isProtected);

    updateManageRoleOptionPolicy();
    updateManageVerificationState();
    updateManageRoleHelp();
  }

  function openManagePanel(row, trigger) {
    if (!managePanel || !manageOverlay || !row) {
      return;
    }

    if (panelState.isOpen && panelState.row === row) {
      return;
    }

    if (panelState.isOpen) {
      panelState.row = row;
      panelState.trigger = trigger || panelState.trigger;
      hydrateManagePanel(row);
      var first = getFocusable(managePanel)[0];
      if (first) {
        first.focus();
      }
      return;
    }

    if (createModalOverlay && createModalOverlay.classList.contains('active')) {
      closeCreateModal();
    }

    var data = readRowData(row);
    if (data.isProtected) {
      announce('Protected account cannot be modified from this panel.');
      return;
    }

    panelState.row = row;
    panelState.trigger = trigger || null;
    panelState.isOpen = true;

    hydrateManagePanel(row);

    manageOverlay.hidden = false;
    managePanel.hidden = false;
    managePanel.setAttribute('aria-hidden', 'false');
    managePanel.classList.add('is-open');
    lockBodyScroll();

    window.setTimeout(function () {
      var focusableItems = getFocusable(managePanel);
      var firstFocusable = focusableItems.find(function (el) {
        return el.id === 'users-manage-full-name' || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'BUTTON';
      });
      if (firstFocusable) {
        firstFocusable.focus();
      }
    }, 60);
  }

  function closeManagePanel(restoreFocus) {
    if (!managePanel || !manageOverlay || !panelState.isOpen) {
      return;
    }

    panelState.isOpen = false;
    managePanel.classList.remove('is-open');
    managePanel.setAttribute('aria-hidden', 'true');
    managePanel.hidden = true;
    manageOverlay.hidden = true;
    unlockBodyScroll();

    if (restoreFocus !== false && panelState.trigger && typeof panelState.trigger.focus === 'function') {
      panelState.trigger.focus();
    }
  }

  function maybeShowInfo(title, message) {
    var swal = getSweetAlertUtils();
    if (swal) {
      return swal.showInfo(title, message);
    }
    window.alert(message);
    return Promise.resolve();
  }

  function maybeShowError(title, message) {
    var swal = getSweetAlertUtils();
    if (swal) {
      return swal.showError(title, message);
    }
    window.alert(message);
    return Promise.resolve();
  }

  function maybeConfirmAction(title, html, confirmText, cancelText) {
    var swal = getSweetAlertUtils();
    if (swal) {
      return swal.confirmAction(title, html, confirmText, cancelText);
    }
    return Promise.resolve({ isConfirmed: window.confirm(title + ': ' + html.replace(/<[^>]+>/g, ' ')) });
  }

  function maybeConfirmDelete(userName, userRole) {
    var swal = getSweetAlertUtils();
    if (swal) {
      return swal.confirmDeleteUser(userName, userRole);
    }
    return Promise.resolve({ isConfirmed: window.confirm('Delete "' + userName + '" permanently?') });
  }

  function maybeConfirmQuickAction(action, userName) {
    var swal = getSweetAlertUtils();
    if (swal) {
      if (action === 'deactivate') {
        return swal.confirmSuspendUser(userName, 'Account will be deactivated');
      }
      return swal.confirmAction(
        'Reactivate Account?',
        '<p>This account will be reactivated and the user can log in again.</p><p><strong>' + escapeHtml(userName) + '</strong></p>',
        'Reactivate',
        'Cancel'
      );
    }
    var fallback = action === 'deactivate'
      ? 'Deactivate "' + userName + '" now? They will immediately lose sign-in access.'
      : 'Reactivate "' + userName + '" now?';
    return Promise.resolve({ isConfirmed: window.confirm(fallback) });
  }

  async function handleQuickFormSubmit(e) {
    var form = e.currentTarget;
    var action = form.getAttribute('data-quick-action') || '';
    var userNameInput = form.querySelector('input[name="user_name"]');
    var userName = userNameInput ? userNameInput.value : 'this account';

    e.preventDefault();
    var result = await maybeConfirmQuickAction(action, userName);
    if (result && result.isConfirmed) {
      setSubmitPending(form, action === 'deactivate' ? 'Deactivating...' : 'Reactivating...');
      form.submit();
      return;
    }
    resetSubmitState(form);
  }

  async function handleCredentialsSubmit(e) {
    var form = e.currentTarget;
    var userName = form.getAttribute('data-user-name') || 'this user';
    var currentName = form.getAttribute('data-current-name') || '';
    var currentEmail = (form.getAttribute('data-current-email') || '').toLowerCase();
    var fullNameInput = form.querySelector('input[name="full_name"]');
    var emailInput = form.querySelector('input[name="email"]');
    var nextName = fullNameInput ? fullNameInput.value.trim() : '';
    var nextEmail = emailInput ? emailInput.value.trim().toLowerCase() : '';

    e.preventDefault();

    if (!nextName || !nextEmail) {
      await maybeShowError('Missing Required Fields', 'Full name and email are required.');
      resetSubmitState(form);
      return;
    }

    if (nextName === currentName && nextEmail === currentEmail) {
      await maybeShowInfo('No Changes', 'No credential changes were detected for this account.');
      resetSubmitState(form);
      return;
    }

    var result = await maybeConfirmAction(
      'Save Credential Changes?',
      '<p><strong>' + escapeHtml(userName) + '</strong></p><p style="margin-top: 10px; font-size: 0.9rem;">Updates to full name and email will take effect immediately.</p>',
      'Save Credentials',
      'Cancel'
    );

    if (result && result.isConfirmed) {
      setSubmitPending(form, 'Saving...');
      form.submit();
      return;
    }
    resetSubmitState(form);
  }

  async function handleVerificationSubmit(e) {
    var form = e.currentTarget;
    var userName = form.getAttribute('data-user-name') || 'this user';
    var roleLabel = form.getAttribute('data-role-label') || 'Current role';
    var roleRequiresVerified = form.getAttribute('data-role-requires-verified') === '1';
    var currentVerified = form.getAttribute('data-current-verified') === '1';
    var select = form.querySelector('select[name="is_verified"]');
    var nextVerified = select ? select.value === '1' : currentVerified;

    e.preventDefault();

    if (nextVerified === currentVerified) {
      await maybeShowInfo('No Change', 'Verification status is already up to date.');
      resetSubmitState(form);
      return;
    }

    if (!nextVerified && roleRequiresVerified) {
      await maybeShowError('Policy Restricted', roleLabel + ' accounts must remain verified. Change role first if needed.');
      resetSubmitState(form);
      return;
    }

    var result = await maybeConfirmAction(
      'Save Verification Status?',
      '<p><strong>' + escapeHtml(userName) + '</strong></p><p style="margin-top: 10px; font-size: 0.9rem;">The account will be marked as ' + (nextVerified ? '<strong>Verified</strong>' : '<strong>Not verified</strong>') + ' immediately.</p>',
      'Save Verification',
      'Cancel'
    );

    if (result && result.isConfirmed) {
      setSubmitPending(form, 'Saving...');
      form.submit();
      return;
    }
    resetSubmitState(form);
  }

  async function handleRoleSubmit(e) {
    var form = e.currentTarget;
    var userName = form.getAttribute('data-user-name') || 'this user';
    var currentRole = form.getAttribute('data-current-role') || '';
    var currentVerified = form.getAttribute('data-current-verified') === '1';
    var select = form.querySelector('select[name="new_role"]');
    var newRole = select ? select.value : currentRole;

    e.preventDefault();

    if (!select) {
      return;
    }

    if (newRole === currentRole) {
      await maybeShowInfo('No Change', 'The new role is the same as the current role.');
      resetSubmitState(form);
      return;
    }

    if (roleRequiresVerification(newRole) && !currentVerified) {
      await maybeShowError('Verification Required', 'Mark this account as verified before assigning Admin or Librarian access.');
      resetSubmitState(form);
      return;
    }

    var swal = getSweetAlertUtils();
    var result = swal
      ? await swal.confirmChangeRole(userName, currentRole, newRole)
      : await maybeConfirmAction('Change User Role', 'Change role for "' + escapeHtml(userName) + '" from ' + escapeHtml(currentRole) + ' to ' + escapeHtml(newRole) + '?', 'Change Role', 'Cancel');

    if (result && result.isConfirmed) {
      setSubmitPending(form, 'Saving...');
      form.submit();
      return;
    }
    resetSubmitState(form);
  }

  async function handlePasswordSubmit(e) {
    var form = e.currentTarget;
    var userName = form.getAttribute('data-user-name') || 'this user';
    var newPasswordInput = form.querySelector('input[name="new_password"]');
    var confirmPasswordInput = form.querySelector('input[name="confirm_password"]');
    var newPassword = newPasswordInput ? newPasswordInput.value : '';
    var confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';

    e.preventDefault();

    if (newPassword.length < 8) {
      await maybeShowError('Invalid Password', 'Password must be at least 8 characters.');
      resetSubmitState(form);
      return;
    }

    if (newPassword !== confirmPassword) {
      await maybeShowError('Password Mismatch', 'New password and confirmation must match.');
      resetSubmitState(form);
      return;
    }

    var result = await maybeConfirmAction(
      'Reset User Password?',
      '<p><strong>' + escapeHtml(userName) + '</strong></p><p style="margin-top: 10px; font-size: 0.9rem;">This will replace the user\'s current password immediately.</p>',
      'Update Password',
      'Cancel'
    );

    if (result && result.isConfirmed) {
      setSubmitPending(form, 'Updating...');
      form.submit();
      return;
    }
    resetSubmitState(form);
  }

  async function handleDeleteSubmit(e) {
    var form = e.currentTarget;
    var userName = form.getAttribute('data-user-name') || 'this user';
    var userRole = form.getAttribute('data-user-role') || 'User';
    var confirmCheckbox = form.querySelector('input[name="delete_confirm"]');

    e.preventDefault();
    var result = await maybeConfirmDelete(userName, userRole);
    if (result && result.isConfirmed) {
      if (confirmCheckbox) {
        confirmCheckbox.checked = true;
      }
      setSubmitPending(form, 'Deleting...');
      form.submit();
      return;
    }
    resetSubmitState(form);
  }

  function updateCreateRolePolicy() {
    if (!createRoleSelect || !createVerifiedCheckbox) {
      return;
    }
    var mustBeVerified = roleRequiresVerification(createRoleSelect.value);
    if (mustBeVerified) {
      createVerifiedCheckbox.checked = true;
      createVerifiedCheckbox.disabled = true;
      createVerifiedCheckbox.setAttribute('aria-disabled', 'true');
    } else {
      createVerifiedCheckbox.disabled = false;
      createVerifiedCheckbox.removeAttribute('aria-disabled');
    }
  }

  function clearCreateModalErrors() {
    document.querySelectorAll('#create-user-modal .field-error').forEach(function (el) {
      el.textContent = '';
    });
  }

  function validateCreateModal() {
    if (!createForm) {
      return false;
    }
    clearCreateModalErrors();

    var fullName = createForm.querySelector('input[name="full_name"]').value.trim();
    var email = createForm.querySelector('input[name="email"]').value.trim();
    var password = createForm.querySelector('input[name="password"]').value;
    var role = createForm.querySelector('select[name="role"]').value;
    var isValid = true;

    if (!fullName) {
      document.getElementById('full_name-error').textContent = 'Enter the person\'s full name.';
      isValid = false;
    }
    if (!email) {
      document.getElementById('email-error').textContent = 'Enter an email address.';
      isValid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      document.getElementById('email-error').textContent = 'Email must be valid (e.g., jane@example.com).';
      isValid = false;
    }
    if (!password) {
      document.getElementById('password-error').textContent = 'Enter a password.';
      isValid = false;
    } else if (password.length < 8) {
      document.getElementById('password-error').textContent = 'Password must be at least 8 characters.';
      isValid = false;
    }
    if (!role) {
      document.getElementById('role-error').textContent = 'Choose an account type.';
      isValid = false;
    }

    return isValid;
  }

  function openCreateModal() {
    if (!createModalOverlay || !createModal || !createForm) {
      return;
    }

    if (createModalOverlay.classList.contains('active')) {
      return;
    }

    closeManagePanel(false);
    createModalOverlay.classList.add('active');
    createModalOverlay.setAttribute('aria-hidden', 'false');
    lockBodyScroll();
    updateCreateRolePolicy();

    window.setTimeout(function () {
      var firstInput = createForm.querySelector('input[type="text"], input[type="email"]');
      if (firstInput) {
        firstInput.focus();
      }
    }, 80);
  }

  function closeCreateModal() {
    if (!createModalOverlay || !createForm) {
      return;
    }
    createModalOverlay.classList.remove('active');
    createModalOverlay.setAttribute('aria-hidden', 'true');
    unlockBodyScroll();
    clearCreateModalErrors();
    createForm.reset();
    updateCreateRolePolicy();
    if (createFormError) {
      createFormError.style.display = 'none';
    }
    if (createUserTrigger) {
      createUserTrigger.focus();
    }
  }

  function wireAutoDisableForms() {
    var managedSelectors = [
      '.users-quick-form',
      '.users-credentials-form',
      '.users-verification-form',
      '.users-role-form',
      '.users-password-reset-form',
      '.users-delete-form',
      '#users-bulk-form',
      '#create-user-form'
    ];

    var allForms = Array.from(document.querySelectorAll('form'));
    allForms.forEach(function (form) {
      var managed = managedSelectors.some(function (selector) {
        return form.matches(selector);
      });
      if (managed || form.classList.contains('users-nojs-only')) {
        return;
      }
      form.addEventListener('submit', function () {
        setSubmitPending(form, 'Working...');
      });
    });
  }

  function wireBulkForm() {
    if (!bulkForm) {
      return;
    }

    bulkForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      var selected = rowChecks.filter(function (box) { return box.checked; }).length;
      if (selected === 0) {
        await maybeShowError('No Users Selected', 'Select at least one user before applying a bulk action.');
        return;
      }
      if (!bulkAction || bulkAction.value === '') {
        await maybeShowError('No Action Selected', 'Choose a bulk action before applying.');
        return;
      }

      if (isRoleAction(bulkAction.value)) {
        var targetRole = selectedRoleFromAction(bulkAction.value);
        var eligibleUsers = resolveSelectedUsers(targetRole);
        if (eligibleUsers.length === 0) {
          await maybeShowError('No Eligible Users', 'No selected users are eligible for that role. Admin and Librarian roles require verified accounts.');
          resetSubmitState(bulkForm);
          return;
        }

        var blocked = selected - eligibleUsers.length;
        if (blocked > 0) {
          var blockedResult = await maybeConfirmAction(
            'Continue With Eligible Users?',
            '<p>' + blocked + ' selected user(s) are not verified and will be skipped.</p><p style="margin-top: 10px;">Continue with ' + eligibleUsers.length + ' eligible user(s)?</p>',
            'Continue',
            'Cancel'
          );
          if (!blockedResult || !blockedResult.isConfirmed) {
            resetSubmitState(bulkForm);
            return;
          }
        }
      }

      if (bulkSelectedContainer) {
        bulkSelectedContainer.innerHTML = '';
        var bulkTargetRole = selectedRoleFromAction(bulkAction.value);
        var eligibleIds = isRoleAction(bulkAction.value)
          ? new Set(resolveSelectedUsers(bulkTargetRole).map(function (user) { return user.id; }))
          : null;

        rowChecks.forEach(function (box) {
          if (!box.checked) {
            return;
          }
          if (eligibleIds && !eligibleIds.has(box.value)) {
            return;
          }
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'selected_users[]';
          hidden.value = box.value;
          bulkSelectedContainer.appendChild(hidden);
        });
      }

      var label = bulkAction.options[bulkAction.selectedIndex] ? bulkAction.options[bulkAction.selectedIndex].text : 'this action';
      var selectedForSubmission = bulkSelectedContainer
        ? bulkSelectedContainer.querySelectorAll('input[name="selected_users[]"]').length
        : selected;

      var confirmResult = await maybeConfirmAction(
        'Apply Bulk Action?',
        '<p>Apply <strong>' + escapeHtml(label) + '</strong> to ' + selectedForSubmission + ' selected user(s)?</p>',
        'Apply',
        'Cancel'
      );

      if (!confirmResult || !confirmResult.isConfirmed) {
        resetSubmitState(bulkForm);
        return;
      }

      announce('Preparing to apply ' + label + ' to ' + selectedForSubmission + ' users.');
      setSubmitPending(bulkForm, 'Applying...');
      bulkForm.submit();
    });
  }

  function wireManagePanel() {
    if (!managePanel || !manageOverlay) {
      return;
    }

    manageTriggers.forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        var userId = trigger.getAttribute('data-user-id');
        if (!userId) {
          return;
        }
        var row = document.getElementById('user-row-' + userId);
        if (!row) {
          return;
        }
        openManagePanel(row, trigger);
      });
    });

    if (manageCloseBtn) {
      manageCloseBtn.addEventListener('click', function () {
        closeManagePanel(true);
      });
    }

    manageOverlay.addEventListener('click', function () {
      closeManagePanel(true);
    });

    managePanel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeManagePanel(true);
        return;
      }
      trapFocus(e, managePanel);
    });

    managePanel.querySelectorAll('.users-manage-focus-guard').forEach(function (guard) {
      guard.addEventListener('focus', function () {
        var focusables = getFocusable(managePanel);
        if (focusables.length === 0) {
          return;
        }
        if (guard.getAttribute('data-focus-guard') === 'start') {
          focusables[focusables.length - 1].focus();
        } else {
          focusables[0].focus();
        }
      });
    });

    if (roleSelect) {
      roleSelect.addEventListener('change', function () {
        updateManageRoleHelp();
      });
    }
  }

  function wireCreateModal() {
    if (!createModalOverlay || !createModal || !createForm || !createUserTrigger) {
      return;
    }

    createUserTrigger.addEventListener('click', openCreateModal);
    if (createUserEmptyState) {
      createUserEmptyState.addEventListener('click', openCreateModal);
    }

    if (createModalClose) {
      createModalClose.addEventListener('click', closeCreateModal);
    }
    if (createModalCancel) {
      createModalCancel.addEventListener('click', closeCreateModal);
    }

    createModalOverlay.addEventListener('click', function (e) {
      if (e.target === createModalOverlay) {
        closeCreateModal();
      }
    });

    createModalOverlay.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeCreateModal();
        return;
      }
      trapFocus(e, createModal);
    });

    if (createRoleSelect) {
      createRoleSelect.addEventListener('change', updateCreateRolePolicy);
    }

    createForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!validateCreateModal()) {
        if (createFormError) {
          createFormError.textContent = 'Fix the errors above, then try again.';
          createFormError.style.display = 'block';
        }
        announce('Please fix the add user form errors.');
        return;
      }

      if (createFormError) {
        createFormError.style.display = 'none';
      }
      if (createSubmitBtn) {
        createSubmitBtn.disabled = true;
        if (!createSubmitBtn.dataset.originalText) {
          createSubmitBtn.dataset.originalText = createSubmitBtn.textContent;
        }
        createSubmitBtn.textContent = 'Adding user...';
      }
      announce('Submitting new user account.');
      createForm.submit();
    });

    if (createModalOverlay.getAttribute('data-open-on-load') === '1') {
      openCreateModal();
      if (createFormError && createFormError.textContent.trim() !== '') {
        createFormError.style.display = 'block';
        announce('Add user form has errors.');
      }
    }
  }

  function wireKeyboardAndOnboarding() {
    if (onboardingBanner) {
      try {
        var dismissed = localStorage.getItem(ONBOARDING_KEY);
        if (!dismissed) {
          onboardingBanner.style.display = 'grid';
        }
      } catch (error) {
        onboardingBanner.style.display = 'grid';
      }

      if (onboardingDismiss) {
        onboardingDismiss.addEventListener('click', function () {
          try {
            localStorage.setItem(ONBOARDING_KEY, 'true');
          } catch (error) {
            // ignore storage failures
          }
          onboardingBanner.style.display = 'none';
        });
      }
    }

    if (searchInput) {
      window.setTimeout(function () {
        var createModalOpen = createModalOverlay && createModalOverlay.classList.contains('active');
        if (createModalOpen || panelState.isOpen) {
          return;
        }
        searchInput.focus();
      }, 100);
    }

    // Search debounce: auto-submit form after typing stops
    if (searchInput && searchForm) {
      searchInput.addEventListener('input', function () {
        if (searchDebounceTimer) {
          clearTimeout(searchDebounceTimer);
        }
        searchDebounceTimer = setTimeout(function () {
          searchForm.requestSubmit();
        }, SEARCH_DEBOUNCE_MS);
      });
    }

    // Per-page selector: auto-submit on change
    if (perPageSelect) {
      perPageSelect.addEventListener('change', function () {
        var form = perPageSelect.closest('form');
        if (form) {
          form.requestSubmit();
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      var createModalOpen = createModalOverlay && createModalOverlay.classList.contains('active');
      if (createModalOpen || panelState.isOpen) {
        return;
      }

      var activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
      var isTypingContext = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select' || (document.activeElement && document.activeElement.isContentEditable);

      if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !isTypingContext && searchInput) {
        e.preventDefault();
        searchInput.focus();
        searchInput.select();
      }

      if ((e.metaKey || e.ctrlKey) && e.key === 'k' && searchInput) {
        e.preventDefault();
        searchInput.focus();
        searchInput.select();
      }

      if ((e.metaKey || e.ctrlKey) && e.key === 'n' && createUserTrigger) {
        e.preventDefault();
        openCreateModal();
      }
    });
  }

  function wireActionForms() {
    document.querySelectorAll('.users-quick-form').forEach(function (form) {
      form.addEventListener('submit', handleQuickFormSubmit);
    });

    if (credentialsForm) {
      credentialsForm.addEventListener('submit', handleCredentialsSubmit);
    }
    if (verificationForm) {
      verificationForm.addEventListener('submit', handleVerificationSubmit);
    }
    if (roleForm) {
      roleForm.addEventListener('submit', handleRoleSubmit);
    }
    if (passwordForm) {
      passwordForm.addEventListener('submit', handlePasswordSubmit);
    }
    if (deleteForm) {
      deleteForm.addEventListener('submit', handleDeleteSubmit);
    }
  }

  function showFlashMessages() {
    var swal = getSweetAlertUtils();
    if (!swal) {
      return;
    }

    var flashSuccess = document.getElementById('users-flash-success');
    var flashError = document.getElementById('users-flash-error');

    if (flashSuccess) {
      var message = flashSuccess.getAttribute('data-message');
      if (message) {
        window.setTimeout(function () {
          swal.showSuccess('Success', message, 3000);
        }, 280);
      }
    }

    if (flashError) {
      var errMessage = flashError.getAttribute('data-message');
      if (errMessage) {
        window.setTimeout(function () {
          swal.showError('Error', errMessage);
        }, 280);
      }
    }
  }

  function highlightFocusedRow() {
    var usersLayout = document.querySelector('.users-layout[data-focus-user-id]');
    if (!usersLayout) {
      return;
    }
    var focusUserId = parseInt(usersLayout.getAttribute('data-focus-user-id') || '0', 10);
    if (focusUserId <= 0) {
      return;
    }
    var targetRow = document.getElementById('user-row-' + focusUserId);
    if (!targetRow) {
      return;
    }
    targetRow.classList.add('users-row--focus-pulse');
    window.setTimeout(function () {
      targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 120);
    window.setTimeout(function () {
      targetRow.classList.remove('users-row--focus-pulse');
    }, 2600);

    window.setTimeout(function () {
      targetRow.classList.remove('users-row--focus-target');
      usersLayout.setAttribute('data-focus-user-id', '0');
    }, 2800);
  }

  function wireBulkSelection() {
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        rowChecks.forEach(function (box) {
          box.checked = selectAll.checked;
        });
        updateBulkState();
      });
    }

    rowChecks.forEach(function (box) {
      box.addEventListener('change', updateBulkState);
    });

    if (bulkAction) {
      bulkAction.addEventListener('change', updateBulkState);
    }

    updateBulkState();
  }

  // Initialize everything
  wireBulkSelection();
  wireBulkForm();
  wireManagePanel();
  wireCreateModal();
  wireKeyboardAndOnboarding();
  wireActionForms();
  wireAutoDisableForms();
  updateCreateRolePolicy();
  showFlashMessages();
  highlightFocusedRow();
})();