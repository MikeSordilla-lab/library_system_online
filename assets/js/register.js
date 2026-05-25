document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;
  const alertType = body.dataset.alertType;
  const alertTitle = body.dataset.alertTitle;
  const alertMessage = body.dataset.alertMessage;
  const alertRedirect = body.dataset.alertRedirect;

  if (!alertType || !alertMessage) {
    return;
  }

  const normalizedType = alertType === 'success' ? 'success' : 'error';
  const title = alertTitle || (normalizedType === 'success' ? 'Success' : 'Error');

  const redirectIfNeeded = function () {
    if (normalizedType === 'success' && alertRedirect) {
      window.location.href = alertRedirect;
    }
  };

  const showWithFallback = function () {
    if (typeof window.Swal !== 'undefined') {
      window.Swal.fire({
        icon: normalizedType,
        title: title,
        text: alertMessage
      }).then(redirectIfNeeded);
      return;
    }

    window.alert(title + '\n\n' + alertMessage);
    redirectIfNeeded();
  };

  if (typeof window.sweetAlertUtils === 'undefined' || typeof window.Swal === 'undefined') {
    showWithFallback();
    return;
  }

  if (normalizedType === 'success') {
    window.sweetAlertUtils.showSuccess(title, alertMessage, 3000).then(function () {
      redirectIfNeeded();
    });
    return;
  }

  window.sweetAlertUtils.showError(title, alertMessage);
});
