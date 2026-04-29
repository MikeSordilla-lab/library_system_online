<?php

/**
 * borrower/catalog.php — Borrower Catalog
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';

$pdo = get_db();

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
	$conditions[] = 'b.available_copies > 0';
} elseif ($filter_availability === 'not_available') {
	$conditions[] = 'b.available_copies = 0';
}

$whereSql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

$orderSql = ' ORDER BY b.title ASC';
if ($sort === 'newest') {
	$orderSql = ' ORDER BY b.id DESC';
} elseif ($sort === 'popular') {
	$orderSql = ' ORDER BY b.total_copies DESC, b.title ASC';
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM Books b' . $whereSql);
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
					EXISTS(SELECT 1 FROM book_covers bc WHERE bc.book_id = b.id) AS has_cover
	 FROM Books b' .
	$whereSql .
	$orderSql .
	' LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
	$stmt->bindValue($key, $value, PDO::PARAM_STR);
}
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
$pageTitle = 'Browse Catalog | Library System';
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
					<div>
						<a class="rd-btn" style="background:var(--rd-surface); border:1px solid var(--rd-border); color:var(--rd-text);" href="<?= htmlspecialchars(BASE_URL . 'borrower/index.php', ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
					</div>
				</div>

				<section class="catalog-search" aria-label="Search and filter catalog" style="margin-bottom: 2.5rem;">
					<form class="catalog-search__form catalog-filters rd-card" method="GET" action="" style="display: flex; flex-direction: column; gap: 1.25rem;">
						<div class="catalog-filters__primary" style="display: flex; gap: 1rem; width: 100%;">
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

									<?php if ((int) $book['available_copies'] > 0): ?>
										<span class="catalog-book__badge catalog-book__badge--available">Available (<?= (int) $book['available_copies'] ?>)</span>
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
										<?php if ((int) $book['available_copies'] > 0): ?>
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
						<button type="button" class="rd-btn" style="background:transparent; border:1px solid var(--rd-border); color:var(--rd-text);" id="reserve-modal-cancel">Cancel</button>
						<button type="submit" class="rd-btn rd-btn-primary">Confirm</button>
					</form>
				</div>
			</div>
		</main>
	</div>

	<style>
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
	</style>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			var modal = document.getElementById('reserve-modal');
			var titleEl = document.getElementById('reserve-modal-title');
			var idInput = document.getElementById('reserve-modal-book-id');
			var cancelBtn = document.getElementById('reserve-modal-cancel');
			var closeBg = document.getElementById('reserve-modal-close');

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
