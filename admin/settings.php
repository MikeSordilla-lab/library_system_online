<?php

/**
 * admin/settings.php — Global System Settings Editor (US2, FR-008–FR-011)
 *
 * GET : Display settings form pre-filled with current values.
 * POST: Validate and save changed settings; log each change to System_Logs.
 *
 * Protected: Admin role only.
 * Validated inputs: max_borrow_limit (int ≥ 1), fine_per_day (decimal ≥ 0.00),
 * max_loan_days (int ≥ 1). Zero fine rate is accepted (clarification Q2).
 */

// RBAC guard
$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/csrf.php';

$pdo      = get_db();
$actor_id = (int) $_SESSION['user_id'];

// ---- Flash messages -------------------------------------------------------
$flash_success = '';
$flash_info    = '';

if (!empty($_SESSION['flash_success'])) {
  $flash_success = $_SESSION['flash_success'];
  unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_info'])) {
  $flash_info = $_SESSION['flash_info'];
  unset($_SESSION['flash_info']);
}

// ---- Inline validation errors and user-entered values ---------------------
$errors = [
  'max_borrow_limit'        => '',
  'fine_per_day'            => '',
  'loan_period_days'        => '',
  'reservation_expiry_days' => '',
];

// Load current settings as defaults (overridden by user input on re-render)
$values = [
  'max_borrow_limit'        => get_setting($pdo, 'max_borrow_limit', '3'),
  'fine_per_day'            => get_setting($pdo, 'fine_per_day',     '5.00'),
  'loan_period_days'        => get_setting($pdo, 'loan_period_days', '14'),
  'reservation_expiry_days' => get_setting($pdo, 'reservation_expiry_days', '7'),
];

// ---- POST Handler (T008) --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  csrf_verify();

  // Capture raw user input for re-render on validation failure
  $raw = [
    'max_borrow_limit'        => $_POST['max_borrow_limit'] ?? '',
    'fine_per_day'            => $_POST['fine_per_day']     ?? '',
    'loan_period_days'        => $_POST['loan_period_days'] ?? '',
    'reservation_expiry_days' => $_POST['reservation_expiry_days'] ?? '',
  ];
  $values = $raw; // will be overwritten on success

  // Validate
  $max_borrow = (int) $raw['max_borrow_limit'];
  $fine_rate  = round((float) $raw['fine_per_day'], 2);
  $loan_days  = (int) $raw['loan_period_days'];
  $res_expiry = (int) $raw['reservation_expiry_days'];

  if ($max_borrow < 1) {
    $errors['max_borrow_limit'] = 'Max borrow limit must be at least 1.';
  }
  if ($fine_rate < 0.00) {
    $errors['fine_per_day'] = 'Fine per day cannot be negative.';
  }
  if ($loan_days < 1) {
    $errors['loan_period_days'] = 'Loan period must be at least 1 day.';
  }
  if ($res_expiry < 1) {
    $errors['reservation_expiry_days'] = 'Reservation expiry must be at least 1 day.';
  }

  // If no validation errors, save
  if ($errors['max_borrow_limit'] === '' && $errors['fine_per_day'] === '' && $errors['loan_period_days'] === '' && $errors['reservation_expiry_days'] === '') {

    $new_values = [
      'max_borrow_limit'        => (string) $max_borrow,
      'fine_per_day'            => number_format($fine_rate, 2, '.', ''),
      'loan_period_days'        => (string) $loan_days,
      'reservation_expiry_days' => (string) $res_expiry,
    ];

    try {
      $pdo->beginTransaction();

      $changed = 0;
      foreach ($new_values as $key => $new_val) {
        $old_val = get_setting($pdo, $key, '');
        if ($new_val !== $old_val) {
          save_setting($pdo, $key, $new_val, $actor_id, 'admin');
          $changed++;
        }
      }

      $pdo->commit();

      if ($changed === 0) {
        $_SESSION['flash_info'] = 'No changes were made.';
      } else {
        $_SESSION['flash_success'] = 'Settings updated successfully.';
      }
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/settings.php] POST DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'A server error occurred. Please try again.';
    }

    header('Location: ' . BASE_URL . 'admin/settings.php');
    exit;
  }
  // If there were errors, fall through to render the form with $errors and $values
}

$dashboard_url = htmlspecialchars(BASE_URL . 'admin/index.php',    ENT_QUOTES, 'UTF-8');
$logout_url    = htmlspecialchars(BASE_URL . 'logout.php',          ENT_QUOTES, 'UTF-8');
$self_url      = htmlspecialchars(BASE_URL . 'admin/settings.php',  ENT_QUOTES, 'UTF-8');
$current_page = 'admin.settings';
$pageTitle    = 'Global Settings | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>
    <main class="main-content admin-settings-page">
      <div class="page-header">
        <h1>Global Settings</h1>
      </div>

      <?php if ($flash_success !== ''): ?>
        <div class="flash-success"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_info !== ''): ?>
        <div class="flash-info"><?= htmlspecialchars($flash_info, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Operational Settings</span>
        </div>
        <form method="post" action="<?= $self_url ?>" class="admin-settings-form">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

          <div class="admin-settings-field">
            <label class="field-label" for="max_borrow_limit">Max Borrow Limit</label>
            <input class="field-input" type="number" id="max_borrow_limit" name="max_borrow_limit" min="1"
              value="<?= htmlspecialchars($values['max_borrow_limit'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($errors['max_borrow_limit'] !== ''): ?>
              <div class="flash-error admin-settings-field-error"><?= htmlspecialchars($errors['max_borrow_limit'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
              <p class="admin-settings-field-help">Maximum number of books a Borrower can have checked out at once (minimum 1).</p>
            <?php endif; ?>
          </div>

          <div class="admin-settings-field">
            <label class="field-label" for="fine_per_day">Fine Per Day</label>
            <input class="field-input" type="number" id="fine_per_day" name="fine_per_day" min="0" step="0.01"
              value="<?= htmlspecialchars($values['fine_per_day'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($errors['fine_per_day'] !== ''): ?>
              <div class="flash-error admin-settings-field-error"><?= htmlspecialchars($errors['fine_per_day'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
              <p class="admin-settings-field-help">Daily overdue fine per book. Zero is accepted (no-fine policy).</p>
            <?php endif; ?>
          </div>

          <div class="admin-settings-field">
            <label class="field-label" for="loan_period_days">Loan Period (Days)</label>
            <input class="field-input" type="number" id="loan_period_days" name="loan_period_days" min="1"
              value="<?= htmlspecialchars($values['loan_period_days'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($errors['loan_period_days'] !== ''): ?>
              <div class="flash-error admin-settings-field-error"><?= htmlspecialchars($errors['loan_period_days'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
              <p class="admin-settings-field-help">Default loan duration in days applied to every new checkout (minimum 1).</p>
            <?php endif; ?>
          </div>

          <div class="admin-settings-field">
            <label class="field-label" for="reservation_expiry_days">Reservation Expiry (Days)</label>
            <input class="field-input" type="number" id="reservation_expiry_days" name="reservation_expiry_days" min="1"
              value="<?= htmlspecialchars($values['reservation_expiry_days'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($errors['reservation_expiry_days'] !== ''): ?>
              <div class="flash-error admin-settings-field-error"><?= htmlspecialchars($errors['reservation_expiry_days'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
              <p class="admin-settings-field-help">How many days a book remains reserved for a user before expiring (minimum 1).</p>
            <?php endif; ?>
          </div>

          <button type="submit" class="btn-primary">Save Settings</button>
        </form>
      </div>
    </main>
  </div>
</body>

</html>
