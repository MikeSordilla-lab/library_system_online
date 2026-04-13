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

  const showWithFallback = function () {
    if (typeof window.Swal === 'undefined') {
      return;
    }

    window.Swal.fire({
      icon: normalizedType,
      title: title,
      text: alertMessage
    }).then(function () {
      if (normalizedType === 'success' && alertRedirect) {
        window.location.href = alertRedirect;
      }
    });
  };

  if (typeof window.sweetAlertUtils === 'undefined') {
    showWithFallback();
    return;
  }

  if (normalizedType === 'success') {
    window.sweetAlertUtils.showSuccess(title, alertMessage, 3000).then(function () {
      if (alertRedirect) {
        window.location.href = alertRedirect;
      }
    });
    return;
  }

  window.sweetAlertUtils.showError(title, alertMessage);
});
