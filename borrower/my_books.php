<?php

/**
 * borrower/my_books.php — My Books: Full Borrowing Lifecycle (EPIC 1)
 *
 * Displays the complete lifecycle for the authenticated borrower:
 *   1. Active & Overdue Loans    — books currently borrowed + due dates
 *   2. Approved Reservations     — ready to be checked out
 *   3. Pending Reservations      — awaiting librarian decision
 *   4. Rejected Reservations     — declined by librarian (recent 10)
 *   5. Loan History              — returned books (last 30)
 *
 * POST action=return_request: (future hook — placeholder, actual return is
 * done by librarian via checkin.php)
 *
 * Protected: Borrower role only.
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo     = get_db();
$user_id = (int) $_SESSION['user_id'];

// Expire stale reservations before any display so statuses are accurate
expire_stale_reservations($pdo);

// Flash messages
$flash_error   = (string) ($_SESSION['flash_error']   ?? '');
$flash_success = (string) ($_SESSION['flash_success'] ?? '');
$flash_info    = (string) ($_SESSION['flash_info']    ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_info']);

// ── Cancel reservation (POST) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action         = (string) ($_POST['action'] ?? '');
    $reservation_id = (int)   ($_POST['reservation_id'] ?? 0);

    if ($action === 'cancel' && $reservation_id > 0) {
        $pdo->beginTransaction();
        try {
            // Only allow cancelling own pending/approved reservations
            $chk = $pdo->prepare(
                "SELECT id, status FROM Reservations
                  WHERE id = ? AND user_id = ? AND status IN ('pending','approved')
                  FOR UPDATE"
            );
            $chk->execute([$reservation_id, $user_id]);
            $res = $chk->fetch();

            if ($res) {
                $upd = $pdo->prepare(
                    "UPDATE Reservations
                        SET status = 'cancelled', updated_at = NOW()
                      WHERE id = ?"
                );
                $upd->execute([$reservation_id]);
                log_circulation($pdo, [
                    'actor_id'      => $user_id,
                    'actor_role'    => 'borrower',
                    'action_type'   => 'reservation_cancel',
                    'target_entity' => 'Reservations',
                    'target_id'     => $reservation_id,
                    'outcome'       => 'success',
                ]);
                $pdo->commit();
                $_SESSION['flash_success'] = 'Reservation cancelled.';
            } else {
                $pdo->rollBack();
                $_SESSION['flash_error'] = 'Could not cancel — reservation not found or already processed.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[my_books.php] cancel failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An error occurred. Please try again.';
        }
    }

    header('Location: ' . BASE_URL . 'borrower/my_books.php');
    exit;
}

// ── 1. Active & Overdue Loans ─────────────────────────────────────────────────
$active_stmt = $pdo->prepare(
    "SELECT c.id, c.checkout_date, c.due_date, c.status,
            b.title, b.author, b.isbn,
            DATEDIFF(NOW(), c.due_date) AS days_overdue
       FROM Circulation c
       JOIN Books b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status IN ('active','overdue')
      ORDER BY c.due_date ASC"
);
$active_stmt->execute([$user_id]);
$active_loans = $active_stmt->fetchAll();

// ── 2. Approved Reservations (ready for pickup) ───────────────────────────────
$approved_stmt = $pdo->prepare(
    "SELECT r.id, r.reserved_at, r.expires_at, r.approved_at,
            b.title, b.author, b.isbn
       FROM Reservations r
       JOIN Books b ON r.book_id = b.id
      WHERE r.user_id = ? AND r.status = 'approved'
      ORDER BY r.approved_at DESC"
);
$approved_stmt->execute([$user_id]);
$approved_reservations = $approved_stmt->fetchAll();

// ── 3. Pending Reservations with queue position ────────────────────────────────
$pending_stmt = $pdo->prepare(
    "SELECT r.id, r.reserved_at, r.expires_at, r.book_id,
            b.title, b.author,
            (
              SELECT COUNT(*) + 1
                FROM Reservations q
               WHERE q.book_id = r.book_id
                 AND q.status  = 'pending'
                 AND (
                   q.reserved_at < r.reserved_at
                   OR (q.reserved_at = r.reserved_at AND q.id < r.id)
                 )
            ) AS queue_position
       FROM Reservations r
       JOIN Books b ON r.book_id = b.id
      WHERE r.user_id = ? AND r.status = 'pending'
      ORDER BY r.reserved_at ASC"
);
$pending_stmt->execute([$user_id]);
$pending_reservations = $pending_stmt->fetchAll();

// ── 4. Rejected Reservations (recent 10) ─────────────────────────────────────
$rejected_stmt = $pdo->prepare(
    "SELECT r.id, r.reserved_at, r.rejected_at, r.rejection_reason,
            b.title, b.author
       FROM Reservations r
       JOIN Books b ON r.book_id = b.id
      WHERE r.user_id = ? AND r.status = 'rejected'
      ORDER BY r.rejected_at DESC
      LIMIT 10"
);
$rejected_stmt->execute([$user_id]);
$rejected_reservations = $rejected_stmt->fetchAll();

// ── 5. Loan History (returned, last 30) ───────────────────────────────────────
$history_stmt = $pdo->prepare(
    "SELECT c.id, c.checkout_date, c.due_date, c.return_date,
            c.fine_amount, c.fine_paid,
            b.title, b.author
       FROM Circulation c
       JOIN Books b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status = 'returned'
      ORDER BY c.return_date DESC
      LIMIT 30"
);
$history_stmt->execute([$user_id]);
$loan_history = $history_stmt->fetchAll();

$current_page = 'borrower.my_books';
$pageTitle    = 'My Books | Library System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="app-shell">
  <?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <h1>My Books</h1>
      <p>Track every stage of your borrowing — from reservation to return.</p>
    </div>

    <!-- ── Flash Messages ─────────────────────────────────────────────────── -->
    <?php if ($flash_error !== ''): ?>
      <div class="flash flash-error" role="alert"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flash_success !== ''): ?>
      <div class="flash flash-success" role="status"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flash_info !== ''): ?>
      <div class="flash flash-info" role="status"><?= htmlspecialchars($flash_info, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- ── Lifecycle Progress Indicator ──────────────────────────────────── -->
    <div class="section-card" style="margin-bottom: var(--space-4);">
      <div class="lifecycle-steps">
        <div class="lifecycle-step <?= !empty($pending_reservations) ? 'lifecycle-step--active' : '' ?>">
          <span class="lifecycle-step__icon">📋</span>
          <span class="lifecycle-step__label">Reserved</span>
          <?php if (!empty($pending_reservations)): ?>
            <span class="badge badge-blue"><?= count($pending_reservations) ?></span>
          <?php endif; ?>
        </div>
        <div class="lifecycle-step__arrow">→</div>
        <div class="lifecycle-step <?= !empty($approved_reservations) ? 'lifecycle-step--active' : '' ?>">
          <span class="lifecycle-step__icon">✅</span>
          <span class="lifecycle-step__label">Approved</span>
          <?php if (!empty($approved_reservations)): ?>
            <span class="badge badge-green"><?= count($approved_reservations) ?></span>
          <?php endif; ?>
        </div>
        <div class="lifecycle-step__arrow">→</div>
        <div class="lifecycle-step <?= !empty($active_loans) ? 'lifecycle-step--active' : '' ?>">
          <span class="lifecycle-step__icon">📖</span>
          <span class="lifecycle-step__label">Borrowed</span>
          <?php if (!empty($active_loans)): ?>
            <span class="badge badge-amber"><?= count($active_loans) ?></span>
          <?php endif; ?>
        </div>
        <div class="lifecycle-step__arrow">→</div>
        <div class="lifecycle-step">
          <span class="lifecycle-step__icon">↩️</span>
          <span class="lifecycle-step__label">Returned</span>
          <?php if (!empty($loan_history)): ?>
            <span class="badge badge-default"><?= count($loan_history) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Section 1: Active & Overdue Loans ────────────────────────────── -->
    <div class="section-card">
      <div class="section-card__header">
        <span class="section-card__title">📖 Currently Borrowed</span>
        <?php if (!empty($active_loans)): ?>
          <span class="badge badge-amber"><?= count($active_loans) ?> book<?= count($active_loans) !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>

      <?php if (empty($active_loans)): ?>
        <div class="empty-state">
          <div class="empty-state__icon">📚</div>
          <p>You have no books currently borrowed.</p>
          <a href="<?= BASE_URL ?>borrower/catalog.php" class="btn-confirm" style="display:inline-block;margin-top:var(--space-2);">Browse Catalog</a>
        </div>
      <?php else: ?>
        <div class="tbl-wrapper">
          <table class="tbl">
            <thead>
              <tr>
                <th>Book</th>
                <th>Author</th>
                <th>Checked Out</th>
                <th>Due Date</th>
                <th>Status</th>
                <th>Days Left / Overdue</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($active_loans as $loan): ?>
                <?php
                  $is_overdue   = $loan['status'] === 'overdue' || (int)$loan['days_overdue'] > 0;
                  $days_overdue = (int)$loan['days_overdue'];
                  $due_ts       = strtotime($loan['due_date']);
                  $days_left    = (int)ceil(($due_ts - time()) / 86400);
                ?>
                <tr<?= $is_overdue ? ' class="row-overdue"' : '' ?>>
                  <td data-label="Book"><?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Author"><?= htmlspecialchars($loan['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Checked Out"><?= htmlspecialchars(date('d M Y', strtotime($loan['checkout_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Due Date">
                    <strong><?= htmlspecialchars(date('d M Y', strtotime($loan['due_date'])), ENT_QUOTES, 'UTF-8') ?></strong>
                  </td>
                  <td data-label="Status">
                    <?php if ($is_overdue): ?>
                      <span class="badge badge-red">Overdue</span>
                    <?php else: ?>
                      <span class="badge badge-blue">Borrowed</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Days Left / Overdue">
                    <?php if ($is_overdue): ?>
                      <span style="color:var(--color-error,#c0392b);font-weight:600;">
                        <?= $days_overdue ?> day<?= $days_overdue !== 1 ? 's' : '' ?> overdue
                      </span>
                    <?php else: ?>
                      <span style="color:var(--color-success,#27ae60);">
                        <?= max(0, $days_left) ?> day<?= $days_left !== 1 ? 's' : '' ?> left
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="tbl-note" style="margin:var(--space-2) var(--space-3) 0;font-size:.85rem;color:var(--color-muted,#888);">
          To return a book, bring it to the library desk — a librarian will process the check-in.
        </p>
      <?php endif; ?>
    </div>

    <!-- ── Section 2: Approved Reservations (Ready for Pickup) ────────── -->
    <?php if (!empty($approved_reservations)): ?>
    <div class="section-card" style="border:2px solid var(--color-success,#27ae60);">
      <div class="section-card__header">
        <span class="section-card__title">✅ Ready for Pickup</span>
        <span class="badge badge-green"><?= count($approved_reservations) ?> approved</span>
      </div>
      <p style="padding:0 var(--space-3);color:var(--color-muted);font-size:.9rem;margin-top:0;">
        Your reservation has been approved! Visit the library to borrow these books before they expire.
      </p>
      <div class="tbl-wrapper">
        <table class="tbl">
          <thead>
            <tr>
              <th>Book</th>
              <th>Author</th>
              <th>Approved On</th>
              <th>Pickup By</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($approved_reservations as $res): ?>
              <?php
                $expires_ts   = strtotime($res['expires_at']);
                $hrs_left     = (int)ceil(($expires_ts - time()) / 3600);
                $expires_soon = $hrs_left <= 24 && $hrs_left > 0;
                $expired      = $hrs_left <= 0;
              ?>
              <tr<?= $expires_soon ? ' class="row-warning"' : '' ?>>
                <td data-label="Book"><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td data-label="Author"><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td data-label="Approved On">
                  <?= $res['approved_at'] ? htmlspecialchars(date('d M Y', strtotime($res['approved_at'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                </td>
                <td data-label="Pickup By">
                  <strong><?= htmlspecialchars(date('d M Y H:i', $expires_ts), ENT_QUOTES, 'UTF-8') ?></strong>
                  <?php if ($expires_soon): ?>
                    <span class="badge badge-amber" style="margin-left:4px;">Expires soon!</span>
                  <?php endif; ?>
                </td>
                <td data-label="Action">
                  <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/my_books.php', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="reservation_id" value="<?= (int)$res['id'] ?>">
                    <button type="submit" class="btn-accent" onclick="return confirm('Cancel this approved reservation?')">Cancel</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Section 3: Pending Reservations ──────────────────────────────── -->
    <div class="section-card">
      <div class="section-card__header">
        <span class="section-card__title">📋 Pending Reservations</span>
        <?php if (!empty($pending_reservations)): ?>
          <span class="badge badge-blue"><?= count($pending_reservations) ?> waiting</span>
        <?php endif; ?>
      </div>

      <?php if (empty($pending_reservations)): ?>
        <div class="empty-state">
          <div class="empty-state__icon">🔖</div>
          <p>No pending reservations.</p>
          <a href="<?= BASE_URL ?>borrower/catalog.php" class="btn-confirm" style="display:inline-block;margin-top:var(--space-2);">Browse Catalog</a>
        </div>
      <?php else: ?>
        <div class="tbl-wrapper">
          <table class="tbl">
            <thead>
              <tr>
                <th>Book</th>
                <th>Author</th>
                <th>Reserved On</th>
                <th>Expires</th>
                <th>Queue #</th>
                <th>Status</th>
                <th>Cancel</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pending_reservations as $res): ?>
                <tr>
                  <td data-label="Book"><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Author"><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Reserved On"><?= htmlspecialchars(date('d M Y', strtotime($res['reserved_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Expires"><?= htmlspecialchars(date('d M Y', strtotime($res['expires_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Queue #">
                    <span class="badge badge-blue">#<?= (int)$res['queue_position'] ?></span>
                  </td>
                  <td data-label="Status">
                    <span class="badge badge-amber">Awaiting Approval</span>
                  </td>
                  <td data-label="Cancel">
                    <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/my_books.php', ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="reservation_id" value="<?= (int)$res['id'] ?>">
                      <button type="submit" class="btn-accent" onclick="return confirm('Cancel this reservation?')">Cancel</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── Section 4: Rejected Reservations ─────────────────────────────── -->
    <?php if (!empty($rejected_reservations)): ?>
    <div class="section-card">
      <div class="section-card__header">
        <span class="section-card__title">❌ Recently Rejected</span>
        <span class="badge badge-red"><?= count($rejected_reservations) ?></span>
      </div>
      <div class="tbl-wrapper">
        <table class="tbl">
          <thead>
            <tr>
              <th>Book</th>
              <th>Author</th>
              <th>Reserved On</th>
              <th>Rejected On</th>
              <th>Reason</th>
              <th>Reserve Again</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rejected_reservations as $res): ?>
              <tr>
                <td data-label="Book"><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td data-label="Author"><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td data-label="Reserved On"><?= htmlspecialchars(date('d M Y', strtotime($res['reserved_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td data-label="Rejected On">
                  <?= $res['rejected_at'] ? htmlspecialchars(date('d M Y', strtotime($res['rejected_at'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                </td>
                <td data-label="Reason">
                  <?php if (!empty($res['rejection_reason'])): ?>
                    <span title="<?= htmlspecialchars($res['rejection_reason'], ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars(mb_strimwidth($res['rejection_reason'], 0, 60, '…'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  <?php else: ?>
                    <span style="color:var(--color-muted);">No reason given</span>
                  <?php endif; ?>
                </td>
                <td data-label="Reserve Again">
                  <a href="<?= htmlspecialchars(BASE_URL . 'borrower/catalog.php', ENT_QUOTES, 'UTF-8') ?>" class="btn-confirm" style="font-size:.82rem;padding:.3rem .75rem;">Browse</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Section 5: Loan History ───────────────────────────────────────── -->
    <div class="section-card">
      <div class="section-card__header">
        <span class="section-card__title">↩️ Loan History</span>
        <span style="font-size:.82rem;color:var(--color-muted);">Last 30 returned books</span>
      </div>

      <?php if (empty($loan_history)): ?>
        <div class="empty-state">
          <div class="empty-state__icon">📖</div>
          <p>No returned books on record yet.</p>
        </div>
      <?php else: ?>
        <div class="tbl-wrapper">
          <table class="tbl">
            <thead>
              <tr>
                <th>Book</th>
                <th>Author</th>
                <th>Borrowed</th>
                <th>Due</th>
                <th>Returned</th>
                <th>Fine</th>
                <th>Fine Paid</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($loan_history as $h): ?>
                <?php
                  $returned_late = strtotime($h['return_date']) > strtotime($h['due_date']);
                ?>
                <tr<?= $returned_late ? ' class="row-warning"' : '' ?>>
                  <td data-label="Book"><?= htmlspecialchars($h['title'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Author"><?= htmlspecialchars($h['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Borrowed"><?= htmlspecialchars(date('d M Y', strtotime($h['checkout_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Due"><?= htmlspecialchars(date('d M Y', strtotime($h['due_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Returned">
                    <?= htmlspecialchars(date('d M Y', strtotime($h['return_date'])), ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($returned_late): ?>
                      <span class="badge badge-amber" style="margin-left:4px;">Late</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Fine">
                    <?php if ((float)$h['fine_amount'] > 0): ?>
                      <span style="color:var(--color-error,#c0392b);font-weight:600;">
                        ₱<?= number_format((float)$h['fine_amount'], 2) ?>
                      </span>
                    <?php else: ?>
                      <span style="color:var(--color-muted);">None</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Fine Paid">
                    <?php if ((float)$h['fine_amount'] > 0): ?>
                      <?php if ($h['fine_paid']): ?>
                        <span class="badge badge-green">Paid</span>
                      <?php else: ?>
                        <span class="badge badge-red">Unpaid</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span style="color:var(--color-muted);">—</span>
                    <?php endif; ?>
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

<style>
/* ── Lifecycle stepper ───────────────────────────────────────────────── */
.lifecycle-steps {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-1, .5rem);
  padding: var(--space-3, 1rem);
}
.lifecycle-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: var(--space-2, .75rem) var(--space-3, 1rem);
  border-radius: var(--radius-md, 8px);
  background: var(--color-surface-alt, #f5f5f5);
  min-width: 90px;
  opacity: .55;
  transition: opacity .2s;
}
.lifecycle-step--active {
  opacity: 1;
  background: var(--color-primary-soft, #e8f0e5);
}
.lifecycle-step__icon { font-size: 1.4rem; }
.lifecycle-step__label { font-size: .78rem; font-weight: 600; text-align: center; }
.lifecycle-step__arrow {
  font-size: 1.2rem;
  color: var(--color-muted, #aaa);
  flex-shrink: 0;
}
.row-warning td { background: rgba(201,168,76,.08); }
.tbl-note { color: var(--color-muted, #888); }
@media (max-width: 640px) {
  .lifecycle-steps { justify-content: center; }
  .lifecycle-step__arrow { transform: rotate(90deg); }
}
</style>

</body>
</html>
