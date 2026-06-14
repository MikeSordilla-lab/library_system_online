<?php

/**
 * borrower/catalog.php — Borrower Catalog
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();
$currentUserId = (int) $_SESSION['user_id'];
expire_stale_reservations($pdo);

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filter_category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$filter_availability = isset($_GET['availability']) ? trim((string) $_GET['availability']) : '';
$sort = isset($_GET['sort']) ? trim((string) $_GET['sort']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 12;

$catStmt = $pdo->query('SELECT DISTINCT category FROM Books WHERE category IS NOT NULL AND category != \'\' ORDER BY category ASC');
$allCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$conditions = [];
$params = [];
$availabilityJoinSql = BookAvailabilityModule::catalogApprovedHoldJoinSql('b', 'availability_holds');
$approvedHoldsSql = BookAvailabilityModule::approvedHoldsSql('availability_holds');
$reservableCopiesSql = BookAvailabilityModule::reservableCopiesSql('b', 'availability_holds');

if ($q !== '') {
	$like = '%' . $q . '%';
	$conditions[] = '(b.title LIKE :q1 OR b.author LIKE :q2 OR b.isbn LIKE :q3 OR b.category LIKE :q4)';
	$params[':q1'] = $like;
	$params[':q2'] = $like;
	$params[':q3'] = $like;
	$params[':q4'] = $like;
}

if ($filter_category !== '') {
	$conditions[] = 'b.category = :cat';
	$params[':cat'] = $filter_category;
}

if ($filter_availability === 'available') {
	$conditions[] = '(' . $reservableCopiesSql . ') > 0';
} elseif ($filter_availability === 'not_available') {
	$conditions[] = '(' . $reservableCopiesSql . ') = 0';
}

$whereSql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

$orderSql = ' ORDER BY b.title ASC';
if ($sort === 'newest') {
	$orderSql = ' ORDER BY b.id DESC';
} elseif ($sort === 'popular') {
	$orderSql = ' ORDER BY b.total_copies DESC, b.title ASC';
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM Books b' . $availabilityJoinSql . $whereSql);
foreach ($params as $key => $value) {
	$countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();
$totalBooks = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalBooks / $perPage));
if ($page > $totalPages) {
	$page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listSql =
	'SELECT b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
					' . $approvedHoldsSql . ' AS approved_holds,
					' . $reservableCopiesSql . ' AS reservable_copies,
					CASE WHEN b.total_copies >= 1 AND ' . $reservableCopiesSql . ' > 0 THEN 1 ELSE 0 END AS has_reservable_copy,
					EXISTS(SELECT 1 FROM book_covers bc WHERE bc.book_id = b.id) AS has_cover,
					EXISTS(
						SELECT 1
						FROM Reservations r
						WHERE r.book_id = b.id
							AND r.user_id = :current_user_id
							AND r.status IN (\'pending\', \'approved\')
							AND r.expires_at >= NOW()
					) AS has_active_reservation
	 FROM Books b' .
	$availabilityJoinSql .
	$whereSql .
	$orderSql .
	' LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
	$stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$books = $stmt->fetchAll();

$flash_error = $_SESSION['flash_error'] ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_receipt_no = (string) ($_SESSION['flash_receipt_no'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_receipt_no']);

$qEscaped = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
$current_page = 'borrower.catalog';
$pageTitle = 'Browse Catalog | LibraLink';
$extraStyles = [
	'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
	BASE_URL . 'assets/css/borrower-redesign.css'
];

$shownStart = $totalBooks > 0 ? $offset + 1 : 0;
$shownEnd = $totalBooks > 0 ? $offset + count($books) : 0;

$buildCatalogUrl = function (int $page) use ($q, $filter_category, $filter_availability, $sort): string {
	$query = ['page' => $page];
	if ($q !== '') $query['q'] = $q;
	if ($filter_category !== '') $query['category'] = $filter_category;
	if ($filter_availability !== '') $query['availability'] = $filter_availability;
	if ($sort !== '') $query['sort'] = $sort;
	return 'catalog.php?' . http_build_query($query);
};

$currentCatalogRedirect = 'borrower/' . $buildCatalogUrl($page);
$activeLoanStmt = $pdo->prepare('SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\')');
$activeLoanStmt->execute([$currentUserId]);
$currentLoanCount = (int) $activeLoanStmt->fetchColumn();

$activeReservationStmt = $pdo->prepare('SELECT COUNT(*) FROM Reservations WHERE user_id = ? AND status IN (?, ?)');
$activeReservationStmt->execute([$currentUserId, RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED]);
$currentReservationCount = (int) $activeReservationStmt->fetchColumn();

$maxBorrowLimit = (int) get_setting($pdo, 'max_borrow_limit', '3');
$remainingReservationSlots = max(0, $maxBorrowLimit - $currentLoanCount - $currentReservationCount);

?>
<!DOCTYPE html>
<html lang="en">

<head>
	<?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body class="dashboard-redesign borrower-dashboard-new">
	<div class="app-shell">
		<?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>

		<main class="main-content borrower-catalog-page">
			<section class="catalog-layout" aria-labelledby="catalog-title">
				<div class="rd-header">
					<div>
						<h1 id="catalog-title">Browse Catalog</h1>
						<p>Discover books and reserve in one step.</p>
					</div>
					<div class="catalog-header-actions">
						<a class="rd-btn" style="background:var(--rd-surface); border:1px solid var(--rd-border); color:var(--rd-text);" href="<?= htmlspecialchars(BASE_URL . 'borrower/index.php', ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
					</div>
				</div>

				<section class="catalog-search" aria-label="Search and filter catalog" style="margin-bottom: 2.5rem;">
					<form class="catalog-search__form catalog-filters rd-card" method="GET" action="" style="display: flex; flex-direction: column; gap: 1.25rem;">
						<div class="catalog-filters__primary" style="display: flex; gap: 1rem; width: 100%; align-items: center;">
							<label class="sr-only" for="catalog-search-input">Search by title, author, ISBN</label>
							<div style="position:relative; flex:1;">
								<svg style="position:absolute; left:1rem; top:50%; transform:translateY(-50%); color:var(--rd-text-muted);" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
								<input id="catalog-search-input" type="text" name="q" placeholder="Search title, author, or ISBN" value="<?= $qEscaped ?>" style="width:100%; padding: 0.85rem 1rem 0.85rem 2.75rem; border-radius: 12px; border: 1px solid var(--rd-border); background-color: var(--rd-surface); color: var(--rd-text); font-family: inherit; font-size: 1rem; outline: none; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);">
							</div>
							<button type="submit" class="rd-btn rd-btn-primary" style="padding: 0.85rem 1.75rem; font-size: 1rem; border:none; border-radius:12px; cursor:pointer;">Search</button>
						</div>
						<div class="catalog-filters__secondary" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
							<select name="category" aria-label="Filter by category" style="padding: 0.6rem 2.25rem 0.6rem 1.25rem; border-radius: 10px; border: 1px solid var(--rd-border); background-color: var(--rd-surface); color: var(--rd-text); font-family: inherit; font-size: 0.95rem; outline: none; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill=%22none%22 stroke=%22%238a8278%22 viewBox=%220 0 24 24%22 xmlns=%22http://www.w3.org/2000/svg%22><path stroke-linecap=%22round%22 stroke-linejoin=%22round%22 stroke-width=%222%22 d=%22M19 9l-7 7-7-7%22></path></svg>'); background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 16px; transition: all 0.2s;">
								<option value="">All Categories</option>
								<?php foreach ($allCategories as $cat): ?>
									<option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_category === $cat ? 'selected' : '' ?>>
										<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
									</option>
								<?php endforeach; ?>
							</select>

							<select name="availability" aria-label="Filter by availability" style="padding: 0.6rem 2.25rem 0.6rem 1.25rem; border-radius: 10px; border: 1px solid var(--rd-border); background-color: var(--rd-surface); color: var(--rd-text); font-family: inherit; font-size: 0.95rem; outline: none; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill=%22none%22 stroke=%22%238a8278%22 viewBox=%220 0 24 24%22 xmlns=%22http://www.w3.org/2000/svg%22><path stroke-linecap=%22round%22 stroke-linejoin=%22round%22 stroke-width=%222%22 d=%22M19 9l-7 7-7-7%22></path></svg>'); background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 16px; transition: all 0.2s;">
								<option value="">Any Availability</option>
								<option value="available" <?= $filter_availability === 'available' ? 'selected' : '' ?>>Available</option>
								<option value="not_available" <?= $filter_availability === 'not_available' ? 'selected' : '' ?>>Not Available</option>
							</select>

							<select name="sort" aria-label="Sort configuration" style="padding: 0.6rem 2.25rem 0.6rem 1.25rem; border-radius: 10px; border: 1px solid var(--rd-border); background-color: var(--rd-surface); color: var(--rd-text); font-family: inherit; font-size: 0.95rem; outline: none; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill=%22none%22 stroke=%22%238a8278%22 viewBox=%220 0 24 24%22 xmlns=%22http://www.w3.org/2000/svg%22><path stroke-linecap=%22round%22 stroke-linejoin=%22round%22 stroke-width=%222%22 d=%22M19 9l-7 7-7-7%22></path></svg>'); background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 16px; transition: all 0.2s;">
								<option value="">Sort: A-Z</option>
								<option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Sort: Newest</option>
								<option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Sort: Popular</option>
							</select>

							<?php if ($q !== '' || $filter_category !== '' || $filter_availability !== '' || $sort !== ''): ?>
								<a href="catalog.php" class="rd-btn" style="padding: 0.6rem 1.25rem; font-size: 0.95rem; border: 1px solid var(--rd-border); background: var(--rd-surface); color: var(--rd-text-muted); text-decoration: none;">Clear Filters</a>
							<?php endif; ?>
						</div>
					</form>
				</section>

				<?php if ($flash_error !== ''): ?>
					<div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true">
						<?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?>
					</div>
				<?php endif; ?>
				<?php if ($flash_success !== ''): ?>
					<div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true">
						<?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?>
					</div>
				<?php endif; ?>
				<?php
				$receipt_modal_title = 'Reservation ticket ready';
				$receipt_modal_message = 'Your reservation was submitted successfully. Print the reservation ticket from this overlay.';
				$receipt_modal_print_label = 'Print Reservation Ticket';
				require __DIR__ . '/../includes/receipt-success-modal.php';
				?>
				<div class="catalog-results" role="status" aria-live="polite">
					<?php if ($q !== ''): ?>
						Showing <strong><?= (int) $shownStart ?></strong>-<strong><?= (int) $shownEnd ?></strong> of <strong><?= (int) $totalBooks ?></strong> results for "<?= $qEscaped ?>"
					<?php else: ?>
						Showing <strong><?= (int) $shownStart ?></strong>-<strong><?= (int) $shownEnd ?></strong> of <strong><?= (int) $totalBooks ?></strong> books
					<?php endif; ?>
				</div>

				<section class="catalog-grid rd-stagger" aria-label="Catalog books">

					<?php if (empty($books)): ?>
						<div class="catalog-empty" role="status" aria-live="polite">
							<div class="catalog-empty__icon" aria-hidden="true">Books</div>
							<h2>No books found</h2>
							<p><?= $q !== '' ? 'Try another search term.' : 'The catalog is currently empty.' ?></p>
						</div>
					<?php else: ?>
						<?php foreach ($books as $book): ?>
							<?php
							$reservableCopies = (int) ($book['reservable_copies'] ?? $book['available_copies']);
							$physicalCopies = (int) ($book['available_copies'] ?? 0);
							$hasReservableCopy = !empty($book['has_reservable_copy']);
							?>
							<article class="catalog-book" aria-label="<?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?>">
								<div class="catalog-book__cover-wrap">
									<?php if (!empty($book['has_cover'])): ?>
										<img
											class="catalog-book__cover"
											src="<?= BASE_URL ?>public/book-cover.php?book_id=<?= (int) $book['id'] ?>"
											alt="Cover of <?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?>"
											width="180"
											height="280"
											loading="lazy"
											decoding="async"
											data-fallback-src="<?= BASE_URL ?>assets/images/placeholder-book.png"
										>
									<?php else: ?>
										<img
											class="catalog-book__cover"
											src="<?= BASE_URL ?>assets/images/placeholder-book.png"
											alt="No cover available for <?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?>"
											width="180"
											height="280"
											loading="lazy"
											decoding="async"
										>
									<?php endif; ?>

									<?php if ($hasReservableCopy): ?>
										<span class="catalog-book__badge catalog-book__badge--available">Available (<?= $reservableCopies ?>)</span>
									<?php elseif ($physicalCopies > 0): ?>
										<span class="catalog-book__badge catalog-book__badge--unavailable">Reserved for Pickup</span>
									<?php else: ?>
										<span class="catalog-book__badge catalog-book__badge--unavailable">Unavailable</span>
									<?php endif; ?>
								</div>

								<div class="catalog-book__body">
									<h2 class="catalog-book__title"><?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?></h2>
									<p class="catalog-book__author">by <?= htmlspecialchars($book['author'], ENT_QUOTES, 'UTF-8') ?></p>

									<div class="catalog-book__meta">
										<span class="catalog-book__category"><?= htmlspecialchars($book['category'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="catalog-book__isbn">ISBN <?= htmlspecialchars($book['isbn'], ENT_QUOTES, 'UTF-8') ?></span>
									</div>

									<div class="catalog-book__action">
										<?php if (!empty($book['has_active_reservation'])): ?>
											<button type="button" class="catalog-book__btn catalog-book__btn--disabled" disabled aria-disabled="true">Already Reserved</button>
										<?php elseif ($hasReservableCopy): ?>
											<button
												type="button"
												class="catalog-book__bulk-check js-book-select"
												data-book-id="<?= (int) $book['id'] ?>"
												data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?>"
												aria-label="Select <?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?> for bulk reservation"
												aria-pressed="false"
												<?= $remainingReservationSlots < 1 ? 'disabled aria-disabled="true"' : '' ?>>
												<span class="catalog-book__bulk-icon" aria-hidden="true">
													<svg viewBox="0 0 24 24" focusable="false">
														<path d="M5 12.5l4.2 4.2L19 7" />
													</svg>
												</span>
												<span>Select for Reservation</span>
											</button>
											<button type="button" class="btn-confirm catalog-book__btn js-reserve-btn" data-book-id="<?= (int) $book['id'] ?>" data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?>">Reserve This Book</button>
										<?php else: ?>
											<button type="button" class="catalog-book__btn catalog-book__btn--disabled" disabled aria-disabled="true">Join Waitlist (Unavailable)</button>
										<?php endif; ?>
									</div>
								</div>
							</article>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

				<?php if ($totalPages > 1): ?>
					<nav class="catalog-pagination" aria-label="Catalog pagination">
						<?php if ($page > 1): ?>
							<a class="btn-ghost" href="<?= htmlspecialchars($buildCatalogUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to previous page">&larr; Previous</a>
						<?php else: ?>
							<span class="catalog-pagination__disabled" aria-disabled="true">&larr; Previous</span>
						<?php endif; ?>

						<div class="catalog-pagination__pages">
							<?php
							$startPage = max(1, $page - 2);
							$endPage = min($totalPages, $page + 2);
							for ($i = $startPage; $i <= $endPage; $i++):
							?>
								<?php if ($i === $page): ?>
									<span class="catalog-pagination__current" aria-current="page"><?= (int) $i ?></span>
								<?php else: ?>
									<a class="catalog-pagination__link" href="<?= htmlspecialchars($buildCatalogUrl($i), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $i ?></a>
								<?php endif; ?>
							<?php endfor; ?>
						</div>

						<?php if ($page < $totalPages): ?>
							<a class="btn-ghost" href="<?= htmlspecialchars($buildCatalogUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to next page">Next &rarr;</a>
						<?php else: ?>
							<span class="catalog-pagination__disabled" aria-disabled="true">Next &rarr;</span>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</section>

			<!-- Reservation Confirm Modal -->
			<div id="reserve-modal" class="rd-modal" style="display: none;" aria-hidden="true">
				<div class="rd-modal-backdrop" id="reserve-modal-close"></div>
				<div class="rd-modal-panel rd-card">
					<h2 style="margin-top:0; color:var(--rd-primary);">Confirm Reservation</h2>
					<p style="color:var(--rd-text-muted); margin-bottom:1.5rem;">Are you sure you want to reserve <strong id="reserve-modal-title" style="color:var(--rd-text-bold);"></strong>?</p>
					<form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>" style="display:flex; justify-content:flex-end; gap:0.75rem;">
						<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
						<input type="hidden" name="action" value="place">
						<input type="hidden" name="book_id" id="reserve-modal-book-id" value="">
						<input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentCatalogRedirect, ENT_QUOTES, 'UTF-8') ?>">
						<button type="button" class="rd-btn" style="background:transparent; border:1px solid var(--rd-border); color:var(--rd-text);" id="reserve-modal-cancel">Cancel</button>
						<button type="submit" class="rd-btn rd-btn-primary">Confirm</button>
					</form>
				</div>
			</div>

			<div id="bulk-reserve-modal" class="rd-modal" style="display: none;" aria-hidden="true">
				<div class="rd-modal-backdrop" id="bulk-reserve-modal-close"></div>
				<div class="rd-modal-panel rd-card">
					<h2 style="margin-top:0; color:var(--rd-primary);">Confirm Selected Reservations</h2>
					<p style="color:var(--rd-text-muted); margin-bottom:1rem;">Reserve these selected books?</p>
					<ul id="bulk-reserve-list" class="bulk-reserve-list"></ul>
					<form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>" id="bulk-reserve-form" style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.25rem;">
						<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
						<input type="hidden" name="action" value="place_bulk">
						<input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentCatalogRedirect, ENT_QUOTES, 'UTF-8') ?>">
						<div id="bulk-reserve-inputs"></div>
						<button type="button" class="rd-btn" style="background:transparent; border:1px solid var(--rd-border); color:var(--rd-text);" id="bulk-reserve-modal-cancel">Cancel</button>
						<button type="submit" class="rd-btn rd-btn-primary">Reserve Selected</button>
					</form>
				</div>
			</div>

			<div class="bulk-selection-tray" id="bulk-selection-tray" hidden>
				<button type="button" class="bulk-selection-tray__toggle" id="bulk-selection-tray-toggle" aria-expanded="false" aria-controls="bulk-selection-tray-panel" aria-label="Show selected books">
					<svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
						<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Z" />
						<path d="M4 5.5A2.5 2.5 0 0 1 6.5 8H20" />
					</svg>
					<strong id="bulk-selection-tray-count">0</strong>
				</button>
				<div class="bulk-selection-tray__panel" id="bulk-selection-tray-panel" hidden>
					<div class="bulk-selection-tray__header">
						<strong>Selected Books</strong>
						<button type="button" id="bulk-selection-tray-close" aria-label="Close selected books panel">&times;</button>
					</div>
					<ul id="bulk-selection-tray-list" class="bulk-selection-tray__list"></ul>
					<div class="bulk-selection-tray__actions">
						<button type="button" class="rd-btn" id="bulk-selection-tray-clear">Clear</button>
						<button type="button" class="rd-btn rd-btn-primary" id="bulk-selection-tray-reserve">Reserve</button>
					</div>
				</div>
			</div>
		</main>
	</div>

	<style>
		.catalog-header-actions {
			display: flex;
			gap: 0.75rem;
			flex-wrap: wrap;
			justify-content: flex-end;
		}
		.rd-modal {
			position: fixed;
			inset: 0;
			z-index: 9999;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 1rem;
		}
		.rd-modal-backdrop {
			position: absolute;
			inset: 0;
			background: rgba(15, 14, 12, 0.7);
			backdrop-filter: blur(4px);
		}
		.rd-modal-panel {
			position: relative;
			width: 100%;
			max-width: 400px;
			z-index: 10000;
		}
		.bulk-reserve-list {
			margin: 0;
			padding-left: 1.25rem;
			color: var(--rd-text);
			max-height: 220px;
			overflow: auto;
		}
		.catalog-book__cover-wrap {
			position: relative;
		}
		.catalog-book.is-selected {
			border-color: var(--rd-primary);
			box-shadow: 0 0 0 2px rgba(201, 168, 76, 0.25), var(--rd-shadow);
		}
		.catalog-book__bulk-check {
			width: 100%;
			min-height: 46px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 0.65rem;
			border-radius: 10px;
			border: 1px solid rgba(201, 168, 76, 0.45);
			background: rgba(201, 168, 76, 0.08);
			color: var(--rd-text);
			cursor: pointer;
			font: inherit;
			font-weight: 700;
			transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
		}
		.catalog-book__bulk-icon {
			width: 22px;
			height: 22px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			border-radius: 999px;
			border: 1px solid rgba(236, 232, 219, 0.55);
			color: transparent;
			flex: 0 0 auto;
		}
		.catalog-book__bulk-check svg {
			width: 16px;
			height: 16px;
			fill: none;
			stroke: currentColor;
			stroke-width: 3;
			stroke-linecap: round;
			stroke-linejoin: round;
		}
		.catalog-book__bulk-check:hover {
			transform: translateY(-1px);
			border-color: var(--rd-primary);
			background: rgba(201, 168, 76, 0.14);
		}
		.catalog-book__bulk-check.is-selected {
			background: var(--rd-primary);
			border-color: var(--rd-primary);
			color: #1c1a17;
			box-shadow: 0 8px 18px rgba(201, 168, 76, 0.2);
		}
		.catalog-book__bulk-check.is-selected .catalog-book__bulk-icon {
			border-color: rgba(28, 26, 23, 0.45);
			background: rgba(28, 26, 23, 0.14);
			color: #1c1a17;
		}
		.bulk-selection-tray {
			position: fixed;
			right: 1.25rem;
			bottom: 1.25rem;
			z-index: 30;
			display: grid;
			gap: 0.75rem;
			justify-items: end;
		}
		.bulk-selection-tray__toggle {
			width: 54px;
			height: 54px;
			display: inline-grid;
			place-items: center;
			border: 1px solid rgba(201, 168, 76, 0.55);
			border-radius: 999px;
			background: var(--rd-primary);
			color: #1c1a17;
			box-shadow: 0 14px 28px rgba(0, 0, 0, 0.35);
			cursor: pointer;
			position: relative;
			font: inherit;
			font-weight: 800;
		}
		.bulk-selection-tray__toggle svg {
			width: 26px;
			height: 26px;
			fill: none;
			stroke: currentColor;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}
		.bulk-selection-tray__toggle strong {
			position: absolute;
			top: -6px;
			right: -6px;
			min-width: 24px;
			height: 24px;
			padding: 0 6px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			border-radius: 999px;
			background: #1c1a17;
			color: var(--rd-primary);
			border: 1px solid rgba(201, 168, 76, 0.5);
			font-size: 0.75rem;
		}
		.bulk-selection-tray__panel {
			width: min(320px, calc(100vw - 2.5rem));
			padding: 1rem;
			border: 1px solid var(--rd-border);
			border-radius: 14px;
			background: rgba(28, 26, 23, 0.96);
			backdrop-filter: blur(16px);
			box-shadow: var(--rd-shadow-hover);
		}
		.bulk-selection-tray__header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 1rem;
			margin-bottom: 0.75rem;
		}
		.bulk-selection-tray__header button {
			width: 32px;
			height: 32px;
			border: 1px solid var(--rd-border);
			border-radius: 999px;
			background: transparent;
			color: var(--rd-text);
			cursor: pointer;
			font-size: 1.25rem;
			line-height: 1;
		}
		.bulk-selection-tray__list {
			display: grid;
			gap: 0.5rem;
			max-height: 220px;
			overflow: auto;
			margin: 0 0 0.9rem;
			padding: 0;
			list-style: none;
		}
		.bulk-selection-tray__list li {
			display: grid;
			grid-template-columns: minmax(0, 1fr) auto;
			align-items: center;
			gap: 0.75rem;
			color: var(--rd-text);
			font-size: 0.9rem;
		}
		.bulk-selection-tray__list button {
			border: 1px solid rgba(255, 255, 255, 0.12);
			border-radius: 999px;
			background: rgba(255, 255, 255, 0.05);
			color: var(--rd-text-muted);
			cursor: pointer;
			width: 28px;
			height: 28px;
			line-height: 1;
		}
		.bulk-selection-tray__actions {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 0.75rem;
		}
		.bulk-selection-tray__actions .rd-btn {
			justify-content: center;
			padding-inline: 1rem;
		}
		@media (max-width: 1024px) {
			.bulk-selection-tray {
				right: 1rem;
			}
		}
		@media (max-width: 640px) {
			.bulk-selection-tray {
				right: 1rem;
				bottom: 1rem;
			}
		}
	</style>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			var modal = document.getElementById('reserve-modal');
			var titleEl = document.getElementById('reserve-modal-title');
			var idInput = document.getElementById('reserve-modal-book-id');
			var cancelBtn = document.getElementById('reserve-modal-cancel');
			var closeBg = document.getElementById('reserve-modal-close');
			var bulkModal = document.getElementById('bulk-reserve-modal');
			var bulkCloseBg = document.getElementById('bulk-reserve-modal-close');
			var bulkCancel = document.getElementById('bulk-reserve-modal-cancel');
			var bulkList = document.getElementById('bulk-reserve-list');
			var bulkInputs = document.getElementById('bulk-reserve-inputs');
			var bulkForm = document.getElementById('bulk-reserve-form');
			var tray = document.getElementById('bulk-selection-tray');
			var trayToggle = document.getElementById('bulk-selection-tray-toggle');
			var trayPanel = document.getElementById('bulk-selection-tray-panel');
			var trayClose = document.getElementById('bulk-selection-tray-close');
			var trayCount = document.getElementById('bulk-selection-tray-count');
			var trayList = document.getElementById('bulk-selection-tray-list');
			var trayClear = document.getElementById('bulk-selection-tray-clear');
			var trayReserve = document.getElementById('bulk-selection-tray-reserve');
			var selectButtons = Array.prototype.slice.call(document.querySelectorAll('.js-book-select'));
			var selectedBooks = new Map();
			var maxSelect = <?= (int) min(10, $remainingReservationSlots) ?>;
			var storageKey = 'libralink:borrower:<?= (int) $currentUserId ?>:bulkSelections';

			document.querySelectorAll('.js-reserve-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					titleEl.textContent = this.getAttribute('data-book-title');
					idInput.value = this.getAttribute('data-book-id');
					modal.style.display = 'flex';
					modal.setAttribute('aria-hidden', 'false');
				});
			});

			function closeModal() {
				modal.style.display = 'none';
				modal.setAttribute('aria-hidden', 'true');
			}

			if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
			if (closeBg) closeBg.addEventListener('click', closeModal);

			function saveSelection() {
				var books = [];
				selectedBooks.forEach(function(title, id) {
					books.push({ id: id, title: title });
				});
				try {
					sessionStorage.setItem(storageKey, JSON.stringify(books));
				} catch (error) {}
			}

			function loadSelection() {
				if (maxSelect < 1) {
					try {
						sessionStorage.removeItem(storageKey);
					} catch (error) {}
					return;
				}
				var raw = null;
				try {
					raw = sessionStorage.getItem(storageKey);
				} catch (error) {}
				if (!raw) return;

				try {
					JSON.parse(raw).forEach(function(book) {
						if (!book || !book.id || !book.title) return;
						if (maxSelect > 0 && selectedBooks.size >= maxSelect) return;
						selectedBooks.set(String(book.id), String(book.title));
					});
				} catch (error) {
					try {
						sessionStorage.removeItem(storageKey);
					} catch (removeError) {}
				}
			}

			function updateBulkBar() {
				var count = selectedBooks.size;
				if (tray) {
					tray.hidden = count < 1;
				}
				if (trayCount) {
					trayCount.textContent = String(count);
				}
				if (trayReserve) {
					trayReserve.disabled = count < 1;
				}
				if (count < 1 && trayPanel && trayToggle) {
					trayPanel.hidden = true;
					trayToggle.setAttribute('aria-expanded', 'false');
				}
				renderTrayList();
			}

			function setButtonSelected(button, selected) {
				var card = button.closest('.catalog-book');
				button.classList.toggle('is-selected', selected);
				button.setAttribute('aria-pressed', selected ? 'true' : 'false');
				if (card) {
					card.classList.toggle('is-selected', selected);
				}
			}

			function syncCurrentPageSelection() {
				selectButtons.forEach(function(button) {
					setButtonSelected(button, selectedBooks.has(button.getAttribute('data-book-id')));
				});
			}

			function renderTrayList() {
				if (!trayList) return;
				trayList.innerHTML = '';
				selectedBooks.forEach(function(title, id) {
					var item = document.createElement('li');
					var text = document.createElement('span');
					var remove = document.createElement('button');

					text.textContent = title;
					remove.type = 'button';
					remove.textContent = 'x';
					remove.setAttribute('aria-label', 'Remove ' + title + ' from selected books');
					remove.addEventListener('click', function() {
						selectedBooks.delete(id);
						saveSelection();
						syncCurrentPageSelection();
						updateBulkBar();
					});

					item.appendChild(text);
					item.appendChild(remove);
					trayList.appendChild(item);
				});
			}

			function clearSelection() {
				selectedBooks.clear();
				selectButtons.forEach(function(button) {
					setButtonSelected(button, false);
				});
				saveSelection();
				updateBulkBar();
			}

			selectButtons.forEach(function(button) {
				button.addEventListener('click', function() {
					if (maxSelect < 1) {
						alert('You have reached your active loan and reservation limit.');
						return;
					}

					var bookId = button.getAttribute('data-book-id');
					var bookTitle = button.getAttribute('data-book-title') || 'Selected book';

					if (selectedBooks.has(bookId)) {
						selectedBooks.delete(bookId);
						setButtonSelected(button, false);
						saveSelection();
						updateBulkBar();
						return;
					}

					if (selectedBooks.size >= maxSelect) {
						alert('You can select up to ' + maxSelect + ' more book' + (maxSelect === 1 ? '' : 's') + ' based on your borrowing limit.');
						return;
					}

					selectedBooks.set(bookId, bookTitle);
					setButtonSelected(button, true);
					saveSelection();
					updateBulkBar();
				});
			});

			function closeBulkModal() {
				if (!bulkModal) return;
				bulkModal.style.display = 'none';
				bulkModal.setAttribute('aria-hidden', 'true');
			}

			function openBulkModal() {
				if (selectedBooks.size < 1 || !bulkModal || !bulkList || !bulkInputs) {
					return;
				}

				bulkList.innerHTML = '';
				bulkInputs.innerHTML = '';
				selectedBooks.forEach(function(title, id) {
					var item = document.createElement('li');
					item.textContent = title;
					bulkList.appendChild(item);

					var input = document.createElement('input');
					input.type = 'hidden';
					input.name = 'book_ids[]';
					input.value = id;
					bulkInputs.appendChild(input);
				});

				bulkModal.style.display = 'flex';
				bulkModal.setAttribute('aria-hidden', 'false');
			}

			if (bulkCancel) bulkCancel.addEventListener('click', closeBulkModal);
			if (bulkCloseBg) bulkCloseBg.addEventListener('click', closeBulkModal);
			if (trayReserve) trayReserve.addEventListener('click', openBulkModal);
			if (trayClear) trayClear.addEventListener('click', clearSelection);
			if (trayToggle && trayPanel) {
				trayToggle.addEventListener('click', function() {
					var expanded = trayToggle.getAttribute('aria-expanded') === 'true';
					trayToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
					trayPanel.hidden = expanded;
				});
			}
			if (trayClose && trayPanel && trayToggle) {
				trayClose.addEventListener('click', function() {
					trayPanel.hidden = true;
					trayToggle.setAttribute('aria-expanded', 'false');
				});
			}
			if (bulkForm) {
				bulkForm.addEventListener('submit', function() {
					try {
						sessionStorage.removeItem(storageKey);
					} catch (error) {}
				});
			}

			loadSelection();
			syncCurrentPageSelection();
			updateBulkBar();
		});


		document.addEventListener('error', function(event) {
			var target = event.target;
			if (!target || target.tagName !== 'IMG') {
				return;
			}

			var fallback = target.getAttribute('data-fallback-src');
			if (!fallback || target.src.endsWith(fallback)) {
				return;
			}

			target.src = fallback;
		}, true);
	</script>
</body>

</html>
