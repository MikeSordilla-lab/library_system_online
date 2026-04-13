document.addEventListener('DOMContentLoaded', function () {
  const otpGroup = document.getElementById('otp-group');
  const otpValueInput = document.getElementById('otp-value');
  const verifyForm = document.getElementById('verify-form');

  if (!otpGroup || !otpValueInput || !verifyForm) {
    return;
  }

  const tiles = Array.from(otpGroup.querySelectorAll('input.otp-digit'));

  otpGroup.addEventListener('keydown', function (event) {
    const index = tiles.indexOf(event.target);
    if (index === -1) {
      return;
    }

    if (event.key === 'Backspace' && event.target.value === '' && index > 0) {
      tiles[index - 1].focus();
      tiles[index - 1].value = '';
    }
  });

  otpGroup.addEventListener('input', function (event) {
    const index = tiles.indexOf(event.target);
    if (index === -1) {
      return;
    }

    event.target.value = event.target.value.replace(/\D/g, '').slice(-1);
    if (event.target.value && index < tiles.length - 1) {
      tiles[index + 1].focus();
    }
  });

  otpGroup.addEventListener('paste', function (event) {
    event.preventDefault();
    const clipboard = event.clipboardData || window.clipboardData;
    const text = clipboard.getData('text').replace(/\D/g, '').slice(0, 6);

    text.split('').forEach(function (digit, index) {
      if (tiles[index]) {
        tiles[index].value = digit;
      }
    });

    tiles[Math.min(text.length, tiles.length - 1)].focus();
  });

  verifyForm.addEventListener('submit', function () {
    otpValueInput.value = tiles.map(function (tile) {
      return tile.value;
    }).join('');

    tiles.forEach(function (tile) {
      tile.removeAttribute('name');
    });
  });
});
