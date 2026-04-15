<?php

/**
 * librarian/reservations.php — Librarian Reservation Queue Management
 *
 * GET: list pending reservations in FIFO order.
 * POST: approve/reject a pending reservation with race-safe transitions.
 */

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $reservationId = (int) ($_POST['reservation_id'] ?? 0);
  $action = (string) ($_POST['action'] ?? '');
  $actorId = (int) ($_SESSION['user_id'] ?? 0);
  $actorRole = (string) ($_SESSION['role'] ?? '');

  if ($reservationId < 1 || !in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['flash_error'] = 'Invalid reservation action request.';
    header('Location: ' . BASE_URL . 'librarian/reservations.php');
    exit;
  }

  $pdo->beginTransaction();

  try {
    expire_stale_reservations($pdo);

    $lockStmt = $pdo->prepare(
      'SELECT id, status
         FROM Reservations
        WHERE id = ?
        FOR UPDATE'
    );
    $lockStmt->execute([$reservationId]);
    $reservation = $lockStmt->fetch();

    if (!$reservation) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'Reservation not found.';
      header('Location: ' . BASE_URL . 'librarian/reservations.php');
      exit;
    }

    $currentStatus = (string) ($reservation['status'] ?? '');
    if ($currentStatus !== RESERVATION_STATUS_PENDING) {
      $pdo->commit();
      $_SESSION['flash_info'] = 'Reservation was already processed by another request.';
      header('Location: ' . BASE_URL . 'librarian/reservations.php');
      exit;
    }

    if ($action === 'approve') {
      $changed = transition_reservation_status(
        $pdo,
        $reservationId,
        RESERVATION_STATUS_PENDING,
        RESERVATION_STATUS_APPROVED,
        [
          'actor_id' => $actorId,
          'actor_role' => $actorRole,
          'action_type' => 'reservation_approve',
        ]
      );

      $pdo->commit();

      if ($changed) {
        $_SESSION['flash_success'] = 'Reservation approved.';
      } else {
        $_SESSION['flash_info'] = 'Reservation status changed by another request.';
      }

      header('Location: ' . BASE_URL . 'librarian/reservations.php');
      exit;
    }

    $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
    if (strlen($rejectionReason) > 255) {
      $rejectionReason = substr($rejectionReason, 0, 255);
    }

    $meta = [
      'actor_id' => $actorId,
      'actor_role' => $actorRole,
      'action_type' => 'reservation_reject',
    ];
    if ($rejectionReason !== '') {
      $meta['rejection_reason'] = $rejectionReason;
    }

    $changed = transition_reservation_status(
      $pdo,
      $reservationId,
      RESERVATION_STATUS_PENDING,
      RESERVATION_STATUS_REJECTED,
      $meta
    );

    $pdo->commit();

    if ($changed) {
      $_SESSION['flash_success'] = 'Reservation rejected.';
    } else {
      $_SESSION['flash_info'] = 'Reservation status changed by another request.';
    }

    header('Location: ' . BASE_URL . 'librarian/reservations.php');
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('[reservations.php] reservation action failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Unable to update reservation right now. Please try again.';
    header('Location: ' . BASE_URL . 'librarian/reservations.php');
    exit;
  }
}

expire_stale_reservations($pdo);
$rows = get_pending_reservation_queue($pdo);

$flashError = (string) ($_SESSION['flash_error'] ?? '');
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
$flashInfo = (string) ($_SESSION['flash_info'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_info']);

$current_page = 'librarian.reservations';
$pageTitle = 'Reservations | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-librarian.php'; ?>
    <main class="main-content">
      <div class="page-header">
        <h1>Reservation Queue</h1>
        <p>Review pending reservation requests in first-in, first-out order.</p>
      </div>

      <?php if ($flashError !== ''): ?>
        <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flashSuccess !== ''): ?>
        <div class="flash flash-success" role="status" aria-live="polite" aria-atomic="true"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flashInfo !== ''): ?>
        <div class="flash flash-info" role="status" aria-live="polite" aria-atomic="true"><?= htmlspecialchars($flashInfo, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Pending Reservations</span>
        </div>

        <?php if (empty($rows)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#10003;</span>
            <p>No pending reservations.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Queue #</th>
                  <th>Reserved At</th>
                  <th>Borrower</th>
                  <th>Email</th>
                  <th>Book</th>
                  <th>Author</th>
                  <th>Expires</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td data-label="Queue #"><?= (int) ($row['queue_position'] ?? 0) ?></td>
                    <td data-label="Reserved At"><?= htmlspecialchars((string) $row['reserved_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Borrower"><?= htmlspecialchars((string) $row['borrower_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Email"><?= htmlspecialchars((string) $row['borrower_email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Book"><?= htmlspecialchars((string) $row['book_title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Author"><?= htmlspecialchars((string) ($row['book_author'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Expires"><?= htmlspecialchars((string) $row['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Actions">
                      <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'librarian/reservations.php', ENT_QUOTES, 'UTF-8') ?>" style="display:grid; gap:6px; min-width: 230px;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                        <input type="text" name="rejection_reason" maxlength="255" placeholder="Optional rejection reason" class="field-input" aria-label="Optional rejection reason for reservation <?= (int) $row['id'] ?>">
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                          <button type="submit" name="action" value="approve" class="btn-confirm">Approve</button>
                          <button type="submit" name="action" value="reject" class="btn-accent">Reject</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>

</html>
