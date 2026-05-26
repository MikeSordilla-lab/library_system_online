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
require_once __DIR__ . '/../includes/mailer.php';

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
      'SELECT id, book_id, status
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

      $autoRejected = [];
      if ($changed) {
        $autoRejected = reject_pending_reservations_when_no_copies_left(
          $pdo,
          (int) $reservation['book_id'],
          $actorId,
          $actorRole
        );
      }

      $pdo->commit();

      foreach ($autoRejected as $rejectedReservation) {
        $mailError = '';
        if (!send_reservation_unavailable_mail(
          (string) $rejectedReservation['borrower_email'],
          (string) $rejectedReservation['borrower_name'],
          (string) $rejectedReservation['book_title'],
          $mailError
        )) {
          error_log('[reservations.php] auto-rejection email failed for reservation '
            . (int) $rejectedReservation['id'] . ': ' . $mailError);
        }
      }

      if ($changed) {
        $_SESSION['flash_success'] = 'Reservation approved.'
          . (!empty($autoRejected)
            ? ' ' . count($autoRejected) . ' competing reservation(s) were removed and notified.'
            : '');
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

// EPIC 1: Also fetch approved reservations so librarians can see what's awaiting pickup
$approved_rows_stmt = $pdo->prepare(
  'SELECT r.id, r.user_id, r.book_id, r.reserved_at, r.approved_at, r.expires_at,
          u.full_name AS borrower_name, u.email AS borrower_email,
          b.title AS book_title, b.author AS book_author,
          (SELECT full_name FROM Users WHERE id = r.approved_by LIMIT 1) AS approved_by_name
     FROM Reservations r
     JOIN Users u ON r.user_id = u.id
     JOIN Books b ON r.book_id = b.id
    WHERE r.status = ?
    ORDER BY r.expires_at ASC'
);
$approved_rows_stmt->execute([RESERVATION_STATUS_APPROVED]);
$approved_rows = $approved_rows_stmt->fetchAll();

$processed_rows_stmt = $pdo->prepare(
  'SELECT r.id, r.status, r.reserved_at, r.approved_at, r.rejected_at, r.updated_at, r.rejection_reason,
          u.full_name AS borrower_name, u.email AS borrower_email,
          b.title AS book_title, b.author AS book_author,
          approver.full_name AS approved_by_name,
          rejecter.full_name AS rejected_by_name
     FROM Reservations r
     JOIN Users u ON r.user_id = u.id
     JOIN Books b ON r.book_id = b.id
LEFT JOIN Users approver ON approver.id = r.approved_by
LEFT JOIN Users rejecter ON rejecter.id = r.rejected_by
    WHERE r.status IN (?, ?, ?)
    ORDER BY COALESCE(r.updated_at, r.rejected_at, r.approved_at, r.reserved_at) DESC, r.id DESC'
);
$processed_rows_stmt->execute([RESERVATION_STATUS_APPROVED, RESERVATION_STATUS_REJECTED, RESERVATION_STATUS_FULFILLED]);
$processed_rows = $processed_rows_stmt->fetchAll();

$pendingCount = count($rows);
$approvedCount = count($approved_rows);
$processedCount = count($processed_rows);
$rejectedCount = 0;
foreach ($processed_rows as $processedRow) {
  if (($processedRow['status'] ?? '') === RESERVATION_STATUS_REJECTED) {
    $rejectedCount++;
  }
}

function librarian_reservation_date($value): string
{
  if (!$value) {
    return '—';
  }

  $timestamp = strtotime((string) $value);
  if ($timestamp === false) {
    return '—';
  }

  return date('d M Y', $timestamp);
}

$flashError = (string) ($_SESSION['flash_error'] ?? '');
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
$flashInfo = (string) ($_SESSION['flash_info'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_info']);

$current_page = 'librarian.reservations';
$pageTitle = 'Reservations | LibraLink';
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
    <main class="main-content librarian-reservations-page">
      <div class="page-header reservation-page-header">
        <div>
          <h1>Reservation Queue</h1>
          <p>Review requests, monitor pickups, and search processed reservations.</p>
        </div>
        <a class="btn-primary" href="<?= htmlspecialchars(BASE_URL . 'librarian/checkout.php', ENT_QUOTES, 'UTF-8') ?>">Go to Check-Out</a>
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

      <div class="reservation-stats" aria-label="Reservation summary">
        <div class="reservation-stat-card reservation-stat-card--pending">
          <span class="reservation-stat-card__label">Pending</span>
          <strong><?= number_format($pendingCount) ?></strong>
          <span>Needs review</span>
        </div>
        <div class="reservation-stat-card reservation-stat-card--approved">
          <span class="reservation-stat-card__label">Awaiting Pickup</span>
          <strong><?= number_format($approvedCount) ?></strong>
          <span>Approved holds</span>
        </div>
        <div class="reservation-stat-card reservation-stat-card--processed">
          <span class="reservation-stat-card__label">Processed</span>
          <strong><?= number_format($processedCount) ?></strong>
          <span><?= number_format($rejectedCount) ?> declined</span>
        </div>
      </div>

      <div class="reservation-tabs" role="tablist" aria-label="Reservation sections">
        <button type="button" class="reservation-tab active" data-reservation-tab="pending" role="tab" aria-selected="true" aria-controls="reservation-panel-pending">
          Pending <span><?= $pendingCount ?></span>
        </button>
        <button type="button" class="reservation-tab" data-reservation-tab="pickup" role="tab" aria-selected="false" aria-controls="reservation-panel-pickup">
          Awaiting Pickup <span><?= $approvedCount ?></span>
        </button>
        <button type="button" class="reservation-tab" data-reservation-tab="processed" role="tab" aria-selected="false" aria-controls="reservation-panel-processed">
          History <span><?= $processedCount ?></span>
        </button>
      </div>

      <div class="reservation-filterbar" aria-label="Filter visible reservation section">
        <div class="reservation-search">
          <span aria-hidden="true">⌕</span>
          <input id="reservation-search" type="search" placeholder="Search selected tab by borrower, email, book, or date">
        </div>
        <div class="reservation-controls">
          <label class="sr-only" for="reservation-page-size">Rows per page</label>
          <select id="reservation-page-size" class="reservation-select" aria-label="Rows per page">
            <option value="5">5 rows</option>
            <option value="10" selected>10 rows</option>
            <option value="25">25 rows</option>
          </select>
        </div>
      </div>

      <section id="reservation-panel-pending" class="reservation-panel active" data-reservation-panel="pending" role="tabpanel">
      <div class="reservation-filter-row" data-filter-group="pending">
        <button type="button" class="reservation-filter-chip active" data-filter="all">All Pending</button>
        <button type="button" class="reservation-filter-chip" data-filter="today">Today</button>
        <button type="button" class="reservation-filter-chip" data-filter="oldest">Oldest First</button>
        <button type="button" class="reservation-filter-chip" data-filter="newest">Newest First</button>
      </div>
      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Pending Reservations</span>
          <?php if ($pendingCount > 0): ?>
            <span class="badge badge-blue"><?= $pendingCount ?></span>
          <?php endif; ?>
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
                  <th>Book</th>
                  <th>Expires</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row):
                  $__pendingTs = strtotime($row['reserved_at'] ?? '') ?: 0;
                ?>
                  <tr data-reservation-row data-ts-sort="<?= $__pendingTs ?>" data-ts-reserved="<?= $__pendingTs ?>">
                    <td data-label="Queue #"><span class="reservation-queue-chip">#<?= (int) ($row['queue_position'] ?? 0) ?></span></td>
                    <td data-label="Reserved At"><?= htmlspecialchars(librarian_reservation_date($row['reserved_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Borrower">
                      <span class="reservation-main"><?= htmlspecialchars((string) $row['borrower_name'], ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="reservation-sub"><?= htmlspecialchars((string) $row['borrower_email'], ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td data-label="Book">
                      <span class="reservation-main"><?= htmlspecialchars((string) $row['book_title'], ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="reservation-sub"><?= htmlspecialchars((string) ($row['book_author'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td data-label="Expires"><?= htmlspecialchars(librarian_reservation_date($row['expires_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Actions">
                      <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'librarian/reservations.php', ENT_QUOTES, 'UTF-8') ?>" class="table-action-form reservation-action-form">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                        <div class="table-action-buttons reservation-action-buttons">
                          <button type="submit" name="action" value="approve" class="btn-confirm">Approve</button>
                          <button type="submit" name="action" value="reject" class="btn-accent">Reject</button>
                        </div>
                        <input type="text" name="rejection_reason" maxlength="255" placeholder="Optional rejection reason" class="field-input" aria-label="Optional rejection reason for reservation <?= (int) $row['id'] ?>">
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <p class="reservation-filter-empty" data-reservation-empty hidden>No matching pending reservations.</p>
      <div class="reservation-pagination" data-pagination-for="pending" hidden></div>
      </section>

      <section id="reservation-panel-pickup" class="reservation-panel" data-reservation-panel="pickup" role="tabpanel" hidden>
      <div class="reservation-filter-row" data-filter-group="pickup">
        <button type="button" class="reservation-filter-chip active" data-filter="all">All Awaiting Pickup</button>
        <button type="button" class="reservation-filter-chip" data-filter="ready-today">Ready Today</button>
        <button type="button" class="reservation-filter-chip" data-filter="deadline-soon">Pickup Deadline Soon</button>
        <button type="button" class="reservation-filter-chip" data-filter="oldest">Oldest First</button>
        <button type="button" class="reservation-filter-chip" data-filter="newest">Newest First</button>
      </div>
      <!-- ── EPIC 1: Approved Reservations (Awaiting Pickup) ───────────────── -->
      <div class="section-card reservation-section-card">
        <div class="section-card__header">
          <span class="section-card__title">Approved — Awaiting Pickup</span>
          <?php if (!empty($approved_rows)): ?>
            <span class="badge badge-green"><?= count($approved_rows) ?></span>
          <?php endif; ?>
        </div>
        <p class="reservation-section-note">
          Use Check-Out when the borrower arrives. The checkout flow will mark the reservation as fulfilled.
        </p>

        <?php if (empty($approved_rows)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#10003;</span>
            <p>No approved reservations awaiting pickup.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Borrower</th>
                  <th>Book</th>
                  <th>Approved On</th>
                  <th>Expires</th>
                  <th>Approved By</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($approved_rows as $ar):
                  $expires_ts   = strtotime($ar['expires_at']);
                  $hrs_left     = (int)ceil(($expires_ts - time()) / 3600);
                  $expires_soon = $hrs_left > 0 && $hrs_left <= 24;
                  $__approvedTs  = strtotime($ar['approved_at'] ?? '') ?: 0;
                  $__expiresTs   = $expires_ts ?: 0;
                ?>
                  <tr<?= $expires_soon ? ' class="reservation-row-warning"' : '' ?> data-reservation-row data-ts-sort="<?= $__approvedTs ?>" data-ts-approved="<?= $__approvedTs ?>" data-ts-expires="<?= $__expiresTs ?>">
                    <td data-label="Borrower">
                      <span class="reservation-main"><?= htmlspecialchars((string)$ar['borrower_name'], ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="reservation-sub"><?= htmlspecialchars((string)$ar['borrower_email'], ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td data-label="Book">
                      <span class="reservation-main"><?= htmlspecialchars((string)$ar['book_title'], ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="reservation-sub"><?= htmlspecialchars((string)($ar['book_author'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td data-label="Approved On">
                      <?= htmlspecialchars(librarian_reservation_date($ar['approved_at'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="Expires">
                      <strong><?= htmlspecialchars(librarian_reservation_date($ar['expires_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                      <?php if ($expires_soon): ?>
                        <span class="badge badge-amber">Expiring soon</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Approved By">
                      <?= htmlspecialchars((string)($ar['approved_by_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <p class="reservation-filter-empty" data-reservation-empty hidden>No matching approved reservations.</p>
      <div class="reservation-pagination" data-pagination-for="pickup" hidden></div>
      </section>

      <section id="reservation-panel-processed" class="reservation-panel" data-reservation-panel="processed" role="tabpanel" hidden>
      <div class="reservation-filter-row" data-filter-group="history">
        <button type="button" class="reservation-filter-chip active" data-filter="all">All History</button>
        <button type="button" class="reservation-filter-chip" data-filter="approved">Approved</button>
        <button type="button" class="reservation-filter-chip" data-filter="declined">Declined</button>
        <button type="button" class="reservation-filter-chip" data-filter="completed">Completed</button>
        <button type="button" class="reservation-filter-chip" data-filter="today">Today</button>
        <button type="button" class="reservation-filter-chip" data-filter="week">This Week</button>
        <button type="button" class="reservation-filter-chip" data-filter="month">This Month</button>
      </div>
      <div class="section-card reservation-section-card">
        <div class="section-card__header">
          <span class="section-card__title">Approved and Declined History</span>
          <?php if (!empty($processed_rows)): ?>
            <span class="badge badge-blue"><?= count($processed_rows) ?></span>
          <?php endif; ?>
        </div>

        <?php if (empty($processed_rows)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#10003;</span>
            <p>No approved or declined reservations yet.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Reserved At</th>
                  <th>Borrower</th>
                  <th>Book</th>
                  <th>Processed On</th>
                  <th>Processed By</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($processed_rows as $processed):
                  $processedStatus = (string) ($processed['status'] ?? '');
                  $isApproved  = $processedStatus === RESERVATION_STATUS_APPROVED;
                  $isFulfilled = $processedStatus === RESERVATION_STATUS_FULFILLED;
                  $processedAt = $isApproved ? ($processed['approved_at'] ?? '') : ($isFulfilled ? ($processed['updated_at'] ?? '') : ($processed['rejected_at'] ?? ''));
                  $processedBy = $isApproved ? ($processed['approved_by_name'] ?? '') : ($isFulfilled ? '—' : ($processed['rejected_by_name'] ?? ''));
                  $__processedTs = strtotime($processedAt) ?: 0;
                ?>
                  <tr data-reservation-row data-status="<?= htmlspecialchars($processedStatus, ENT_QUOTES, 'UTF-8') ?>" data-ts-processed="<?= $__processedTs ?>" data-ts-sort="<?= $__processedTs ?>">
                    <td data-label="Status">
                      <span class="badge <?= $isApproved ? 'badge-green' : ($isFulfilled ? 'badge-green' : 'badge-red') ?>">
                        <?= $isApproved ? 'Approved' : ($isFulfilled ? 'Completed' : 'Declined') ?>
                      </span>
                    </td>
                    <td data-label="Reserved At"><?= htmlspecialchars(librarian_reservation_date($processed['reserved_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Borrower">
                      <span class="reservation-main"><?= htmlspecialchars((string) $processed['borrower_name'], ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="reservation-sub"><?= htmlspecialchars((string) $processed['borrower_email'], ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td data-label="Book">
                      <span class="reservation-main"><?= htmlspecialchars((string) $processed['book_title'], ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="reservation-sub"><?= htmlspecialchars((string) ($processed['book_author'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td data-label="Processed On">
                      <?= htmlspecialchars(librarian_reservation_date($processedAt), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="Processed By">
                      <?= htmlspecialchars((string) ($processedBy ?: '—'), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="Reason">
                      <?= !$isApproved && !empty($processed['rejection_reason'])
                        ? htmlspecialchars((string) $processed['rejection_reason'], ENT_QUOTES, 'UTF-8')
                        : '&mdash;' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <p class="reservation-filter-empty" data-reservation-empty hidden>No matching reservation history.</p>
      <div class="reservation-pagination" data-pagination-for="processed" hidden></div>
      </section>

    </main>
  </div>
  <style>
    .reservation-pagination {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin: 1rem 0 0;
      flex-wrap: wrap;
      color: var(--rd-text-muted, #8f8374);
      font-size: 0.9rem;
      font-weight: 700;
    }
    .res-pagination-summary {
      white-space: nowrap;
    }
    .res-pagination-center {
      display: flex;
      align-items: center;
      gap: 0.35rem;
    }
    .res-pagination-pages {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      flex-wrap: wrap;
    }
    .res-page-btn {
      min-width: 36px;
      min-height: 34px;
      padding: 0.35rem 0.65rem;
      border: 1px solid rgba(201, 168, 76, 0.2);
      border-radius: 8px;
      background: rgba(76, 70, 62, 0.08);
      color: #6f6559;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .res-page-btn:hover:not(:disabled) {
      border-color: rgba(201, 168, 76, 0.52);
      background: var(--rd-primary, #c9a84c);
      color: #2c281f;
    }
    .res-page-btn.active {
      border-color: rgba(201, 168, 76, 0.52);
      background: var(--rd-primary, #c9a84c);
      color: #2c281f;
    }
    .res-page-btn:disabled {
      opacity: 0.4;
      cursor: not-allowed;
      background: rgba(76, 70, 62, 0.06);
      color: #8f8374;
    }
    .res-page-dots {
      color: var(--rd-text-muted, #8f8374);
      font-weight: 700;
      padding: 0 0.2rem;
    }
    .res-pagination-rpp {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      white-space: nowrap;
    }
    .res-pagination-rpp-label {
      font-size: 0.85rem;
      color: var(--rd-text-muted, #8f8374);
      font-weight: 700;
    }
    .reservation-select {
      min-height: 44px;
      padding: 0 2.25rem 0 0.85rem;
      border: 1px solid rgba(201, 168, 76, 0.24);
      border-radius: 10px;
      background:
        linear-gradient(45deg, transparent 50%, #9a8f80 50%) right 1rem center / 6px 6px no-repeat,
        linear-gradient(135deg, #9a8f80 50%, transparent 50%) right 0.72rem center / 6px 6px no-repeat,
        rgba(42, 39, 35, 0.08);
      color: #4c463e;
      font: inherit;
      font-weight: 700;
      appearance: none;
      cursor: pointer;
    }
    .reservation-select:focus {
      outline: 0;
      border-color: rgba(201, 168, 76, 0.56);
      box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.12);
    }
    .res-pagination-rpp-select {
      min-height: 34px;
      padding: 0 2rem 0 0.65rem;
      font-size: 0.85rem;
      width: auto;
    }
    @media (max-width: 768px) {
      .reservation-pagination {
        flex-direction: column;
        align-items: center;
        gap: 0.6rem;
      }
      .res-pagination-center {
        width: 100%;
        justify-content: center;
      }
      .res-pagination-rpp {
        width: 100%;
        justify-content: center;
      }
      .reservation-controls {
        width: 100%;
      }
      .reservation-select {
        width: 100%;
      }
    }
  </style>
  <script>
    (function() {
      var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-reservation-tab]'));
      var panels = Array.prototype.slice.call(document.querySelectorAll('[data-reservation-panel]'));
      var search = document.getElementById('reservation-search');
      var pageSizeSelect = document.getElementById('reservation-page-size');
      if (!tabs.length || !panels.length) return;

      var state = {
        tab: 'pending',
        filter: 'all',
        page: 1,
        pages: { pending: 1, pickup: 1, processed: 1 },
        pageSize: pageSizeSelect ? parseInt(pageSizeSelect.value, 10) || 10 : 10
      };

      function activePanel() {
        return panels.filter(function(panel) {
          return !panel.hidden;
        })[0] || panels[0];
      }

      function isToday(ts) {
        if (!ts) return false;
        var now = new Date();
        var d = new Date(ts * 1000);
        return d.getFullYear() === now.getFullYear() &&
               d.getMonth() === now.getMonth() &&
               d.getDate() === now.getDate();
      }

      function isThisWeek(ts) {
        if (!ts) return false;
        var now = new Date();
        var d = new Date(ts * 1000);
        var day = now.getDay();
        var diff = now.getDate() - day + (day === 0 ? -6 : 1);
        var weekStart = new Date(now.getFullYear(), now.getMonth(), diff);
        weekStart.setHours(0, 0, 0, 0);
        return d >= weekStart;
      }

      function isThisMonth(ts) {
        if (!ts) return false;
        var now = new Date();
        var d = new Date(ts * 1000);
        return d.getFullYear() === now.getFullYear() &&
               d.getMonth() === now.getMonth();
      }

      function matchesRowFilter(row, filter, panelName) {
        if (filter === 'all') return true;

        if (panelName === 'pending') {
          var ts = parseInt(row.getAttribute('data-ts-reserved') || '0');
          if (filter === 'today') return isToday(ts);
        }

        if (panelName === 'pickup') {
          var tsApproved = parseInt(row.getAttribute('data-ts-approved') || '0');
          var tsExpires = parseInt(row.getAttribute('data-ts-expires') || '0');
          if (filter === 'ready-today') return isToday(tsApproved);
          if (filter === 'deadline-soon') {
            if (!tsExpires) return false;
            var secsLeft = tsExpires - Math.floor(Date.now() / 1000);
            return secsLeft > 0 && secsLeft <= 86400;
          }
        }

        if (panelName === 'processed') {
          var status = row.getAttribute('data-status');
          var ts = parseInt(row.getAttribute('data-ts-processed') || '0');
          if (filter === 'approved') return status === 'approved';
          if (filter === 'declined') return status === 'rejected';
          if (filter === 'completed') return status === 'fulfilled';
          if (filter === 'today') return isToday(ts);
          if (filter === 'week') return isThisWeek(ts);
          if (filter === 'month') return isThisMonth(ts);
        }

        return true;
      }

      function sortRowsByTs(rows, dir) {
        rows.sort(function(a, b) {
          var aTs = parseInt(a.getAttribute('data-ts-sort') || '0');
          var bTs = parseInt(b.getAttribute('data-ts-sort') || '0');
          return dir === 'oldest' ? aTs - bTs : bTs - aTs;
        });
      }

      function renderPagination(panel, totalRows) {
        var pagination = panel.querySelector('[data-pagination-for]');
        if (!pagination) return;

        if (totalRows <= state.pageSize) {
          pagination.hidden = true;
          pagination.innerHTML = '';
          return;
        }

        var totalPages = Math.max(1, Math.ceil(totalRows / state.pageSize));
        state.page = Math.min(Math.max(1, state.page), totalPages);

        var start = totalRows === 0 ? 0 : ((state.page - 1) * state.pageSize) + 1;
        var end = Math.min(totalRows, state.page * state.pageSize);
        pagination.hidden = false;
        pagination.innerHTML = '';

        var summary = document.createElement('span');
        summary.className = 'res-pagination-summary';
        summary.textContent = 'Showing ' + start + '\u2013' + end + ' of ' + totalRows + ' records';
        pagination.appendChild(summary);

        var center = document.createElement('div');
        center.className = 'res-pagination-center';

        var pagesWrap = document.createElement('div');
        pagesWrap.className = 'res-pagination-pages';

        function addBtn(label, page, disabled, active) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'res-page-btn' + (active ? ' active' : '');
          button.textContent = label;
          button.disabled = disabled;
          button.addEventListener('click', function() {
            state.page = page;
            state.pages[state.tab] = page;
            applyFilter();
          });
          pagesWrap.appendChild(button);
        }

        addBtn('Previous', state.page - 1, state.page === 1, false);
        for (var i = 1; i <= totalPages; i += 1) {
          if (i === 1 || i === totalPages || Math.abs(i - state.page) <= 1) {
            addBtn(String(i), i, false, i === state.page);
          } else if (i === state.page - 2 || i === state.page + 2) {
            var dots = document.createElement('span');
            dots.className = 'res-page-dots';
            dots.textContent = '\u2026';
            pagesWrap.appendChild(dots);
          }
        }
        addBtn('Next', state.page + 1, state.page === totalPages, false);

        center.appendChild(pagesWrap);

        var rppWrap = document.createElement('div');
        rppWrap.className = 'res-pagination-rpp';
        var rppLabel = document.createElement('label');
        rppLabel.textContent = 'Rows per page:';
        rppLabel.className = 'res-pagination-rpp-label';
        var rppSelect = document.createElement('select');
        rppSelect.className = 'reservation-select res-pagination-rpp-select';
        rppSelect.setAttribute('aria-label', 'Rows per page');
        [5, 10, 25].forEach(function(val) {
          var opt = document.createElement('option');
          opt.value = String(val);
          opt.textContent = val;
          if (state.pageSize === val) opt.selected = true;
          rppSelect.appendChild(opt);
        });
        rppSelect.addEventListener('change', function() {
          state.pageSize = parseInt(rppSelect.value, 10) || 10;
          state.page = 1;
          state.pages[state.tab] = 1;
          if (pageSizeSelect) pageSizeSelect.value = rppSelect.value;
          applyFilter();
        });
        rppWrap.appendChild(rppLabel);
        rppWrap.appendChild(rppSelect);

        pagination.appendChild(center);
        pagination.appendChild(rppWrap);

        if (pageSizeSelect) pageSizeSelect.value = String(state.pageSize);
      }

      function applyFilter() {
        var panel = activePanel();
        var query = search ? search.value.trim().toLowerCase() : '';
        var activeChip = panel.querySelector('[data-filter-group] [data-filter].active');
        var filterVal = activeChip ? activeChip.getAttribute('data-filter') : 'all';
        var panelName = panel.getAttribute('data-reservation-panel');
        var rows = Array.prototype.slice.call(panel.querySelectorAll('[data-reservation-row]'));
        var empty = panel.querySelector('[data-reservation-empty]');

        state.tab = panelName;
        state.filter = filterVal;
        state.page = state.pages[state.tab] || 1;

        rows.forEach(function(row, index) {
          if (!row.dataset.originalIndex) {
            row.dataset.originalIndex = String(index);
          }
        });

        // Handle sort-only filters
        if (filterVal === 'oldest' || filterVal === 'newest') {
          var tbody = panel.querySelector('tbody');
          if (tbody) {
            sortRowsByTs(rows, filterVal);
            rows.forEach(function(row) { tbody.appendChild(row); });
          }
        }

        // Determine matched rows (search + status/date filter)
        var matchedRows = rows.filter(function(row) {
          var matchesSearch = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
          var matchesFilter = matchesRowFilter(row, filterVal, panelName);
          return matchesSearch && matchesFilter;
        });

        var totalMatched = matchedRows.length;
        var startIndex = (state.page - 1) * state.pageSize;
        var endIndex = startIndex + state.pageSize;

        if (startIndex >= totalMatched && totalMatched > 0) {
          state.page = Math.ceil(totalMatched / state.pageSize);
          state.pages[state.tab] = state.page;
          startIndex = (state.page - 1) * state.pageSize;
          endIndex = startIndex + state.pageSize;
        }

        rows.forEach(function(row) { row.hidden = true; });

        matchedRows.forEach(function(row, index) {
          row.hidden = index < startIndex || index >= endIndex;
        });

        if (empty) {
          var hasQuery = query !== '';
          var hasFilter = filterVal !== 'all';
          empty.hidden = rows.length === 0 || totalMatched > 0 || (!hasQuery && !hasFilter);
        }

        renderPagination(panel, totalMatched);
      }

      function activate(name) {
        state.tab = name;
        tabs.forEach(function(tab) {
          var selected = tab.getAttribute('data-reservation-tab') === name;
          tab.classList.toggle('active', selected);
          tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });

        panels.forEach(function(panel) {
          var selected = panel.getAttribute('data-reservation-panel') === name;
          panel.classList.toggle('active', selected);
          panel.hidden = !selected;
        });

        if (search) search.value = '';
        applyFilter();
      }

      // Tab clicks
      tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
          activate(tab.getAttribute('data-reservation-tab'));
        });
      });

      // Filter chip clicks
      document.querySelectorAll('[data-filter-group]').forEach(function(group) {
        var chips = group.querySelectorAll('[data-filter]');
        chips.forEach(function(chip) {
          chip.addEventListener('click', function() {
            var panel = group.closest('[data-reservation-panel]');
            if (!panel || panel.hidden) return;
            chips.forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            applyFilter();
          });
        });
      });

      if (search) {
        search.addEventListener('input', function() {
          state.page = 1;
          state.pages[state.tab] = 1;
          applyFilter();
        });
      }
      if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function() {
          state.pageSize = parseInt(pageSizeSelect.value, 10) || 10;
          state.page = 1;
          state.pages[state.tab] = 1;
          applyFilter();
        });
      }
      applyFilter();
    }());
  </script>
</body>

</html>
