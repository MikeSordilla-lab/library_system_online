document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;
  const alertType = body.dataset.alertType;
  const alertMessage = body.dataset.alertMessage;
  const alertEmail = body.dataset.alertEmail || '';
  const alertCsrf = body.dataset.alertCsrf || '';
  const baseUrl = body.dataset.baseUrl || '';

  if (!alertType || !alertMessage) {
    return;
  }

  const normalizedType = alertType === 'success' ? 'success' : 'error';
  const lowerMessage = alertMessage.toLowerCase();
  const errorContext = lowerMessage.includes('verify')
    ? 'unverified'
    : lowerMessage.includes('suspended') || lowerMessage.includes('suspension')
      ? 'suspended'
      : '';

  if (typeof window.sweetAlertUtils !== 'undefined') {
    if (normalizedType === 'success') {
      window.sweetAlertUtils.showSuccess('Success', alertMessage, 3000);
      return;
    }

    window.sweetAlertUtils.showLoginError(alertMessage, errorContext, alertEmail, alertCsrf, baseUrl);
    return;
  }

  if (typeof window.Swal === 'undefined') {
    return;
  }

  let html = alertMessage;
  if (errorContext === 'unverified' && alertEmail !== '') {
    html += '\n\nCheck your inbox to verify. If you need a new code, use the resend button below.';
  }

  window.Swal.fire({
    icon: normalizedType,
    title: normalizedType === 'success' ? 'Success' : 'Error',
    text: html
  });
});
