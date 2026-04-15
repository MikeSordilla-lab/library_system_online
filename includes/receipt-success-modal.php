<?php

$flash_receipt_no = isset($flash_receipt_no) ? trim((string) $flash_receipt_no) : '';
if ($flash_receipt_no === '') {
  return;
}

$receipt_modal_base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';

$receipt_modal_title = isset($receipt_modal_title) && trim((string) $receipt_modal_title) !== ''
  ? (string) $receipt_modal_title
  : 'Receipt ready';

$receipt_modal_message = isset($receipt_modal_message) && trim((string) $receipt_modal_message) !== ''
  ? (string) $receipt_modal_message
  : 'Your transaction was completed successfully. You can open the ticket now.';

$receipt_modal_view_label = isset($receipt_modal_view_label) && trim((string) $receipt_modal_view_label) !== ''
  ? (string) $receipt_modal_view_label
  : 'View Ticket';

$receipt_modal_view_url = $receipt_modal_base_url . 'receipt/view.php?no=' . rawurlencode($flash_receipt_no);
$receipt_modal_kiosk_url = $receipt_modal_base_url . 'receipt/kiosk.php?no=' . rawurlencode($flash_receipt_no);
?>
<div class="receipt-success-modal" role="dialog" aria-modal="true" aria-labelledby="receipt-success-modal-title">
  <div class="receipt-success-modal__backdrop" data-receipt-modal-close></div>
  <div class="receipt-success-modal__panel">
    <h2 class="receipt-success-modal__title" id="receipt-success-modal-title"><?= htmlspecialchars($receipt_modal_title, ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="receipt-success-modal__message"><?= htmlspecialchars($receipt_modal_message, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="receipt-success-modal__number">Receipt No: <strong><?= htmlspecialchars($flash_receipt_no, ENT_QUOTES, 'UTF-8') ?></strong></p>
    <div class="receipt-success-modal__actions">
      <a class="btn-primary" href="<?= htmlspecialchars($receipt_modal_view_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($receipt_modal_view_label, ENT_QUOTES, 'UTF-8') ?></a>
      <a class="btn-ghost" href="<?= htmlspecialchars($receipt_modal_kiosk_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Kiosk Print View</a>
      <button type="button" class="btn-ghost" data-receipt-modal-close>Close</button>
    </div>
  </div>
</div>
<style>
  .receipt-success-modal {
    position: fixed;
    inset: 0;
    z-index: 1200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }

  .receipt-success-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
  }

  .receipt-success-modal__panel {
    position: relative;
    width: min(560px, 100%);
    background: #fff;
    color: #1f2937;
    border-radius: 10px;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22);
    padding: 20px;
  }

  .receipt-success-modal__title {
    margin: 0 0 10px;
    font-size: 1.35rem;
  }

  .receipt-success-modal__message {
    margin: 0 0 10px;
  }

  .receipt-success-modal__number {
    margin: 0 0 16px;
    font-size: 0.95rem;
  }

  .receipt-success-modal__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
</style>
<script>
  (function() {
    var modal = document.querySelector('.receipt-success-modal');
    if (!modal) {
      return;
    }

    var closeButtons = modal.querySelectorAll('[data-receipt-modal-close]');
    function closeModal() {
      modal.style.display = 'none';
    }

    for (var i = 0; i < closeButtons.length; i++) {
      closeButtons[i].addEventListener('click', closeModal);
    }
  })();
</script>
