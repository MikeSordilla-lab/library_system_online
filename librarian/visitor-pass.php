<?php

$allowed_roles = ['librarian', 'admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();
$viewer_id = (int) ($_SESSION['user_id'] ?? 0);
$viewer_role = (string) ($_SESSION['role'] ?? '');
$base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $visitor_name = trim((string) ($_POST['visitor_name'] ?? ''));
  $purpose = trim((string) ($_POST['purpose'] ?? 'Library Visit'));
  $valid_hours = (int) ($_POST['valid_hours'] ?? 8);
  $format = strtolower(trim((string) ($_POST['format'] ?? 'thermal')));
  if ($format !== 'a4') {
    $format = 'thermal';
  }

  if ($visitor_name === '') {
    $_SESSION['flash_error'] = 'Visitor name is required.';
    header('Location: ' . $base_url . 'librarian/visitor-pass.php');
    exit;
  }

  if ($valid_hours < 1 || $valid_hours > 72) {
    $valid_hours = 8;
  }

  $valid_until = date('Y-m-d H:i:s', time() + ($valid_hours * 3600));
  $ref_id = (int) floor(time());

  try {
    $receipt = issue_receipt_ticket($pdo, [
      'type' => 'visitor_pass',
      'actor_user_id' => $viewer_id,
      'actor_role' => $viewer_role,
      'patron_user_id' => $viewer_id,
      'reference_table' => 'Visitor_Passes',
      'reference_id' => $ref_id,
      'idempotency_key' => 'visitor_pass:' . $viewer_id . ':' . md5($visitor_name . '|' . $valid_until),
      'format' => $format,
      'channel' => 'visitor_desk',
      'payload' => [
        'visitor_name' => $visitor_name,
        'purpose' => $purpose,
        'valid_until' => $valid_until,
        'issued_by' => (string) ($_SESSION['full_name'] ?? 'Staff'),
      ],
    ]);

    $_SESSION['flash_success'] = 'Visitor pass ticket issued.';
    $receipt_no = (string) ($receipt['receipt_no'] ?? '');
    $close_to = rawurlencode('librarian/visitor-pass.php');
    header('Location: ' . $base_url . 'receipt/view.php?no=' . rawurlencode($receipt_no) . '&close_to=' . $close_to . '&autofocus_close=1');
    exit;
  } catch (Throwable $e) {
    error_log('[visitor-pass.php] ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Failed to issue visitor pass.';
  }

  header('Location: ' . $base_url . 'librarian/visitor-pass.php');
  exit;
}

$flash_error = (string) ($_SESSION['flash_error'] ?? '');
$flash_success = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$current_page = 'librarian.visitor-pass';
$pageTitle = 'Visitor Pass | Library System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
  <div class="app-shell">
    <?php
    if ($viewer_role === 'admin') {
      require_once __DIR__ . '/../includes/sidebar-admin.php';
    } else {
      require_once __DIR__ . '/../includes/sidebar-librarian.php';
    }
    ?>

    <main class="main-content">
      <div class="page-header">
        <h1>Issue Visitor Pass</h1>
        <p>Create a printable visitor ticket for walk-in guests.</p>
      </div>

      <?php if ($flash_error !== ''): ?>
        <div class="flash flash-error" role="alert"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_success !== ''): ?>
        <div class="flash flash-success" role="status"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php
      $receipt_modal_title = 'Visitor pass ticket ready';
      $receipt_modal_message = 'Visitor pass issuance completed successfully. Open the visitor ticket from the actions below.';
      $receipt_modal_view_label = 'Open Visitor Ticket';
      require __DIR__ . '/../includes/receipt-success-modal.php';
      ?>
      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Visitor Details</span>
        </div>
        <div style="padding: var(--space-6)">
          <form method="post" action="<?= htmlspecialchars($base_url . 'librarian/visitor-pass.php', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div style="margin-bottom: var(--space-5)">
              <label class="field-label" for="visitor_name">Visitor name</label>
              <input class="field-input" id="visitor_name" name="visitor_name" type="text" required maxlength="120" placeholder="e.g. Jane Doe">
            </div>

            <div style="margin-bottom: var(--space-5)">
              <label class="field-label" for="purpose">Purpose</label>
              <input class="field-input" id="purpose" name="purpose" type="text" maxlength="160" value="Library Visit">
            </div>

            <div style="margin-bottom: var(--space-5)">
              <label class="field-label" for="valid_hours">Valid for (hours)</label>
              <input class="field-input" id="valid_hours" name="valid_hours" type="number" min="1" max="72" value="8">
            </div>

            <div style="margin-bottom: var(--space-5)">
              <label class="field-label" for="format">Format</label>
              <select class="field-select" id="format" name="format">
                <option value="thermal">Thermal</option>
                <option value="a4">A4</option>
              </select>
            </div>

            <button class="btn-primary" type="submit">Issue Visitor Ticket</button>
          </form>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
