<?php

$allowed_roles = ['librarian', 'borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/receipts.php';
require_once __DIR__ . '/../includes/csrf.php';

$pdo = get_db();

function sanitize_close_to($value): ?string
{
  $candidate = trim((string) $value);
  if ($candidate === '') {
    return null;
  }

  if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
    return null;
  }

  if (preg_match('/^(?:[a-z][a-z0-9+\-.]*:|\/\/|\\\\)/i', $candidate) === 1) {
    return null;
  }

  $parts = parse_url($candidate);
  if ($parts === false) {
    return null;
  }

  if (isset($parts['scheme']) || isset($parts['host'])) {
    return null;
  }

  $path = (string) ($parts['path'] ?? '');
  if ($path === '' || strpos($candidate, '//') !== false) {
    return null;
  }

  return $candidate;
}

function resolve_close_href(string $base_url, ?string $close_to): string
{
  if ($close_to === null || $close_to === '') {
    return '';
  }

  if (strpos($close_to, '/') === 0) {
    return $close_to;
  }

  return $base_url . ltrim($close_to, '/');
}

$receipt_no = trim((string) ($_GET['no'] ?? ''));
if ($receipt_no === '') {
  http_response_code(400);
  exit('Missing receipt number.');
}

$receipt = get_receipt_ticket_by_number($pdo, $receipt_no);
if (!$receipt) {
  http_response_code(404);
  exit('Receipt not found.');
}

$viewer_id = (int) $_SESSION['user_id'];
$viewer_role = (string) $_SESSION['role'];

if (!can_access_receipt_ticket($receipt, $viewer_id, $viewer_role)) {
  http_response_code(403);
  require_once __DIR__ . '/../403.php';
  exit;
}

$compact = ((string) ($_GET['compact'] ?? '0')) === '1';
$model = build_receipt_view_model($pdo, $receipt, true);
$qr_payload = (string) $model['qr_payload'];
$base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';
$close_to = sanitize_close_to($_GET['close_to'] ?? '');
$close_href = resolve_close_href($base_url, $close_to);
$close_fallback = $base_url . 'receipt/index.php';
$should_autofocus_close = $close_to !== null || ((string) ($_GET['autofocus_close'] ?? '0')) === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $reason = trim((string) ($_POST['reprint_reason'] ?? ''));

  try {
    request_receipt_reprint($pdo, $receipt, $viewer_id, $viewer_role, $reason, [
      'target' => 'browser_print',
      'channel' => 'web',
    ]);
    $_SESSION['flash_success'] = 'Reprint request logged.';
  } catch (InvalidArgumentException $e) {
    $_SESSION['flash_error'] = $e->getMessage();
  } catch (Throwable $e) {
    error_log('[receipt/view] reprint failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Reprint request failed.';
  }

  $redirect_params = ['no' => $receipt_no];
  if ($close_to !== null) {
    $redirect_params['close_to'] = $close_to;
  }
  if ($should_autofocus_close) {
    $redirect_params['autofocus_close'] = '1';
  }
  header('Location: ' . $base_url . 'receipt/view.php?' . http_build_query($redirect_params));
  exit;
}

$event = (string) ($_GET['event'] ?? 'view');
$event = $event === 'reprint' ? 'reprint' : 'view';
log_receipt_ticket_event($pdo, (int) $receipt['id'], $viewer_id, $viewer_role, $event, [
  'source' => 'receipt/view.php',
  'ua'     => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
  'compact' => $compact ? '1' : '0',
], [
  'channel' => 'web',
]);

$flash_error = (string) ($_SESSION['flash_error'] ?? '');
$flash_success = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

function esc($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$format = (string) ($model['format'] ?? 'thermal');
$section_class = 'section-' . preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($model['section'] ?? 'details')));
$reprint_action_params = ['no' => $receipt_no];
if ($close_to !== null) {
  $reprint_action_params['close_to'] = $close_to;
}
if ($should_autofocus_close) {
  $reprint_action_params['autofocus_close'] = '1';
}
$reprint_action_url = $base_url . 'receipt/view.php?' . http_build_query($reprint_action_params);

$pageTitle = 'Receipt ' . (string) ($model['receipt_no'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($base_url . 'assets/css/libris.css', ENT_QUOTES, 'UTF-8') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Outfit', system-ui, sans-serif;
      background: #0f0e0c;
      color: #ecebdf;
      margin: 0;
      min-height: 100vh;
    }
    body::before {
      content: ''; position: fixed; top: -50%; left: -50%; width: 200%; height: 200%;
      background: radial-gradient(circle at 50% 50%, rgba(201, 168, 76, 0.08) 0%, transparent 60%),
                  radial-gradient(circle at 80% 20%, rgba(201, 168, 76, 0.05) 0%, transparent 40%);
      z-index: -1; pointer-events: none;
    }
    .ticket-wrap { max-width: 860px; margin: 30px auto; padding: 0 16px; }
    .ticket {
      background: rgba(255, 255, 255, 0.03);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 14px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
      padding: 22px;
      position: relative;
      overflow: hidden;
      color: #ecebdf;
    }
    .ticket::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 4px;
      background: linear-gradient(90deg, #c8401a, #c9a84c, #4a6741);
    }
    .ticket.thermal { max-width: 460px; margin: 0 auto; }
    .ticket.a4 { max-width: 820px; margin: 0 auto; }
    .head {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      border-bottom: 1px dashed rgba(255, 255, 255, 0.1);
      padding-bottom: 12px;
      margin-bottom: 14px;
    }
    .title {
      margin: 0;
      font-size: 1.45rem;
      line-height: 1.2;
      letter-spacing: 0.01em;
      color: #c9a84c;
    }
    .sub {
      margin: 4px 0 0 0;
      font-size: 0.86rem;
      color: rgba(255,255,255,0.6);
      letter-spacing: 0.04em;
      text-transform: uppercase;
      font-weight: 600;
    }
    .meta { text-align: right; font-size: 0.88rem; color: rgba(255,255,255,0.5); }
    .meta div { margin-bottom: 3px; }
    .meta strong { color: #ecebdf; font-weight: 600; }
    .section-label {
      margin: 16px 0 8px;
      font-size: 0.78rem;
      letter-spacing: 0.09em;
      color: #c9a84c;
      text-transform: uppercase;
      font-weight: 600;
    }
    .line-grid { width: 100%; border-collapse: collapse; }
    .line-grid td {
      padding: 8px 0;
      vertical-align: top;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      font-size: 0.95rem;
    }
    .line-grid td:first-child { width: 35%; color: rgba(255,255,255,0.5); }
    .qr {
      margin-top: 14px;
      padding: 10px;
      border: 1px dashed rgba(255,255,255,0.2);
      border-radius: 8px;
      background: rgba(255,255,255,0.05);
      font-family: var(--font-mono, "DM Mono", "Courier New", monospace);
      font-size: 0.83rem;
      color: #c9a84c;
      word-break: break-all;
    }
    .actions { margin: 14px 0 0; display: flex; gap: 8px; flex-wrap: wrap; }
    .btn {
      display: inline-block;
      text-decoration: none;
      padding: 8px 12px;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      background: rgba(201, 168, 76, 0.1);
      border: 1px solid rgba(201, 168, 76, 0.3);
      color: #c9a84c;
      transition: all 0.2s;
    }
    .btn:hover { background: rgba(201, 168, 76, 0.2); }
    .btn.secondary {
      background: rgba(255, 255, 255, 0.05);
      border-color: rgba(255, 255, 255, 0.1);
      color: #ecebdf;
    }
    .btn.secondary:hover { background: rgba(255, 255, 255, 0.1); }
    .flash { border-radius: 8px; margin: 0 0 12px 0; padding: 10px 12px; font-size: 0.9rem; }
    .flash.error { border: 1px solid rgba(239, 68, 68, 0.3); background: rgba(239, 68, 68, 0.1); color: #ef4444; }
    .flash.success { border: 1px solid rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.1); color: #10b981; }
    .reprint-form { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
    .reprint-form input {
      flex: 1;
      min-width: 240px;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 8px;
      padding: 8px 10px;
      font-size: 0.9rem;
      background: rgba(255,255,255,0.05);
      color: #ecebdf;
    }
    .reprint-form input:focus { outline: none; border-color: #c9a84c; }
    .ticket.thermal .head { flex-direction: column; }
    .ticket.thermal .meta { text-align: left; }
    .ticket.thermal .line-grid td:first-child { width: 42%; }
    .section-checkout .sub::after { content: " • CHECKOUT"; }
    .section-checkin .sub::after { content: " • RETURN"; }
    .section-payment .sub::after { content: " • PAYMENT"; }
    .section-reservation .sub::after { content: " • RESERVATION"; }
    .section-visitor .sub::after { content: " • VISITOR PASS"; }
    
    @media print {
      body { background: #fff; color: #000; min-height: auto; }
      body::before { display: none; }
      .ticket-wrap { margin: 0; padding: 0; max-width: none; }
      .ticket { 
        background: #fff; 
        border: none; 
        border-radius: 0; 
        color: #000; 
        box-shadow: none; 
        backdrop-filter: none; 
        -webkit-backdrop-filter: none; 
      }
      .ticket::before { display: none; }
      .head { border-bottom: 1px dashed #ccc; }
      .title { color: #000; }
      .sub { color: #555; }
      .meta { color: #555; }
      .meta strong { color: #000; }
      .section-label { color: #000; }
      .line-grid td { border-bottom: 1px solid #ccc; }
      .line-grid td:first-child { color: #333; }
      .qr { background: #fff; border: 1px dashed #000; color: #000; }
      .actions { display: none; }
      .reprint-form { display: none; }
      .flash { display: none; }
    }
  </style>
</head>
<body>
  <div class="ticket-wrap">
    <?php if ($flash_error !== ''): ?>
      <div class="flash error" role="alert"><?= esc($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_success !== ''): ?>
      <div class="flash success" role="status"><?= esc($flash_success) ?></div>
    <?php endif; ?>

    <article class="ticket <?= esc($format) ?> <?= esc($section_class) ?>" aria-label="Receipt ticket">
      <header class="head">
        <div>
          <h1 class="title"><?= esc($model['library_label'] ?? 'Library System') ?></h1>
          <p class="sub"><?= esc($model['type_label'] ?? 'Transaction Ticket') ?></p>
        </div>
        <div class="meta">
          <div><strong>Receipt #:</strong> <?= esc($model['receipt_no'] ?? '') ?></div>
          <div><strong>Issued:</strong> <?= esc($model['issued_at_formatted'] ?? '') ?></div>
          <div><strong>Type:</strong> <?= esc($model['type'] ?? 'transaction') ?></div>
          <div><strong>Status:</strong> <?= esc($model['status'] ?? 'issued') ?></div>
          <div><strong>Format:</strong> <?= esc($model['format'] ?? 'thermal') ?></div>
        </div>
      </header>

      <div class="section-label">Patron</div>
      <table class="line-grid" role="presentation">
        <tr>
          <td>Name</td>
          <td><?= esc((string) (($model['patron']['name'] ?? 'N/A'))) ?></td>
        </tr>
        <tr>
          <td>Email</td>
          <td><?= esc((string) (($model['patron']['email'] ?? 'N/A'))) ?></td>
        </tr>
        <tr>
          <td>Borrower ID</td>
          <td><?= (int) (($model['patron']['id'] ?? 0)) ?></td>
        </tr>
      </table>

      <div class="section-label">Details</div>
      <table class="line-grid" role="presentation">
        <?php foreach (($model['details'] ?? []) as $line): ?>
          <tr>
            <td><?= esc($line[0]) ?></td>
            <td><?= esc($line[1]) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <div class="section-label">QR Payload</div>
      <div class="qr"><?= esc($qr_payload) ?></div>

      <div class="section-label">Integrity</div>
      <div class="qr">hash: <?= esc((string) ($model['payload_hash'] ?? '')) ?><br>sig: <?= esc(substr((string) ($model['payload_signature'] ?? ''), 0, 24)) ?>...</div>

      <?php if (!$compact): ?>
        <div class="actions">
          <button
            class="btn secondary"
            type="button"
            id="close-receipt-btn"
            data-close-to="<?= esc($close_href) ?>"
            data-fallback="<?= esc($close_fallback) ?>"
            aria-label="Close Receipt"
            <?= $should_autofocus_close ? 'autofocus data-autofocus="1"' : '' ?>
          >Close Receipt</button>
          <button class="btn" type="button" onclick="window.print()">Print Ticket</button>
          <a class="btn secondary" href="<?= esc(receipt_kiosk_url($receipt)) ?>" target="_blank" rel="noopener">Open Kiosk Print View</a>
          <a class="btn secondary" href="<?= esc($base_url . 'api/receipts/pdf.php?no=' . rawurlencode($receipt_no)) ?>" target="_blank" rel="noopener">PDF Fallback Info</a>
        </div>

        <form method="post" class="reprint-form" action="<?= esc($reprint_action_url) ?>">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="text" name="reprint_reason" required minlength="3" maxlength="255" placeholder="Reprint reason (required for audit)">
          <button class="btn secondary" type="submit">Log Reprint</button>
        </form>
      <?php endif; ?>
    </article>
  </div>
  <script>
    (function() {
      var closeButton = document.getElementById('close-receipt-btn');
      if (!closeButton) {
        return;
      }

      var closeTo = closeButton.getAttribute('data-close-to') || '';
      var fallback = closeButton.getAttribute('data-fallback') || '/receipt/index.php';

      function closeReceipt() {
        if (closeTo !== '') {
          window.location.assign(closeTo);
          return;
        }

        if (window.history && window.history.length > 1) {
          window.history.back();
          window.setTimeout(function() {
            window.location.assign(fallback);
          }, 250);
          return;
        }

        window.location.assign(fallback);
      }

      closeButton.addEventListener('click', function(event) {
        event.preventDefault();
        closeReceipt();
      });

      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
          closeReceipt();
        }
      });

      if (closeButton.hasAttribute('data-autofocus')) {
        window.setTimeout(function() {
          closeButton.focus();
        }, 0);
      }
    })();
  </script>
</body>
</html>