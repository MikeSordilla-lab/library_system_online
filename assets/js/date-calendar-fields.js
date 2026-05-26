(function () {
  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function textToIso(value) {
    var match = /^(\d{2})-(\d{2})-(\d{2})$/.exec(String(value || '').trim());
    if (!match) {
      return '';
    }

    var month = Number(match[1]);
    var day = Number(match[2]);
    var year = 2000 + Number(match[3]);
    var date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
      return '';
    }

    return year + '-' + pad(month) + '-' + pad(day);
  }

  function isoToText(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || '').trim());
    if (!match) {
      return '';
    }

    return match[2] + '-' + match[3] + '-' + match[1].slice(-2);
  }

  function openPicker(input) {
    if (typeof input.showPicker === 'function') {
      input.showPicker();
      return;
    }

    input.focus();
    input.click();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-calendar-field]').forEach(function (field) {
      var textInput = field.querySelector('[data-calendar-text]');
      var dateInput = field.querySelector('[data-calendar-hidden]');
      var trigger = field.querySelector('[data-calendar-trigger]');
      if (!textInput || !dateInput || !trigger) {
        return;
      }

      dateInput.value = textToIso(textInput.value);

      trigger.addEventListener('click', function () {
        dateInput.value = textToIso(textInput.value);
        openPicker(dateInput);
      });

      dateInput.addEventListener('change', function () {
        textInput.value = isoToText(dateInput.value);
        textInput.dispatchEvent(new Event('input', { bubbles: true }));
      });

      textInput.addEventListener('blur', function () {
        dateInput.value = textToIso(textInput.value);
      });
    });
  });
}());
