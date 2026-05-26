<?php

/**
 * librarian/borrowers.php - Borrower-only user management for librarians.
 */

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/pagination.php';

$pdo = get_db();
$actorId = (int) ($_SESSION['user_id'] ?? 0);
$selfUrl = BASE_URL . 'librarian/borrowers.php';

function librarian_table_exists(PDO $pdo, string $table): bool
{
  static $cache = [];
  if (isset($cache[$table])) {
    return $cache[$table];
  }
  if (!preg_match('/\A[A-Za-z0-9_]+\z/', $table)) {
    $cache[$table] = false;
    return false;
  }
  $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $table);
  $stmt = $pdo->query("SHOW TABLES LIKE '{$escaped}'");
  $cache[$table] = $stmt->fetch() !== false;
  return $cache[$table];
}

function librarian_flash(string $type, string $message): void
{
  $_SESSION['flash_' . $type] = $message;
}

function librarian_redirect(string $url): void
{
  header('Location: ' . $url);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $action = (string) ($_POST['action'] ?? '');
  $userId = (int) ($_POST['user_id'] ?? 0);

  if ($userId < 1 || !in_array($action, ['update_info', 'reset_password', 'delete_borrower'], true)) {
    librarian_flash('error', 'Invalid borrower management request.');
    librarian_redirect($selfUrl);
  }

  if ($action === 'update_info') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      librarian_flash('error', 'Enter a valid borrower name and email.');
      librarian_redirect($selfUrl);
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, role FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$userId]);
      $target = $stmt->fetch();

      if (!$target || (string) $target['role'] !== 'borrower') {
        $pdo->rollBack();
        librarian_flash('error', 'Only borrower accounts can be edited here.');
        librarian_redirect($selfUrl);
      }

      $dupStmt = $pdo->prepare('SELECT id FROM Users WHERE email = ? AND id <> ? LIMIT 1');
      $dupStmt->execute([$email, $userId]);
      if ($dupStmt->fetch()) {
        $pdo->rollBack();
        librarian_flash('error', 'A user with that email already exists.');
        librarian_redirect($selfUrl);
      }

      $upd = $pdo->prepare('UPDATE Users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ? AND role = ? LIMIT 1');
      $upd->execute([$fullName, $email, $userId, 'borrower']);

      log_event($pdo, 'borrower_info_update', $actorId, 'Users', $userId, 'success', 'librarian', $email);
      $pdo->commit();

      librarian_flash('success', 'Borrower information updated.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[librarian/borrowers.php] update_info failed: ' . $e->getMessage());
      librarian_flash('error', 'Unable to update borrower information right now.');
    }

    librarian_redirect($selfUrl);
  }

  if ($action === 'reset_password') {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($newPassword === '' || $confirmPassword === '' || strlen($newPassword) < 8 || !hash_equals($newPassword, $confirmPassword)) {
      librarian_flash('error', 'Password must match and be at least 8 characters.');
      librarian_redirect($selfUrl);
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, role, password_hash, email FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$userId]);
      $target = $stmt->fetch();

      if (!$target || (string) $target['role'] !== 'borrower') {
        $pdo->rollBack();
        librarian_flash('error', 'Only borrower passwords can be changed here.');
        librarian_redirect($selfUrl);
      }

      $currentHash = (string) ($target['password_hash'] ?? '');
      if ($currentHash !== '' && password_verify($newPassword, $currentHash)) {
        $pdo->rollBack();
        librarian_flash('error', 'New password must be different from the current password.');
        librarian_redirect($selfUrl);
      }

      $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
      $upd = $pdo->prepare('UPDATE Users SET password_hash = ?, updated_at = NOW() WHERE id = ? AND role = ? LIMIT 1');
      $upd->execute([$newHash, $userId, 'borrower']);

      log_event($pdo, 'borrower_password_reset', $actorId, 'Users', $userId, 'success', 'librarian', (string) $target['email']);
      $pdo->commit();

      librarian_flash('success', 'Borrower password updated.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[librarian/borrowers.php] reset_password failed: ' . $e->getMessage());
      librarian_flash('error', 'Unable to update borrower password right now.');
    }

    librarian_redirect($selfUrl);
  }

  if ($action === 'delete_borrower') {
    $deleteConfirm = (string) ($_POST['delete_confirm'] ?? '0');
    if ($deleteConfirm !== '1') {
      librarian_flash('error', 'Please confirm permanent deletion before continuing.');
      librarian_redirect($selfUrl);
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, role, email FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$userId]);
      $target = $stmt->fetch();

      if (!$target || (string) $target['role'] !== 'borrower') {
        $pdo->rollBack();
        librarian_flash('error', 'Only borrower accounts can be deleted here.');
        librarian_redirect($selfUrl);
      }

      expire_stale_reservations($pdo);

      $depStmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\')) AS open_loan_count,
            (SELECT COUNT(*) FROM Reservations WHERE user_id = ? AND status IN (\'pending\', \'approved\')) AS open_reservation_count'
      );
      $depStmt->execute([$userId, $userId]);
      $dep = $depStmt->fetch() ?: ['open_loan_count' => 0, 'open_reservation_count' => 0];

      if ((int) $dep['open_loan_count'] > 0 || (int) $dep['open_reservation_count'] > 0) {
        $pdo->rollBack();
        librarian_flash('error', 'Close active loans and open reservations before deleting this borrower.');
        librarian_redirect($selfUrl);
      }

      if (librarian_table_exists($pdo, 'Receipt_Print_Jobs') && librarian_table_exists($pdo, 'Receipt_Tickets')) {
        $deletePrintJobs = $pdo->prepare(
          'DELETE FROM Receipt_Print_Jobs
            WHERE actor_user_id = ?
               OR receipt_id IN (
                    SELECT id FROM Receipt_Tickets
                     WHERE actor_user_id = ? OR patron_user_id = ?
                  )'
        );
        $deletePrintJobs->execute([$userId, $userId, $userId]);
      }

      if (librarian_table_exists($pdo, 'Receipt_Ticket_Logs') && librarian_table_exists($pdo, 'Receipt_Tickets')) {
        $deleteReceiptLogs = $pdo->prepare(
          'DELETE FROM Receipt_Ticket_Logs
            WHERE actor_user_id = ?
               OR receipt_id IN (
                    SELECT id FROM Receipt_Tickets
                     WHERE actor_user_id = ? OR patron_user_id = ?
                  )'
        );
        $deleteReceiptLogs->execute([$userId, $userId, $userId]);
      }

      if (librarian_table_exists($pdo, 'Receipt_Tickets')) {
        $deleteReceipts = $pdo->prepare('DELETE FROM Receipt_Tickets WHERE actor_user_id = ? OR patron_user_id = ?');
        $deleteReceipts->execute([$userId, $userId]);
      }

      $deleteClosedReservations = $pdo->prepare(
        'DELETE FROM Reservations WHERE user_id = ? AND status NOT IN (\'pending\', \'approved\')'
      );
      $deleteClosedReservations->execute([$userId]);

      $deleteClosedCirculation = $pdo->prepare(
        'DELETE FROM Circulation WHERE user_id = ? AND status NOT IN (\'active\', \'overdue\')'
      );
      $deleteClosedCirculation->execute([$userId]);

      $clearLogs = $pdo->prepare('UPDATE System_Logs SET actor_id = NULL WHERE actor_id = ?');
      $clearLogs->execute([$userId]);

      $del = $pdo->prepare('DELETE FROM Users WHERE id = ? AND role = ? LIMIT 1');
      $del->execute([$userId, 'borrower']);

      log_event($pdo, 'borrower_delete', $actorId, 'Users', $userId, 'success', 'librarian', (string) $target['email']);
      $pdo->commit();

      librarian_flash('success', 'Borrower account deleted.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[librarian/borrowers.php] delete_borrower failed: ' . $e->getMessage());
      librarian_flash('error', 'Unable to delete borrower. The account may still be linked to protected records.');
    }

    librarian_redirect($selfUrl);
  }
}

$q = trim((string) ($_GET['q'] ?? ''));
$q = preg_replace('/\s+/u', ' ', $q) ?? $q;
$statusFilter = (string) ($_GET['status'] ?? '');
$loanFilter = (string) ($_GET['loans'] ?? '');
$reservationFilter = (string) ($_GET['reservations'] ?? '');
$fineFilter = (string) ($_GET['fines'] ?? '');

if (!in_array($statusFilter, ['', 'verified', 'unverified', 'suspended'], true)) {
  $statusFilter = '';
}
if (!in_array($loanFilter, ['', 'active', 'none'], true)) {
  $loanFilter = '';
}
if (!in_array($reservationFilter, ['', 'open', 'none'], true)) {
  $reservationFilter = '';
}
if (!in_array($fineFilter, ['', 'unpaid', 'none'], true)) {
  $fineFilter = '';
}

$where = ['u.role = ?'];
$params = ['borrower'];
if ($q !== '') {
  $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}
if ($statusFilter === 'verified') {
  $where[] = 'u.is_verified = 1 AND u.is_suspended = 0';
} elseif ($statusFilter === 'unverified') {
  $where[] = 'u.is_verified = 0 AND u.is_suspended = 0';
} elseif ($statusFilter === 'suspended') {
  $where[] = 'u.is_suspended = 1';
}
if ($loanFilter === 'active') {
  $where[] = 'EXISTS (SELECT 1 FROM Circulation c WHERE c.user_id = u.id AND c.status IN (\'active\', \'overdue\'))';
} elseif ($loanFilter === 'none') {
  $where[] = 'NOT EXISTS (SELECT 1 FROM Circulation c WHERE c.user_id = u.id AND c.status IN (\'active\', \'overdue\'))';
}
if ($reservationFilter === 'open') {
  $where[] = 'EXISTS (SELECT 1 FROM Reservations r WHERE r.user_id = u.id AND r.status IN (\'pending\', \'approved\'))';
} elseif ($reservationFilter === 'none') {
  $where[] = 'NOT EXISTS (SELECT 1 FROM Reservations r WHERE r.user_id = u.id AND r.status IN (\'pending\', \'approved\'))';
}
if ($fineFilter === 'unpaid') {
  $where[] = 'EXISTS (SELECT 1 FROM Circulation c WHERE c.user_id = u.id AND c.fine_paid = 0 AND c.fine_amount > 0)';
} elseif ($fineFilter === 'none') {
  $where[] = 'NOT EXISTS (SELECT 1 FROM Circulation c WHERE c.user_id = u.id AND c.fine_paid = 0 AND c.fine_amount > 0)';
}

$whereSql = ' WHERE ' . implode(' AND ', $where);
$hasFilters = $q !== '' || $statusFilter !== '' || $loanFilter !== '' || $reservationFilter !== '' || $fineFilter !== '';

$borrowersPerPage = 25;
$requestedPage = max(1, (int) ($_GET['page'] ?? 1));

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM Users u' . $whereSql);
$countStmt->execute($params);
$totalBorrowers = (int) $countStmt->fetchColumn();
$borrowersTotalPages = max(1, (int) ceil($totalBorrowers / $borrowersPerPage));
$borrowersPage = pagination_clamp_page($requestedPage, $borrowersTotalPages);
$borrowersOffset = ($borrowersPage - 1) * $borrowersPerPage;
[$borrowersRangeStart, $borrowersRangeEnd] = pagination_result_range($totalBorrowers, $borrowersOffset, $borrowersPerPage);
$borrowerPageWindow = pagination_page_window($borrowersPage, $borrowersTotalPages);

$borrowerPageParams = [
  'q' => $q,
  'status' => $statusFilter,
  'loans' => $loanFilter,
  'reservations' => $reservationFilter,
  'fines' => $fineFilter,
];
$borrowerPageUrl = static function (int $targetPage) use ($selfUrl, $borrowerPageParams): string {
  return pagination_build_url($selfUrl, $borrowerPageParams, 'page', $targetPage);
};

$stmt = $pdo->prepare(
  'SELECT u.id, u.full_name, u.email, u.is_verified, u.is_suspended, u.created_at,
          (SELECT COUNT(*) FROM Circulation c WHERE c.user_id = u.id AND c.status IN (\'active\', \'overdue\')) AS active_loans,
          (SELECT COUNT(*) FROM Reservations r WHERE r.user_id = u.id AND r.status IN (\'pending\', \'approved\')) AS open_reservations,
          (SELECT COALESCE(SUM(c2.fine_amount), 0) FROM Circulation c2 WHERE c2.user_id = u.id AND c2.fine_paid = 0) AS unpaid_fines
     FROM Users u' . $whereSql . '
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT ? OFFSET ?'
);
foreach ($params as $index => $value) {
  $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(count($params) + 1, $borrowersPerPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $borrowersOffset, PDO::PARAM_INT);
$stmt->execute();
$borrowers = $stmt->fetchAll();

$flashError = (string) ($_SESSION['flash_error'] ?? '');
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$current_page = 'librarian.borrowers';
$pageTitle = 'User Management | LibraLink';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
  BASE_URL . 'assets/css/librarian-redesign.css'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body class="librarian-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-librarian.php'; ?>
    <main class="main-content librarian-borrowers-page">
      <div class="page-header">
        <div>
          <h1>User Management</h1>
          <p>View and manage borrower accounts only.</p>
        </div>
      </div>

      <?php if ($flashError !== ''): ?>
        <div class="flash flash-error" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flashSuccess !== ''): ?>
        <div class="flash flash-success" role="status"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form class="cat-toolbar" method="get" action="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>">
        <div class="cat-toolbar__search-wrap">
          <span class="cat-toolbar__search-icon" aria-hidden="true">&#128269;</span>
          <input class="cat-toolbar__search" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search name or email">
        </div>
        <select class="cat-toolbar__select" name="status" aria-label="Filter by borrower status">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All Status</option>
          <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>Verified</option>
          <option value="unverified" <?= $statusFilter === 'unverified' ? 'selected' : '' ?>>Unverified</option>
          <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
        </select>
        <select class="cat-toolbar__select" name="loans" aria-label="Filter by active loans">
          <option value="" <?= $loanFilter === '' ? 'selected' : '' ?>>All Loans</option>
          <option value="active" <?= $loanFilter === 'active' ? 'selected' : '' ?>>Has Active Loans</option>
          <option value="none" <?= $loanFilter === 'none' ? 'selected' : '' ?>>No Active Loans</option>
        </select>
        <select class="cat-toolbar__select" name="reservations" aria-label="Filter by open reservations">
          <option value="" <?= $reservationFilter === '' ? 'selected' : '' ?>>All Reservations</option>
          <option value="open" <?= $reservationFilter === 'open' ? 'selected' : '' ?>>Has Open Reservations</option>
          <option value="none" <?= $reservationFilter === 'none' ? 'selected' : '' ?>>No Open Reservations</option>
        </select>
        <select class="cat-toolbar__select" name="fines" aria-label="Filter by unpaid fines">
          <option value="" <?= $fineFilter === '' ? 'selected' : '' ?>>All Fines</option>
          <option value="unpaid" <?= $fineFilter === 'unpaid' ? 'selected' : '' ?>>Has Unpaid Fines</option>
          <option value="none" <?= $fineFilter === 'none' ? 'selected' : '' ?>>No Unpaid Fines</option>
        </select>
        <button class="btn-primary" type="submit">Search</button>
        <?php if ($hasFilters): ?>
          <a class="btn-ghost" href="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>">Reset</a>
        <?php endif; ?>
      </form>
      <div class="cat-count" aria-live="polite">
        Showing <?= number_format($borrowersRangeStart) ?>-<?= number_format($borrowersRangeEnd) ?>
        of <?= number_format($totalBorrowers) ?> borrower<?= $totalBorrowers === 1 ? '' : 's' ?>
      </div>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Borrowers</span>
          <span class="badge badge-blue"><?= number_format($totalBorrowers) ?></span>
        </div>

        <?php if (empty($borrowers)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#10003;</span>
            <p>No borrowers found.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Borrower</th>
                  <th>Status</th>
                  <th>Loans</th>
                  <th>Reservations</th>
                  <th>Unpaid Fines</th>
                  <th>Joined</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($borrowers as $borrower):
                  $borrowerId = (int) $borrower['id'];
                  $activeLoans = (int) $borrower['active_loans'];
                  $openReservations = (int) $borrower['open_reservations'];
                  $unpaidFines = circulation_currency_symbol($pdo) . number_format((float) $borrower['unpaid_fines'], 2);
                  $canDelete = $activeLoans === 0 && $openReservations === 0;
                  $statusLabel = (int) $borrower['is_suspended'] === 1
                    ? 'Suspended'
                    : ((int) $borrower['is_verified'] === 1 ? 'Verified' : 'Unverified');
                  $statusClass = (int) $borrower['is_suspended'] === 1
                    ? 'badge-red'
                    : ((int) $borrower['is_verified'] === 1 ? 'badge-green' : 'badge-amber');
                  $joinedDate = date('m-d-y', strtotime((string) $borrower['created_at']));
                ?>
                  <tr>
                    <td data-label="Borrower">
                      <div class="borrower-table-person">
                        <strong><?= htmlspecialchars((string) $borrower['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars((string) $borrower['email'], ENT_QUOTES, 'UTF-8') ?></span>
                      </div>
                    </td>
                    <td data-label="Status">
                      <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                    </td>
                    <td data-label="Loans"><?= $activeLoans ?></td>
                    <td data-label="Reservations"><?= $openReservations ?></td>
                    <td data-label="Unpaid Fines"><?= htmlspecialchars($unpaidFines, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Joined"><?= htmlspecialchars($joinedDate, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Actions">
                      <button
                        type="button"
                        class="btn-ghost borrower-manage-trigger"
                        data-user-id="<?= $borrowerId ?>"
                        data-user-name="<?= htmlspecialchars((string) $borrower['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                        data-user-email="<?= htmlspecialchars((string) $borrower['email'], ENT_QUOTES, 'UTF-8') ?>"
                        data-user-status="<?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>"
                        data-user-status-class="<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                        data-user-loans="<?= $activeLoans ?>"
                        data-user-reservations="<?= $openReservations ?>"
                        data-user-fines="<?= htmlspecialchars($unpaidFines, ENT_QUOTES, 'UTF-8') ?>"
                        data-user-joined="<?= htmlspecialchars($joinedDate, ENT_QUOTES, 'UTF-8') ?>"
                        data-can-delete="<?= $canDelete ? '1' : '0' ?>"
                        aria-haspopup="dialog"
                        aria-controls="borrower-manage-panel">
                        Manage
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <nav class="records-pagination" aria-label="Borrower management pagination">
            <div class="records-pagination__page">
              Showing <strong><?= number_format($borrowersRangeStart) ?>-<?= number_format($borrowersRangeEnd) ?></strong>
              of <strong><?= number_format($totalBorrowers) ?></strong>
            </div>
            <div class="records-pagination__nav">
              <a
                class="btn-ghost <?= $borrowersPage <= 1 ? 'records-pagination__disabled' : '' ?>"
                href="<?= htmlspecialchars($borrowerPageUrl($borrowersPage - 1), ENT_QUOTES, 'UTF-8') ?>"
                aria-label="Go to previous borrowers page">Previous</a>

              <?php foreach ($borrowerPageWindow as $pageNumber): ?>
                <?php if ($pageNumber === $borrowersPage): ?>
                  <span class="records-pagination__page" aria-current="page">Page <?= number_format($borrowersPage) ?> of <?= number_format($borrowersTotalPages) ?></span>
                <?php else: ?>
                  <a
                    class="btn-ghost"
                    href="<?= htmlspecialchars($borrowerPageUrl($pageNumber), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="Go to borrowers page <?= (int) $pageNumber ?>"><?= (int) $pageNumber ?></a>
                <?php endif; ?>
              <?php endforeach; ?>

              <a
                class="btn-ghost <?= $borrowersPage >= $borrowersTotalPages ? 'records-pagination__disabled' : '' ?>"
                href="<?= htmlspecialchars($borrowerPageUrl($borrowersPage + 1), ENT_QUOTES, 'UTF-8') ?>"
                aria-label="Go to next borrowers page">Next</a>
            </div>
          </nav>
        <?php endif; ?>
      </div>

      <div class="borrower-panel-overlay" id="borrower-panel-overlay" aria-hidden="true">
        <aside class="borrower-side-panel" id="borrower-manage-panel" role="dialog" aria-modal="true" aria-labelledby="borrower-panel-title">
          <div class="borrower-panel-header">
            <div>
              <h2 id="borrower-panel-title">Manage Borrower</h2>
              <p id="borrower-panel-email"></p>
              <div class="borrower-panel-chips">
                <span class="badge" id="borrower-panel-status"></span>
                <span class="badge badge-blue" id="borrower-panel-loans"></span>
                <span class="badge badge-amber" id="borrower-panel-reservations"></span>
              </div>
              <p class="borrower-panel-meta" id="borrower-panel-meta"></p>
            </div>
            <button type="button" class="borrower-panel-close" id="borrower-panel-close" aria-label="Close borrower management panel">&times;</button>
          </div>

          <div class="borrower-panel-body">
            <section class="borrower-panel-section">
              <h3>Edit Info</h3>
              <form method="post" action="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>" class="borrower-panel-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_info">
                <input type="hidden" name="user_id" id="borrower-edit-id">
                <label class="field-label" for="borrower-edit-name">Full Name</label>
                <input class="field-input" id="borrower-edit-name" type="text" name="full_name" required>
                <label class="field-label" for="borrower-edit-email">Email</label>
                <input class="field-input" id="borrower-edit-email" type="email" name="email" required>
                <button class="btn-confirm" type="submit">Save Changes</button>
              </form>
            </section>

            <section class="borrower-panel-section">
              <h3>Change Password</h3>
              <form method="post" action="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>" class="borrower-panel-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="borrower-password-id">
                <label class="field-label" for="borrower-password-new">New Password</label>
                <input class="field-input" id="borrower-password-new" type="password" name="new_password" minlength="8" required autocomplete="new-password">
                <label class="field-label" for="borrower-password-confirm">Confirm Password</label>
                <input class="field-input" id="borrower-password-confirm" type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
                <button class="btn-ghost" type="submit">Change Password</button>
              </form>
            </section>

            <section class="borrower-panel-section borrower-panel-section--danger">
              <h3>Delete Borrower</h3>
              <p class="borrower-delete-note" id="borrower-delete-note"></p>
              <form method="post" action="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>" class="borrower-panel-form" id="borrower-delete-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_borrower">
                <input type="hidden" name="user_id" id="borrower-delete-id">
                <label class="borrower-delete-confirm">
                  <input type="checkbox" name="delete_confirm" value="1" id="borrower-delete-confirm" required>
                  Confirm permanent deletion
                </label>
                <button class="btn-accent" id="borrower-delete-button" type="submit">Delete Borrower</button>
              </form>
            </section>
          </div>
        </aside>
      </div>
    </main>
  </div>
  <script>
    (() => {
      const overlay = document.getElementById('borrower-panel-overlay');
      const closeButton = document.getElementById('borrower-panel-close');
      const title = document.getElementById('borrower-panel-title');
      const email = document.getElementById('borrower-panel-email');
      const status = document.getElementById('borrower-panel-status');
      const loans = document.getElementById('borrower-panel-loans');
      const reservations = document.getElementById('borrower-panel-reservations');
      const meta = document.getElementById('borrower-panel-meta');
      const editId = document.getElementById('borrower-edit-id');
      const editName = document.getElementById('borrower-edit-name');
      const editEmail = document.getElementById('borrower-edit-email');
      const passwordId = document.getElementById('borrower-password-id');
      const passwordNew = document.getElementById('borrower-password-new');
      const passwordConfirm = document.getElementById('borrower-password-confirm');
      const deleteId = document.getElementById('borrower-delete-id');
      const deleteNote = document.getElementById('borrower-delete-note');
      const deleteConfirm = document.getElementById('borrower-delete-confirm');
      const deleteButton = document.getElementById('borrower-delete-button');

      const closePanel = () => {
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
        passwordNew.value = '';
        passwordConfirm.value = '';
        deleteConfirm.checked = false;
      };

      document.querySelectorAll('.borrower-manage-trigger').forEach((button) => {
        button.addEventListener('click', () => {
          const data = button.dataset;
          title.textContent = data.userName || 'Manage Borrower';
          email.textContent = data.userEmail || '';
          status.textContent = data.userStatus || '';
          status.className = 'badge ' + (data.userStatusClass || 'badge-blue');
          loans.textContent = `${data.userLoans || 0} loans`;
          reservations.textContent = `${data.userReservations || 0} reservations`;
          meta.textContent = `Joined ${data.userJoined || '-'} - Unpaid fines ${data.userFines || '0.00'}`;

          editId.value = data.userId || '';
          editName.value = data.userName || '';
          editEmail.value = data.userEmail || '';
          passwordId.value = data.userId || '';
          deleteId.value = data.userId || '';

          const canDelete = data.canDelete === '1';
          deleteConfirm.disabled = !canDelete;
          deleteButton.disabled = !canDelete;
          deleteNote.textContent = canDelete
            ? 'This permanently removes the borrower account and closed borrower records.'
            : 'Deletion is blocked while this borrower has active loans or open reservations.';

          overlay.classList.add('active');
          overlay.setAttribute('aria-hidden', 'false');
          editName.focus();
        });
      });

      closeButton.addEventListener('click', closePanel);
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
          closePanel();
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('active')) {
          closePanel();
        }
      });
      document.getElementById('borrower-delete-form').addEventListener('submit', (event) => {
        if (!confirm('Delete this borrower account permanently?')) {
          event.preventDefault();
        }
      });
    })();
  </script>
</body>

</html>
