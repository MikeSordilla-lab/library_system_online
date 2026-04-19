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
$receipt_modal_pdf_url = $receipt_modal_base_url . 'api/receipts/pdf.php?no=' . rawurlencode($flash_receipt_no);
$receipt_modal_pdf_filename = 'receipt_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $flash_receipt_no) . '.pdf';

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
      <button
        type="button"
        class="btn-ghost receipt-success-modal__download-btn"
        data-receipt-download-btn
        data-pdf-url="<?= htmlspecialchars($receipt_modal_pdf_url, ENT_QUOTES, 'UTF-8') ?>"
        data-pdf-filename="<?= htmlspecialchars($receipt_modal_pdf_filename, ENT_QUOTES, 'UTF-8') ?>"
      >Download PDF Receipt</button>
      <button type="button" class="btn-ghost receipt-success-modal__retry-btn" data-receipt-download-retry hidden>Retry Download</button>
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
    flex-wrap: wrap;
  }

  .receipt-success-modal__download-btn[disabled],
  .receipt-success-modal__retry-btn[disabled] {
    opacity: 0.6;
    cursor: wait;
  }

  @media (max-width: 640px) {
    .receipt-success-modal__panel {
      padding: 14px;
    }

    .receipt-success-modal__preview-frame {
      min-height: 58vh;
    }

    .receipt-success-modal__actions {
      justify-content: stretch;
    }

    .receipt-success-modal__actions button {
      width: 100%;
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
      var downloadButton = modal.querySelector('[data-receipt-download-btn]');
      var retryButton = modal.querySelector('[data-receipt-download-retry]');
      var closeButtons = modal.querySelectorAll('[data-receipt-modal-close]');
      var initialFocus = document.activeElement;
      var isPreviewReady = false;
      var isDownloading = false;

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

      function parseFilenameFromDisposition(contentDisposition) {
        if (!contentDisposition) {
          return '';
        }

        var utfMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (utfMatch && utfMatch[1]) {
          try {
            return decodeURIComponent(utfMatch[1]).replace(/[\\/:*?"<>|]+/g, '_');
          } catch (_err) {
            return utfMatch[1].replace(/[\\/:*?"<>|]+/g, '_');
          }
        }

        var basicMatch = contentDisposition.match(/filename="?([^";]+)"?/i);
        if (basicMatch && basicMatch[1]) {
          return basicMatch[1].replace(/[\\/:*?"<>|]+/g, '_');
        }

        return '';
      }

      function setDownloadState(state, message) {
        if (downloadButton) {
          var defaultLabel = 'Download PDF Receipt';
          if (state === 'loading') {
            downloadButton.textContent = 'Preparing PDF...';
            downloadButton.disabled = true;
            downloadButton.setAttribute('aria-busy', 'true');
          } else {
            downloadButton.textContent = defaultLabel;
            downloadButton.disabled = false;
            downloadButton.removeAttribute('aria-busy');
          }
        }

        if (retryButton) {
          retryButton.hidden = state !== 'error';
          retryButton.disabled = state === 'loading';
        }

        if (message) {
          setStatus(message);
        }
      }

      function isIOS() {
        var ua = window.navigator.userAgent || '';
        return /iPad|iPhone|iPod/.test(ua) || (ua.indexOf('Mac') >= 0 && 'ontouchend' in document);
      }

      function triggerPdfDownload(blob, suggestedFilename, sourceUrl) {
        var objectUrl = URL.createObjectURL(blob);
        var filename = suggestedFilename || 'receipt.pdf';

        var anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.rel = 'noopener';

        if (!isIOS()) {
          anchor.download = filename;
        } else {
          anchor.target = '_blank';
        }

        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);

        window.setTimeout(function() {
          URL.revokeObjectURL(objectUrl);
        }, 30000);

        if (isIOS() && sourceUrl) {
          window.setTimeout(function() {
            window.open(sourceUrl, '_blank', 'noopener');
          }, 150);
        }
      }

      async function downloadReceiptPdf() {
        if (!downloadButton || isDownloading) {
          return;
        }

        var pdfUrl = downloadButton.getAttribute('data-pdf-url') || '';
        var fallbackFilename = downloadButton.getAttribute('data-pdf-filename') || 'receipt.pdf';
        if (!pdfUrl) {
          setDownloadState('error', 'PDF download URL is unavailable.');
          return;
        }

        isDownloading = true;
        setDownloadState('loading', 'Preparing PDF receipt download...');

        try {
          var response = await fetch(pdfUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/pdf,application/json;q=0.9,*/*;q=0.8'
            }
          });

          if (!response.ok) {
            var failMessage = 'Download failed (' + response.status + ').';
            var contentType = response.headers.get('Content-Type') || '';
            if (contentType.indexOf('application/json') >= 0) {
              var payload = await response.json();
              if (payload && payload.message) {
                failMessage = payload.message;
              }
            }
            throw new Error(failMessage);
          }

          var blob = await response.blob();
          if (!blob || blob.size === 0) {
            throw new Error('Received empty PDF file.');
          }

          var contentDisposition = response.headers.get('Content-Disposition') || '';
          var responseFilename = parseFilenameFromDisposition(contentDisposition);
          var finalFilename = responseFilename || fallbackFilename;

          triggerPdfDownload(blob, finalFilename, pdfUrl);
          setDownloadState('success', 'PDF receipt downloaded successfully.');
        } catch (err) {
          var message = 'Unable to download PDF receipt. Tap Retry.';
          if (err && err.message) {
            message = err.message;
          }
          setDownloadState('error', message);
        } finally {
          isDownloading = false;
          if (downloadButton) {
            downloadButton.disabled = false;
          }
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

      if (downloadButton) {
        downloadButton.addEventListener('click', function(event) {
          event.preventDefault();
          downloadReceiptPdf();
        });
      }

      if (retryButton) {
        retryButton.addEventListener('click', function(event) {
          event.preventDefault();
          downloadReceiptPdf();
        });
      }

      previewFrame.addEventListener('load', function() {
        isPreviewReady = true;
        setStatus('Ticket preview ready. Press Ctrl+P to print or Download PDF Receipt.');
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
