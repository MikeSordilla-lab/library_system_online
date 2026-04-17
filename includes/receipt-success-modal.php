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
  : 'Your transaction was completed successfully. Review the ticket preview, then press Ctrl+P to print.';

$receipt_modal_preview_url = $receipt_modal_base_url . 'receipt/view.php?no=' . rawurlencode($flash_receipt_no) . '&compact=1';

$receipt_modal_dom_id = 'receipt-success-modal-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $flash_receipt_no);
$receipt_modal_title_id = $receipt_modal_dom_id . '-title';
$receipt_modal_msg_id = $receipt_modal_dom_id . '-message';
?>
<div
  class="receipt-success-modal"
  data-receipt-modal
  role="dialog"
  aria-modal="true"
  aria-labelledby="<?= htmlspecialchars($receipt_modal_title_id, ENT_QUOTES, 'UTF-8') ?>"
  aria-describedby="<?= htmlspecialchars($receipt_modal_msg_id, ENT_QUOTES, 'UTF-8') ?>"
>
  <div class="receipt-success-modal__backdrop" data-receipt-modal-close></div>
  <div class="receipt-success-modal__panel" data-receipt-modal-panel>
    <h2 class="receipt-success-modal__title" id="<?= htmlspecialchars($receipt_modal_title_id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($receipt_modal_title, ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="receipt-success-modal__message" id="<?= htmlspecialchars($receipt_modal_msg_id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($receipt_modal_message, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="receipt-success-modal__number">Receipt No: <strong><?= htmlspecialchars($flash_receipt_no, ENT_QUOTES, 'UTF-8') ?></strong></p>
    <p class="receipt-success-modal__status" data-receipt-print-status role="status" aria-live="polite"></p>
    <div class="receipt-success-modal__preview-wrap">
      <iframe
        class="receipt-success-modal__preview-frame"
        data-receipt-preview
        src="<?= htmlspecialchars($receipt_modal_preview_url, ENT_QUOTES, 'UTF-8') ?>"
        title="Ticket preview"
      ></iframe>
    </div>
    <div class="receipt-success-modal__actions">
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
    background: rgba(15, 14, 12, 0.58);
  }

  .receipt-success-modal__panel {
    position: relative;
    width: min(760px, 100%);
    max-height: min(92vh, 980px);
    overflow: auto;
    background: #f7f4ee;
    color: #0f0e0c;
    border: 1px solid #d5cfc4;
    border-radius: 12px;
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.24);
    padding: 20px;
  }

  .receipt-success-modal__title {
    margin: 0 0 10px;
    font-family: "Playfair Display", Georgia, serif;
    font-size: 1.4rem;
  }

  .receipt-success-modal__message {
    margin: 0 0 10px;
    color: #5f5850;
  }

  .receipt-success-modal__number {
    margin: 0 0 16px;
    font-size: 0.95rem;
  }

  .receipt-success-modal__status {
    margin: 0 0 14px;
    min-height: 1.2em;
    color: #5f5850;
    font-size: 0.9rem;
  }

  .receipt-success-modal__preview-wrap {
    border: 1px solid #d5cfc4;
    border-radius: 10px;
    background: #ffffff;
    margin: 0 0 14px;
    min-height: 360px;
  }

  .receipt-success-modal__preview-frame {
    width: 100%;
    min-height: 64vh;
    border: 0;
    display: block;
  }

  .receipt-success-modal__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }

  @media (max-width: 640px) {
    .receipt-success-modal__panel {
      padding: 14px;
    }

    .receipt-success-modal__preview-frame {
      min-height: 58vh;
    }
  }
</style>
<script>
  (function() {
    var modals = document.querySelectorAll('[data-receipt-modal]');
    if (!modals.length) {
      return;
    }

    function getFocusableElements(container) {
      return container.querySelectorAll(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
      );
    }

    function initModal(modal) {
      var panel = modal.querySelector('[data-receipt-modal-panel]');
      var previewFrame = modal.querySelector('[data-receipt-preview]');
      var statusNode = modal.querySelector('[data-receipt-print-status]');
      var closeButtons = modal.querySelectorAll('[data-receipt-modal-close]');
      var initialFocus = document.activeElement;
      var isPreviewReady = false;

      if (!panel || !statusNode || !previewFrame) {
        return;
      }

      function setStatus(message) {
        statusNode.textContent = message;
      }

      function closeModal() {
        modal.style.display = 'none';
        document.removeEventListener('keydown', handleKeydown, true);
        if (initialFocus && typeof initialFocus.focus === 'function') {
          initialFocus.focus();
        }
      }

      function handleKeydown(event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeModal();
          return;
        }

        var ctrlOrCmd = event.ctrlKey || event.metaKey;
        if (ctrlOrCmd && (event.key === 'p' || event.key === 'P')) {
          event.preventDefault();

          if (!isPreviewReady) {
            setStatus('Ticket is still loading. Wait for preview, then press Ctrl+P again.');
            return;
          }

          try {
            var printWindow = previewFrame.contentWindow;
            if (!printWindow) {
              throw new Error('Print window unavailable.');
            }
            printWindow.focus();
            printWindow.print();
            setStatus('');
          } catch (err) {
            setStatus('Unable to start print dialog. Press Ctrl+P again after reload.');
          }
          return;
        }

        if (event.key !== 'Tab') {
          return;
        }

        var focusables = getFocusableElements(panel);
        if (!focusables.length) {
          return;
        }

        var first = focusables[0];
        var last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }

      for (var i = 0; i < closeButtons.length; i++) {
        closeButtons[i].addEventListener('click', closeModal);
      }

      previewFrame.addEventListener('load', function() {
        isPreviewReady = true;
        setStatus('Ticket preview ready. Press Ctrl+P to print.');
      });

      previewFrame.addEventListener('error', function() {
        isPreviewReady = false;
        setStatus('Ticket preview failed to load. Close and retry.');
      });

      document.addEventListener('keydown', handleKeydown, true);
      setStatus('Loading ticket preview...');
    }

    for (var i = 0; i < modals.length; i++) {
      initModal(modals[i]);
    }
  })();
</script>
