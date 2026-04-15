<?php

/**
 * borrower/index.php — Borrower Dashboard (US5, FR-016–FR-019)
 *
 * Displays:
 *   - Active / overdue loans with due dates (T026)
 *   - Loan history with return dates and fine amounts (T027)
 *   - Pending reservations with queue position and cancel option (T028)
 *
 * Protected: Borrower role only (FR-029).
 */

// RBAC guard — must appear before any HTML output (FR-034)
$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo     = get_db();
$user_id = (int) $_SESSION['user_id'];

// Flash messages
$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// T026 — Active loans
$active_stmt = $pdo->prepare(
  'SELECT c.id, c.checkout_date, c.due_date, c.status, b.title
       FROM Circulation c
       JOIN Books       b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status IN (\'active\', \'overdue\')
      ORDER BY c.due_date ASC'
);
$active_stmt->execute([$user_id]);
$active_loans = $active_stmt->fetchAll();

// T027 — Loan history (returned, last 20)
$history_stmt = $pdo->prepare(
  'SELECT c.return_date, c.fine_amount, c.fine_paid, b.title
       FROM Circulation c
       JOIN Books       b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status = \'returned\'
      ORDER BY c.return_date DESC
      LIMIT 20'
);
$history_stmt->execute([$user_id]);
$loan_history = $history_stmt->fetchAll();

// Bug fix: expire stale pending reservations before displaying them so the
// pending list and queue positions reflect reality.
expire_stale_reservations($pdo);

// T028 — Pending reservations with queue position (optimized: avoid N+1 query)
// First, fetch all pending reservations for this user
$res_stmt = $pdo->prepare(
  'SELECT r.id, r.reserved_at, r.expires_at, r.book_id, b.title
   FROM Reservations r
   JOIN Books b ON r.book_id = b.id
   WHERE r.user_id = ? AND r.status = \'pending\'
   ORDER BY r.reserved_at ASC'
);
$res_stmt->execute([$user_id]);
$pending_reservations_raw = $res_stmt->fetchAll();

// Build a map of book_id => [reservation rows for that book] to calculate positions
$book_reservations = [];
foreach ($pending_reservations_raw as $res) {
  $book_id = (int) $res['book_id'];
  if (!isset($book_reservations[$book_id])) {
    $book_reservations[$book_id] = [];
  }
  $book_reservations[$book_id][] = $res;
}

// Now calculate queue position for each reservation (O(n) instead of O(n²))
$pending_reservations = [];
foreach ($pending_reservations_raw as $res) {
  $book_id = (int) $res['book_id'];
  $position = 1;
  foreach ($book_reservations[$book_id] as $other_res) {
    if ($other_res['reserved_at'] < $res['reserved_at']) {
      $position++;
    }
  }
  $res['queue_position'] = $position;
  $pending_reservations[] = $res;
}

// ─── Feature 007 dashboard queries ──────────────────────────────────────────

// Q1: Currently borrowed count
$q1 = $pdo->prepare(
  "SELECT COUNT(*) AS cnt FROM Circulation WHERE user_id = ? AND status IN ('active', 'overdue')"
);
$q1->execute([$user_id]);
$currently_borrowed = (int) $q1->fetchColumn();

// Q2: Total loans ever
$q2 = $pdo->prepare('SELECT COUNT(*) AS cnt FROM Circulation WHERE user_id = ?');
$q2->execute([$user_id]);
$total_borrowed = (int) $q2->fetchColumn();

// Q3: Next return date + due-soon count (within 3 days)
$q3 = $pdo->prepare(
  "SELECT MIN(due_date) AS next_return,
          COUNT(*) AS due_soon_count
     FROM Circulation
    WHERE user_id = ?
      AND status IN ('active', 'overdue')
      AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)"
);
$q3->execute([$user_id]);
$due_row        = $q3->fetch();
$next_return    = $due_row['next_return'] ?? null;
$due_soon_count = (int) ($due_row['due_soon_count'] ?? 0);

// Q4: Pending reservations count (from already-fetched $pending_reservations)
$pending_count = count($pending_reservations);

// Q5: Category distribution for donut chart
$q5 = $pdo->prepare(
  "SELECT b.category, COUNT(*) AS cnt
     FROM Circulation c
     JOIN Books b ON c.book_id = b.id
    WHERE c.user_id = ?
    GROUP BY b.category
    ORDER BY cnt DESC"
);
$q5->execute([$user_id]);
$categories = $q5->fetchAll();

// Q6: Monthly checkout activity — last 12 months
$q6 = $pdo->prepare(
  "SELECT DATE_FORMAT(checkout_date, '%Y-%m') AS month, COUNT(*) AS cnt
     FROM Circulation
    WHERE user_id = ?
      AND checkout_date >= DATE_SUB(NOW(), INTERVAL 11 MONTH)
    GROUP BY month"
);
$q6->execute([$user_id]);
$monthly_raw = [];
foreach ($q6->fetchAll() as $row) {
  $monthly_raw[$row['month']] = (int) $row['cnt'];
}
// Fill all 12 slots even when no loans that month
$month_labels = [];
$month_values = [];
for ($i = 11; $i >= 0; $i--) {
  $key            = date('Y-m', strtotime("-{$i} months"));
  $month_labels[] = date('M Y', strtotime("-{$i} months"));
  $month_values[] = $monthly_raw[$key] ?? 0;
}

// Q7: Reading timeline — last 10 loans
$q7 = $pdo->prepare(
  "SELECT b.title, c.checkout_date, c.due_date, c.return_date, c.status
     FROM Circulation c
     JOIN Books b ON c.book_id = b.id
    WHERE c.user_id = ?
    ORDER BY c.checkout_date DESC
    LIMIT 10"
);
$q7->execute([$user_id]);
$timeline = $q7->fetchAll();

$name       = htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8');
$logout_url = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');

$current_page = 'borrower.index';
$pageTitle    = 'My Account | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>

    <main class="main-content borrower-dashboard">
      <section class="borrower-hero">
        <div class="page-header borrower-hero__content">
          <h1>My Dashboard</h1>
          <p>Welcome back, <?= $name ?>. Browse catalog or review your account details below.</p>
        </div>
        <div class="borrower-hero__actions" aria-label="Dashboard quick actions">
          <a class="btn-ghost is-current" href="<?= htmlspecialchars(BASE_URL . 'borrower/index.php', ENT_QUOTES, 'UTF-8') ?>" aria-current="page">Dashboard</a>
          <a class="btn-ghost" href="<?= htmlspecialchars(BASE_URL . 'borrower/catalog.php', ENT_QUOTES, 'UTF-8') ?>">Browse Catalog</a>
        </div>
      </section>

      <section class="borrower-overview" aria-labelledby="borrower-overview-title">
        <h2 id="borrower-overview-title" class="borrower-section-heading">Overview</h2>
        <p class="borrower-section-subtitle">Track current loans, upcoming due dates, and reservation activity at a glance.</p>

        <?php if ($flash_error !== ''): ?>
          <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flash_success !== ''): ?>
          <div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- ── Stat Cards ─────────────────────────────────────────────────────── -->
        <div class="dash-stats">
          <div class="stat-card">
            <div class="stat-card__label">Currently Borrowed</div>
            <div class="stat-card__value"><?= $currently_borrowed ?></div>
            <div class="stat-card__sub">active &amp; overdue</div>
          </div>
          <div class="stat-card">
            <div class="stat-card__label">Total Books Read</div>
            <div class="stat-card__value"><?= $total_borrowed ?></div>
            <div class="stat-card__sub">all time</div>
          </div>
          <div class="stat-card<?= $due_soon_count > 0 ? ' stat-card--warning' : '' ?>">
            <div class="stat-card__label">Due Soon</div>
            <div class="stat-card__value"><?= $due_soon_count ?></div>
            <div class="stat-card__sub">within 3 days</div>
          </div>
          <div class="stat-card">
            <div class="stat-card__label">Reservations</div>
            <div class="stat-card__value"><?= $pending_count ?></div>
            <div class="stat-card__sub">pending</div>
          </div>
        </div>

      </section>

      <?php if ($due_soon_count > 0): ?>
        <div class="alert-due-soon">
          <?= $due_soon_count === 1 ? '1 book is' : $due_soon_count . ' books are' ?> due
          <?php if ($next_return !== null): ?>
            — next return: <strong><?= htmlspecialchars(date('d M Y', strtotime($next_return)), ENT_QUOTES, 'UTF-8') ?></strong>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- ── Charts ─────────────────────────────────────────────────────────── -->
      <section class="borrower-charts" aria-labelledby="borrower-insights-title">
        <h2 id="borrower-insights-title" class="borrower-section-heading">Reading Insights</h2>
        <p class="borrower-section-subtitle">Your category mix and monthly borrowing pace from recent activity.</p>

        <div class="dash-charts">
          <div class="chart-card">
            <div class="chart-card__title">Books by Category</div>
            <div class="chart-wrapper">
              <canvas id="categoryChart" aria-label="Books borrowed by category" role="img"></canvas>
            </div>
            <?php if (empty($categories)): ?>
              <p style="text-align:center;color:var(--ink-muted);font-size:var(--text-sm);padding:var(--space-4)">
                Borrow some books to see your reading profile here.
              </p>
            <?php else: ?>
              <!-- Accessible text fallback for screen readers -->
              <table style="display:none;width:100%;" role="presentation" aria-label="Books borrowed by category data">
                <caption style="font-weight:bold;text-align:left;padding:var(--space-2);">Books by Category</caption>
                <thead>
                  <tr>
                    <th style="text-align:left;padding:var(--space-2);">Category</th>
                    <th style="text-align:right;padding:var(--space-2);">Count</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($categories as $cat): ?>
                    <tr>
                      <td style="text-align:left;padding:var(--space-2);"><?= htmlspecialchars($cat['category'] ?: 'Uncategorised', ENT_QUOTES, 'UTF-8') ?></td>
                      <td style="text-align:right;padding:var(--space-2);"><?= (int)$cat['cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
          <div class="chart-card">
            <div class="chart-card__title">Monthly Activity (last 12 months)</div>
            <div class="chart-wrapper">
              <canvas id="monthlyChart" aria-label="Books checked out per month" role="img"></canvas>
            </div>
            <!-- Accessible text fallback for screen readers -->
            <table style="display:none;width:100%;" role="presentation" aria-label="Books checked out per month data">
              <caption style="font-weight:bold;text-align:left;padding:var(--space-2);">Monthly Activity (last 12 months)</caption>
              <thead>
                <tr>
                  <th style="text-align:left;padding:var(--space-2);">Month</th>
                  <th style="text-align:right;padding:var(--space-2);">Books Borrowed</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($month_labels as $idx => $month): ?>
                  <tr>
                    <td style="text-align:left;padding:var(--space-2);"><?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="text-align:right;padding:var(--space-2);"><?= (int)($month_values[$idx] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ── Reading Timeline ────────────────────────────────────────────────── -->
      <?php if (!empty($timeline)): ?>
        <div class="section-card">
          <div class="section-card__header">
            <span class="section-card__title">Reading Timeline</span>
          </div>
          <ul class="timeline-list">
            <?php foreach ($timeline as $item): ?>
              <?php
              $badgeClass = match ($item['status']) {
                'active'   => 'timeline-badge--active',
                'overdue'  => 'timeline-badge--overdue',
                'returned' => 'timeline-badge--returned',
                default    => '',
              };
              $dateLabel = $item['return_date']
                ? date('d M Y', strtotime($item['return_date']))
                : 'Due ' . date('d M Y', strtotime($item['due_date']));
              ?>
              <li class="timeline-item">
                <span class="timeline-badge <?= $badgeClass ?>"></span>
                <span class="timeline-item__title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="timeline-item__date"><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- ── Active Loans ───────────────────────────────────────────────────── -->
      <section class="borrower-records" aria-labelledby="borrower-records-title">
        <h2 id="borrower-records-title" class="borrower-section-heading">Loan Records</h2>
        <p class="borrower-section-subtitle">Review active checkouts and recent returns without leaving this page.</p>
        <div class="borrower-records__grid">

          <div class="section-card">
            <div class="section-card__header">
              <span class="section-card__title">Active Loans</span>
            </div>
            <?php if (empty($active_loans)): ?>
              <div class="empty-state">
                <div class="empty-state__icon">📚</div>
                <p>No active or overdue loans.</p>
              </div>
            <?php else: ?>
              <div class="tbl-wrapper">
                <table class="tbl">
                  <thead>
                    <tr>
                      <th>Loan #</th>
                      <th>Book</th>
                      <th>Checked Out</th>
                      <th>Due Date</th>
                      <th>Status</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($active_loans as $loan): ?>
                      <tr<?= $loan['status'] === 'overdue' ? ' class="row-overdue"' : '' ?>>
                        <td><?= (int) $loan['id'] ?></td>
                        <td><?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($loan['checkout_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($loan['due_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                          <?php if ($loan['status'] === 'overdue'): ?>
                            <span class="badge badge-amber">Overdue</span>
                          <?php else: ?>
                            <span class="badge badge-blue">Active</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/renew.php', ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                            <button type="submit" class="btn-confirm" style="padding:5px 12px; font-size:.8125rem;">Renew</button>
                          </form>
                        </td>
                        </tr>
                      <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- ── Loan History ───────────────────────────────────────────────────── -->
          <div class="section-card">
            <div class="section-card__header">
              <span class="section-card__title">Loan History (last 20)</span>
            </div>
            <?php if (empty($loan_history)): ?>
              <div class="empty-state">
                <div class="empty-state__icon">📖</div>
                <p>No past loans on record.</p>
              </div>
            <?php else: ?>
              <div class="tbl-wrapper">
                <table class="tbl">
                  <thead>
                    <tr>
                      <th>Book</th>
                      <th>Returned</th>
                      <th>Fine</th>
                      <th>Fine Paid</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($loan_history as $h): ?>
                      <tr>
                        <td><?= htmlspecialchars($h['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($h['return_date'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(number_format((float) $h['fine_amount'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                          <?php if ($h['fine_paid']): ?>
                            <span class="badge badge-green">Yes</span>
                          <?php else: ?>
                            <span class="badge badge-red">No</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- ── Pending Reservations ───────────────────────────────────────────── -->
      <div class="section-card" id="pending-reservations">
        <div class="section-card__header">
          <span class="section-card__title">Pending Reservations</span>
        </div>
        <?php if (empty($pending_reservations)): ?>
          <div class="empty-state">
            <div class="empty-state__icon">🔖</div>
            <p>No pending reservations.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Book</th>
                  <th>Reserved</th>
                  <th>Expires</th>
                  <th>Queue #</th>
                  <th>Cancel</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pending_reservations as $res): ?>
                  <tr>
                    <td><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($res['reserved_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($res['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $res['queue_position'] ?></td>
                    <td>
                      <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="reservation_id" value="<?= (int) $res['id'] ?>">
                        <button type="submit" class="btn-accent" style="padding:5px 12px; font-size:.8125rem;">Cancel</button>
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

  <!-- ── Chart.js ─────────────────────────────────────────────────────────── -->
  <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>assets/js/chart.min.js"></script>
  <script>
    (function() {
      if (typeof Chart === 'undefined') {
        return;
      }

      // ── Donut: Books by Category ───────────────────────────────────────────
      var catCtx = document.getElementById('categoryChart');
      if (catCtx) {
        var catLabels = <?= json_encode(array_map(fn($r) => $r['category'] ?: 'Uncategorised', $categories), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var catValues = <?= json_encode(array_map(fn($r) => (int) $r['cnt'], $categories), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        new Chart(catCtx, {
          type: 'doughnut',
          data: {
            labels: catLabels,
            datasets: [{
              data: catValues,
              backgroundColor: [
                '#4a6741', '#c9a84c', '#c8401a', '#768a70',
                '#7f6a3d', '#b95a39', '#a6b19a', '#e6d7b8'
              ],
              borderWidth: 2,
              borderColor: '#fff'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'bottom',
                labels: {
                  padding: 16,
                  font: {
                    size: 13
                  }
                }
              }
            }
          }
        });
      }

      // ── Bar: Monthly Activity ──────────────────────────────────────────────
      var monthCtx = document.getElementById('monthlyChart');
      if (monthCtx) {
        var monthLabels = <?= json_encode($month_labels, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var monthValues = <?= json_encode($month_values, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        new Chart(monthCtx, {
          type: 'bar',
          data: {
            labels: monthLabels,
            datasets: [{
              label: 'Books Borrowed',
              data: monthValues,
              backgroundColor: 'rgba(74,103,65,0.78)',
              borderColor: 'rgba(59,85,52,1)',
              borderWidth: 1,
              borderRadius: 4
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  stepSize: 1,
                  precision: 0
                },
                grid: {
                  color: 'rgba(0,0,0,0.05)'
                }
              },
              x: {
                grid: {
                  display: false
                },
                ticks: {
                  font: {
                    size: 11
                  },
                  maxRotation: 45,
                  minRotation: 30
                }
              }
            },
            plugins: {
              legend: {
                display: false
              }
            }
          }
        });
      }
    }());
  </script>
</body>

</html>
