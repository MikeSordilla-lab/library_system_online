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

  const managePanelOverlay = document.getElementById('manage-user-panel-overlay');
  const managePanel = document.getElementById('manage-user-panel');
  const manageCloseBtn = document.getElementById('manage-user-panel-close');
  const manageCancelBtn = document.getElementById('manage-user-panel-cancel-btn');
  const manageSaveBtn = document.getElementById('manage-user-panel-save-btn');
  const manageTriggers = Array.from(document.querySelectorAll('.users-manage-trigger'));
  const manageName = document.getElementById('users-manage-name');
  const manageEmail = document.getElementById('users-manage-email');
  const manageRoleChip = document.getElementById('users-manage-role-chip');
  const manageVerifiedChip = document.getElementById('users-manage-verified-chip');
  const manageStatusChip = document.getElementById('users-manage-status-chip');
  const manageJoined = document.getElementById('users-manage-joined');

  const manageForm = document.getElementById('users-manage-form');
  const panelActionInput = document.getElementById('panel-action-input');
  const panelUserId = document.getElementById('panel-user-id');
  const roleHelp = document.getElementById('users-manage-role-help');
  const verificationHelp = document.getElementById('users-manage-verification-help');
  const roleSelect = document.getElementById('users-manage-role');
  const verificationSelect = document.getElementById('users-manage-verification');

  const activateAction = document.getElementById('activate-action');
  const deactivateAction = document.getElementById('deactivate-action');
  const btnActivate = document.getElementById('btn-activate');
  const btnDeactivate = document.getElementById('btn-deactivate');
  const btnDelete = document.getElementById('btn-delete');
  const deleteConfirmCheckbox = document.getElementById('users-manage-delete-confirm');

  const panelTabs = document.querySelectorAll('.panel-tab');
  const tabContents = document.querySelectorAll('.tab-content');

  const credentialsForm = manageForm;
  const verificationForm = manageForm;
  const roleForm = manageForm;
  const passwordForm = manageForm;
  const deleteForm = manageForm;
  const activateForm = manageForm;
  const deactivateForm = manageForm;

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
  const appShell = document.querySelector('.app-shell');

  const panelState = {
    isOpen: false,
    row: null,
    trigger: null,
  };

  let hasInitializedBulkState = false;
  let scrollLocks = 0;
  function getSweetAlertUtils() {
    return (typeof window !== 'undefined' && window.sweetAlertUtils) ? window.sweetAlertUtils : null;
  }

  function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, function (char) {
      var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
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
    if (appShell) {
      appShell.setAttribute('inert', '');
      appShell.setAttribute('aria-hidden', 'true');
    }
  }

  function unlockBodyScroll() {
    scrollLocks = Math.max(0, scrollLocks - 1);
    if (scrollLocks === 0) {
      document.body.style.overflow = '';
      if (appShell) {
        appShell.removeAttribute('inert');
        appShell.removeAttribute('aria-hidden');
      }
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
      return !el.hasAttribute('hidden')
        && el.getAttribute('aria-hidden') !== 'true'
        && el.offsetParent !== null;
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
    if (!manageForm || !roleSelect) {
      return;
    }
    var currentVerified = manageForm.getAttribute('data-current-verified') === '1';
    var currentRole = manageForm.getAttribute('data-current-role') || '';

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

  function switchTab(tabName) {
    panelTabs.forEach(function(tab) {
      var isActive = tab.getAttribute('data-tab') === tabName;
      tab.classList.toggle('active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    tabContents.forEach(function(content) {
      var isActive = content.id === 'user-panel-tab-' + tabName;
      content.classList.toggle('active', isActive);
    });

    var firstInput = document.querySelector('#user-panel-tab-' + tabName + ' input:not([type="hidden"]), #user-panel-tab-' + tabName + ' select');
    if (firstInput) {
      window.setTimeout(function() { firstInput.focus(); }, 100);
    }
  }

  function getActiveTab() {
    var activeTab = document.querySelector('.panel-tab.active');
    return activeTab ? activeTab.getAttribute('data-tab') : 'profile';
  }

  function detectActionAndSubmit(expectedAction) {
    panelActionInput.value = expectedAction;
    manageForm.submit();
  }

  function hydrateManagePanel(row) {
    if (!row || !manageForm) {
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

    panelUserId.value = data.id;

    var fullNameInput = manageForm.querySelector('input[name="full_name"]');
    var emailInput = manageForm.querySelector('input[name="email"]');
    if (fullNameInput) fullNameInput.value = data.name;
    if (emailInput) emailInput.value = data.email;
    manageForm.setAttribute('data-user-name', data.name);
    manageForm.setAttribute('data-current-name', data.name);
    manageForm.setAttribute('data-current-email', data.email);

    verificationSelect.value = data.verified ? '1' : '0';
    manageForm.setAttribute('data-user-name', data.name);
    manageForm.setAttribute('data-role', data.role);
    manageForm.setAttribute('data-role-label', data.roleLabel);
    manageForm.setAttribute('data-role-requires-verified', data.roleRequiresVerified ? '1' : '0');
    manageForm.setAttribute('data-current-verified', data.verified ? '1' : '0');

    roleSelect.value = data.role;
    manageForm.setAttribute('data-user-name', data.name);
    manageForm.setAttribute('data-current-role', data.role);
    manageForm.setAttribute('data-current-verified', data.verified ? '1' : '0');

    var newPassInput = manageForm.querySelector('input[name="new_password"]');
    var confirmPassInput = manageForm.querySelector('input[name="confirm_password"]');
    if (newPassInput) newPassInput.value = '';
    if (confirmPassInput) confirmPassInput.value = '';
    manageForm.setAttribute('data-user-name', data.name);

    if (deleteConfirmCheckbox) deleteConfirmCheckbox.checked = false;
    manageForm.setAttribute('data-user-name', data.name);
    manageForm.setAttribute('data-user-role', data.roleLabel);

    if (activateAction) activateAction.hidden = !data.suspended;
    if (deactivateAction) deactivateAction.hidden = data.suspended;

    var isProtected = data.isProtected;
    var controls = managePanel.querySelectorAll('input, select, button');
    controls.forEach(function (control) {
      if (control === manageCloseBtn || control === manageCancelBtn || control === manageSaveBtn) {
        return;
      }
      control.disabled = isProtected;
    });

    switchTab('profile');
    updateManageRoleOptionPolicy();
    updateManageVerificationState();
    updateManageRoleHelp();
  }

  function openManagePanel(row, trigger) {
    if (!managePanelOverlay || !managePanel) {
      console.error('[admin-users] Manage panel elements not found in DOM');
      return;
    }

    if (!row) {
      console.error('[admin-users] openManagePanel called without a valid row element');
      return;
    }

    if (panelState.isOpen && panelState.row === row) {
      return;
    }

    if (panelState.isOpen) {
      panelState.row = row;
      panelState.trigger = trigger || panelState.trigger;
      hydrateManagePanel(row);

      if (!managePanelOverlay.classList.contains('active')) {
        managePanelOverlay.classList.add('active');
        managePanelOverlay.setAttribute('aria-hidden', 'false');
        lockBodyScroll();
      }

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
      announce('Protected account cannot be modified.');
      return;
    }

    panelState.row = row;
    panelState.trigger = trigger || null;
    panelState.isOpen = true;

    hydrateManagePanel(row);

    managePanelOverlay.classList.add('active');
    managePanelOverlay.setAttribute('aria-hidden', 'false');
    lockBodyScroll();

    window.setTimeout(function () {
      var focusableItems = getFocusable(managePanel);
      var firstFocusable = focusableItems.find(function (el) {
        return el.id === 'users-manage-full-name' || el.tagName === 'INPUT' || el.tagName === 'SELECT';
      });
      if (firstFocusable) {
        firstFocusable.focus();
      }
    }, 80);
  }

  function closeManagePanel(restoreFocus) {
    if (!managePanelOverlay) {
      return;
    }

    var wasOpen = panelState.isOpen;
    var trigger = panelState.trigger;
    panelState.isOpen = false;
    panelState.row = null;
    panelState.trigger = null;
    managePanelOverlay.classList.remove('active');
    managePanelOverlay.setAttribute('aria-hidden', 'true');

    if (wasOpen) {
      unlockBodyScroll();
    }

    if (restoreFocus !== false && trigger && typeof trigger.focus === 'function') {
      trigger.focus();
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

  function dialogNote(text) {
    return '<p class="users-dialog-note">' + escapeHtml(text) + '</p>';
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

  async function handlePanelCredentialsSubmit(e) {
    var userName = manageForm.getAttribute('data-user-name') || 'this user';
    var currentName = manageForm.getAttribute('data-current-name') || '';
    var currentEmail = (manageForm.getAttribute('data-current-email') || '').toLowerCase();
    var fullNameInput = manageForm.querySelector('input[name="full_name"]');
    var emailInput = manageForm.querySelector('input[name="email"]');
    var nextName = fullNameInput ? fullNameInput.value.trim() : '';
    var nextEmail = emailInput ? emailInput.value.trim().toLowerCase() : '';

    e.preventDefault();

    if (!nextName || !nextEmail) {
      await maybeShowError('Missing Required Fields', 'Full name and email are required.');
      resetSubmitState(manageForm);
      return;
    }

    if (nextName === currentName && nextEmail === currentEmail) {
      await maybeShowInfo('No Changes', 'No credential changes were detected for this account.');
      resetSubmitState(manageForm);
      return;
    }

    var result = await maybeConfirmAction(
      'Save Credential Changes?',
      '<p><strong>' + escapeHtml(userName) + '</strong></p>' + dialogNote('Updates to full name and email will take effect immediately.'),
      'Save Credentials',
      'Cancel'
    );

    if (result && result.isConfirmed) {
      setSubmitPending(manageForm, 'Saving...');
      detectActionAndSubmit('update_user_credentials');
      return;
    }
    resetSubmitState(manageForm);
  }

  async function handlePanelVerificationSubmit(e) {
    var userName = manageForm.getAttribute('data-user-name') || 'this user';
    var roleLabel = manageForm.getAttribute('data-role-label') || 'Current role';
    var roleRequiresVerified = manageForm.getAttribute('data-role-requires-verified') === '1';
    var currentVerified = manageForm.getAttribute('data-current-verified') === '1';
    var nextVerified = verificationSelect ? verificationSelect.value === '1' : currentVerified;

    e.preventDefault();

    if (nextVerified === currentVerified) {
      await maybeShowInfo('No Change', 'Verification status is already up to date.');
      resetSubmitState(manageForm);
      return;
    }

    if (!nextVerified && roleRequiresVerified) {
      await maybeShowError('Policy Restricted', roleLabel + ' accounts must remain verified. Change role first if needed.');
      resetSubmitState(manageForm);
      return;
    }

    var result = await maybeConfirmAction(
      'Save Verification Status?',
      '<p><strong>' + escapeHtml(userName) + '</strong></p><p class="users-dialog-note">The account will be marked as ' + (nextVerified ? '<strong>Verified</strong>' : '<strong>Not verified</strong>') + ' immediately.</p>',
      'Save Verification',
      'Cancel'
    );

    if (result && result.isConfirmed) {
      setSubmitPending(manageForm, 'Saving...');
      detectActionAndSubmit('update_user_verification');
      return;
    }
    resetSubmitState(manageForm);
  }

  async function handlePanelRoleSubmit(e) {
    var userName = manageForm.getAttribute('data-user-name') || 'this user';
    var currentRole = manageForm.getAttribute('data-current-role') || '';
    var currentVerified = manageForm.getAttribute('data-current-verified') === '1';
    var newRole = roleSelect ? roleSelect.value : currentRole;

    e.preventDefault();

    if (!roleSelect) {
      return;
    }

    if (newRole === currentRole) {
      await maybeShowInfo('No Change', 'The new role is the same as the current role.');
      resetSubmitState(manageForm);
      return;
    }

    if (roleRequiresVerification(newRole) && !currentVerified) {
      await maybeShowError('Verification Required', 'Mark this account as verified before assigning Admin or Librarian access.');
      resetSubmitState(manageForm);
      return;
    }

    var swal = getSweetAlertUtils();
    var result = swal
      ? await swal.confirmChangeRole(userName, currentRole, newRole)
      : await maybeConfirmAction('Change User Role', 'Change role for "' + escapeHtml(userName) + '" from ' + escapeHtml(currentRole) + ' to ' + escapeHtml(newRole) + '?', 'Change Role', 'Cancel');

    if (result && result.isConfirmed) {
      setSubmitPending(manageForm, 'Saving...');
      detectActionAndSubmit('role_change');
      return;
    }
    resetSubmitState(manageForm);
  }

  async function handlePanelPasswordSubmit(e) {
    var userName = manageForm.getAttribute('data-user-name') || 'this user';
    var newPasswordInput = manageForm.querySelector('input[name="new_password"]');
    var confirmPasswordInput = manageForm.querySelector('input[name="confirm_password"]');
    var newPassword = newPasswordInput ? newPasswordInput.value : '';
    var confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';

    e.preventDefault();

    if (newPassword.length < 8) {
      await maybeShowError('Invalid Password', 'Password must be at least 8 characters.');
      resetSubmitState(manageForm);
      return;
    }

    if (newPassword !== confirmPassword) {
      await maybeShowError('Password Mismatch', 'New password and confirmation must match.');
      resetSubmitState(manageForm);
      return;
    }

    var result = await maybeConfirmAction(
      'Reset User Password?',
      '<p><strong>' + escapeHtml(userName) + '</strong></p>' + dialogNote('This will replace the user\'s current password immediately.'),
      'Update Password',
      'Cancel'
    );

    if (result && result.isConfirmed) {
      setSubmitPending(manageForm, 'Updating...');
      detectActionAndSubmit('admin_reset_user_password');
      return;
    }
    resetSubmitState(manageForm);
  }

  async function handlePanelDeleteSubmit(e) {
    var userName = manageForm.getAttribute('data-user-name') || 'this user';
    var userRole = manageForm.getAttribute('data-user-role') || 'User';

    e.preventDefault();
    var result = await maybeConfirmDelete(userName, userRole);
    if (result && result.isConfirmed) {
      if (deleteConfirmCheckbox) {
        deleteConfirmCheckbox.checked = true;
      }
      setSubmitPending(manageForm, 'Deleting...');
      detectActionAndSubmit('delete_user');
      return;
    }
    resetSubmitState(manageForm);
  }

  async function handlePanelActivateSubmit(e) {
    var userName = manageForm.getAttribute('data-user-name') || 'this user';
    e.preventDefault();
    var result = await maybeConfirmQuickAction('activate', userName);
    if (result && result.isConfirmed) {
      setSubmitPending(manageForm, 'Reactivating...');
      detectActionAndSubmit('activate');
      return;
    }
    resetSubmitState(manageForm);
  }

  async function handlePanelDeactivateSubmit(e) {
    var userName = manageForm.getAttribute('data-user-name') || 'this user';
    e.preventDefault();
    var result = await maybeConfirmQuickAction('deactivate', userName);
    if (result && result.isConfirmed) {
      setSubmitPending(manageForm, 'Deactivating...');
      detectActionAndSubmit('deactivate');
      return;
    }
    resetSubmitState(manageForm);
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
    createModalOverlay.dataset.restoreFocusId = document.activeElement && document.activeElement.id ? document.activeElement.id : '';
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
    var restoreTarget = createModalOverlay.dataset.restoreFocusId
      ? document.getElementById(createModalOverlay.dataset.restoreFocusId)
      : createUserTrigger;
    createModalOverlay.dataset.restoreFocusId = '';
    if (restoreTarget && typeof restoreTarget.focus === 'function') {
      restoreTarget.focus();
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
            '<p>' + blocked + ' selected user(s) are not verified and will be skipped.</p>' + dialogNote('Continue with ' + eligibleUsers.length + ' eligible user(s)?'),
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
    if (!managePanelOverlay || !managePanel) {
      console.warn('[admin-users] Manage panel elements not found, panel functionality disabled');
      return;
    }

    manageTriggers.forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        try {
          var userId = trigger.getAttribute('data-user-id');
          if (!userId) {
            console.error('[admin-users] Manage trigger missing data-user-id attribute');
            return;
          }
          var row = document.getElementById('user-row-' + userId);
          if (!row) {
            console.error('[admin-users] User row not found: user-row-' + userId);
            return;
          }
          openManagePanel(row, trigger);
        } catch (error) {
          console.error('[admin-users] Error opening manage panel:', error);
        }
      });
    });

    if (manageCloseBtn) {
      manageCloseBtn.addEventListener('click', function () {
        closeManagePanel(true);
      });
    }
    if (manageCancelBtn) {
      manageCancelBtn.addEventListener('click', function () {
        closeManagePanel(true);
      });
    }
    if (manageSaveBtn) {
      manageSaveBtn.addEventListener('click', function () {
        var activeTab = getActiveTab();
        if (activeTab === 'profile') {
          manageForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        } else if (activeTab === 'permissions') {
          manageForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        } else if (activeTab === 'security') {
          manageForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        } else if (activeTab === 'danger') {
          if (!deleteConfirmCheckbox.checked) {
            maybeShowError('Confirmation Required', 'Please confirm the deletion before continuing.');
            return;
          }
          manageForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
      });
    }

    panelTabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        var tabName = tab.getAttribute('data-tab');
        switchTab(tabName);
      });
      tab.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
          e.preventDefault();
          var tabs = Array.from(panelTabs);
          var currentIndex = tabs.indexOf(tab);
          var nextIndex = e.key === 'ArrowRight'
            ? (currentIndex + 1) % tabs.length
            : (currentIndex - 1 + tabs.length) % tabs.length;
          tabs[nextIndex].click();
          tabs[nextIndex].focus();
        }
      });
    });

    if (btnActivate) {
      btnActivate.addEventListener('click', handlePanelActivateSubmit);
    }
    if (btnDeactivate) {
      btnDeactivate.addEventListener('click', handlePanelDeactivateSubmit);
    }
    if (btnDelete) {
      btnDelete.addEventListener('click', handlePanelDeleteSubmit);
    }

    managePanelOverlay.addEventListener('click', function (e) {
      if (e.target === managePanelOverlay) {
        closeManagePanel(true);
      }
    });

    managePanelOverlay.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeManagePanel(true);
        return;
      }
      trapFocus(e, managePanel);
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
          onboardingBanner.hidden = false;
        }
      } catch (error) {
        onboardingBanner.hidden = false;
      }

      if (onboardingDismiss) {
        onboardingDismiss.addEventListener('click', function () {
          try {
            localStorage.setItem(ONBOARDING_KEY, 'true');
          } catch (error) {
            // ignore storage failures
          }
          onboardingBanner.hidden = true;
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
        if (searchInput.value && typeof searchInput.setSelectionRange === 'function') {
          var end = searchInput.value.length;
          searchInput.setSelectionRange(end, end);
        }
      }, 100);
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

    if (manageForm) {
      manageForm.addEventListener('submit', function(e) {
        var activeTab = getActiveTab();
        if (activeTab === 'profile') {
          handlePanelCredentialsSubmit(e);
        } else if (activeTab === 'permissions') {
          e.preventDefault();
          var currentVerified = manageForm.getAttribute('data-current-verified') === '1';
          var nextVerified = verificationSelect ? verificationSelect.value === '1' : currentVerified;
          var currentRole = manageForm.getAttribute('data-current-role') || '';
          var newRole = roleSelect ? roleSelect.value : currentRole;
          if (newRole !== currentRole) {
            handlePanelRoleSubmit(e);
          } else if (nextVerified !== currentVerified) {
            handlePanelVerificationSubmit(e);
          } else {
            maybeShowInfo('No Change', 'No changes detected in the permissions section.');
            resetSubmitState(manageForm);
          }
        } else if (activeTab === 'security') {
          handlePanelPasswordSubmit(e);
        } else if (activeTab === 'danger') {
          e.preventDefault();
          if (!deleteConfirmCheckbox.checked) {
            maybeShowError('Confirmation Required', 'Please confirm the deletion before continuing.');
            resetSubmitState(manageForm);
            return;
          }
          handlePanelDeleteSubmit(e);
        }
      });
    }
  }

  function successTitleForMessage(message) {
    return message === 'User account created.' ? 'User Added' : 'Success';
  }

  function showFlashMessages(attempt) {
    attempt = attempt || 0;
    var swal = getSweetAlertUtils();
    if (!swal) {
      if (attempt < 10) {
        window.setTimeout(function () {
          showFlashMessages(attempt + 1);
        }, 100);
      }
      return;
    }

    var flashSuccess = document.getElementById('users-flash-success');
    var flashError = document.getElementById('users-flash-error');

    if (flashSuccess) {
      var message = flashSuccess.getAttribute('data-message');
      if (message) {
        window.setTimeout(function () {
          swal.showSuccess(successTitleForMessage(message), message, 3000);
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
