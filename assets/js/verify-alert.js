document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;
  const alertType = body.dataset.alertType;
  const alertTitle = body.dataset.alertTitle;
  const alertMessage = body.dataset.alertMessage;

  if (!alertType || !alertMessage) {
    return;
  }

  const normalizedType = alertType === 'success' ? 'success' : 'error';
  const title = alertTitle || (normalizedType === 'success' ? 'Success' : 'Error');

  if (typeof window.sweetAlertUtils !== 'undefined' && typeof window.Swal !== 'undefined') {
    if (normalizedType === 'success') {
      window.sweetAlertUtils.showSuccess(title, alertMessage, 3000);
      return;
    }

    window.sweetAlertUtils.showError(title, alertMessage);
    return;
  }

  if (typeof window.Swal !== 'undefined') {
    window.Swal.fire({
      icon: normalizedType,
      title: title,
      text: alertMessage
    });
    return;
  }

  window.alert(title + '\n\n' + alertMessage);
});
