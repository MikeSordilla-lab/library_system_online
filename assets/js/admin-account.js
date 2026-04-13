(() => {
  const successNotice = document.getElementById('password-change-success');
  const errorNotice = document.getElementById('password-change-error');

  const showPasswordFeedback = async (title, message, isSuccess) => {
    if (!message || typeof Swal === 'undefined' || typeof sweetAlertUtils === 'undefined') {
      return;
    }

    if (isSuccess) {
      await sweetAlertUtils.showSuccess(title, message, 2500);
      return;
    }

    await sweetAlertUtils.showError(title, message);
  };

  if (successNotice) {
    const message = successNotice.getAttribute('data-message') || '';
    setTimeout(() => {
      void showPasswordFeedback('Password Updated', message, true);
    }, 300);
  }

  if (errorNotice) {
    const message = errorNotice.getAttribute('data-message') || '';
    setTimeout(() => {
      void showPasswordFeedback('Password Change Failed', message, false);
    }, 300);
  }

  const form = document.querySelector('.admin-password-form');
  if (!form) {
    return;
  }

  const currentPasswordInput = document.getElementById('current_password');
  const newPasswordInput = document.getElementById('new_password');
  const confirmPasswordInput = document.getElementById('confirm_password');
  const strengthValue = document.querySelector('.password-strength-indicator__value');
  const strengthBar = document.querySelector('.password-strength-indicator__bar');
  const requirementItems = Array.from(document.querySelectorAll('.password-req'));

  const checks = {
    length: (value) => value.length >= 8,
    uppercase: (value) => /[A-Z]/.test(value),
    lowercase: (value) => /[a-z]/.test(value),
    number: (value) => /[0-9]/.test(value),
    symbol: (value) => /[^A-Za-z0-9]/.test(value),
  };

  const getScore = (value) => {
    let score = 0;
    Object.keys(checks).forEach((key) => {
      if (checks[key](value)) {
        score += 1;
      }
    });
    return score;
  };

  const renderStrength = (value) => {
    if (!strengthValue || !strengthBar || requirementItems.length === 0) {
      return;
    }

    const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
    const colors = ['#b42318', '#c8401a', '#a36a14', '#4a6741', '#2f5c36'];
    const score = getScore(value);

    if (value.length === 0) {
      strengthValue.textContent = '-';
      strengthBar.style.width = '8%';
      strengthBar.style.background = '#d5cfc4';
    } else {
      const index = Math.max(0, score - 1);
      strengthValue.textContent = labels[index];
      strengthBar.style.width = `${Math.max(8, (score / 5) * 100)}%`;
      strengthBar.style.background = colors[index];
    }

    requirementItems.forEach((item) => {
      const key = item.getAttribute('data-req') || '';
      const check = checks[key];
      if (!check) {
        return;
      }

      const isValid = check(value);
      item.classList.toggle('active', isValid);

      const checkEl = item.querySelector('.password-req__check');
      if (checkEl) {
        checkEl.textContent = isValid ? '✓' : '○';
      }
    });
  };

  if (newPasswordInput) {
    newPasswordInput.addEventListener('input', () => {
      renderStrength(newPasswordInput.value);

      if (confirmPasswordInput) {
        const matches =
          confirmPasswordInput.value === '' || confirmPasswordInput.value === newPasswordInput.value;
        confirmPasswordInput.setCustomValidity(matches ? '' : 'Password confirmation does not match.');
      }
    });

    renderStrength(newPasswordInput.value);
  }

  if (confirmPasswordInput && newPasswordInput) {
    confirmPasswordInput.addEventListener('input', () => {
      const matches =
        confirmPasswordInput.value === '' || confirmPasswordInput.value === newPasswordInput.value;
      confirmPasswordInput.setCustomValidity(matches ? '' : 'Password confirmation does not match.');
    });
  }

  if (typeof sweetAlertUtils !== 'undefined') {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const hasCurrent = currentPasswordInput && currentPasswordInput.value.trim() !== '';
      const hasNew = newPasswordInput && newPasswordInput.value.trim() !== '';

      if (!hasCurrent || !hasNew) {
        await sweetAlertUtils.showError('Missing Required Fields', 'Please fill in all password fields.');
        return;
      }

      const result = await sweetAlertUtils.confirmAction(
        'Confirm Password Change',
        '<p>Are you sure you want to change your password?</p><p><small style="color: var(--muted);">You will need to use your new password to log in next time.</small></p>',
        'Update Password',
        'Cancel',
      );

      if (result.isConfirmed) {
        form.submit();
      }
    });
  }
})();
