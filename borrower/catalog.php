<?php

/**
 * borrower/catalog.php — Borrower Catalog
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';

$pdo = get_db();

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 12;

$whereSql = '';
$params = [];
if ($q !== '') {
	$like = '%' . $q . '%';
	$whereSql = ' WHERE b.title LIKE :q1 OR b.author LIKE :q2 OR b.isbn LIKE :q3 OR b.category LIKE :q4';
	$params = [
		':q1' => $like,
		':q2' => $like,
		':q3' => $like,
		':q4' => $like,
	];
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
	' ORDER BY b.title ASC LIMIT :limit OFFSET :offset';

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
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$qEscaped = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
$current_page = 'borrower.catalog';
$pageTitle = 'Browse Catalog | Library System';

$shownStart = $totalBooks > 0 ? $offset + 1 : 0;
$shownEnd = $totalBooks > 0 ? $offset + count($books) : 0;

function buildCatalogUrl(string $q, int $page): string
{
	$query = ['page' => $page];
	if ($q !== '') {
		$query['q'] = $q;
	}

	return 'catalog.php?' . http_build_query($query);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
	<?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
	<div class="app-shell">
		<?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>

		<main class="main-content borrower-catalog-page">
			<section class="catalog-layout" aria-labelledby="catalog-title">
				<section class="borrower-hero borrower-hero--catalog" aria-labelledby="catalog-title">
					<div class="page-header borrower-hero__content">
						<h1 id="catalog-title">Browse Catalog</h1>
						<p>Discover books and reserve in one step.</p>
					</div>
					<div class="borrower-hero__actions" aria-label="Catalog quick actions">
						<a class="btn-ghost" href="<?= htmlspecialchars(BASE_URL . 'borrower/index.php', ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
						<a class="btn-ghost is-current" href="<?= htmlspecialchars(BASE_URL . 'borrower/catalog.php', ENT_QUOTES, 'UTF-8') ?>" aria-current="page">Browse Catalog</a>
					</div>
					<section class="catalog-search" aria-label="Search catalog">
						<form class="catalog-search__form" method="GET" action="">
							<label class="sr-only" for="catalog-search-input">Search by title, author, ISBN, or category</label>
							<input id="catalog-search-input" type="text" name="q" placeholder="Search title, author, ISBN, or category" value="<?= $qEscaped ?>">
							<button type="submit" class="btn-primary">Search</button>
							<?php if ($q !== ''): ?>
								<a href="catalog.php" class="btn-ghost">Clear</a>
							<?php endif; ?>
						</form>
					</section>
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
<<<<<<< ours
				<?php
				$receipt_modal_title = 'Reservation ticket ready';
				$receipt_modal_message = 'Your reservation was submitted successfully. Open the reservation ticket from the actions below.';
				$receipt_modal_view_label = 'View Reservation Ticket';
				require __DIR__ . '/../includes/receipt-success-modal.php';
				?>

=======
>>>>>>> theirs
				<div class="catalog-results" role="status" aria-live="polite">
					<?php if ($q !== ''): ?>
						Showing <strong><?= (int) $shownStart ?></strong>-<strong><?= (int) $shownEnd ?></strong> of <strong><?= (int) $totalBooks ?></strong> results for "<?= $qEscaped ?>"
					<?php else: ?>
						Showing <strong><?= (int) $shownStart ?></strong>-<strong><?= (int) $shownEnd ?></strong> of <strong><?= (int) $totalBooks ?></strong> books
					<?php endif; ?>
				</div>

				<section class="catalog-grid" aria-label="Catalog books">
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
											src="<?= BASE_URL ?>book-cover.php?book_id=<?= (int) $book['id'] ?>"
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
											<form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>">
												<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
												<input type="hidden" name="action" value="place">
												<input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
												<button type="submit" class="btn-confirm catalog-book__btn">Reserve This Book</button>
											</form>
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
							<a class="btn-ghost" href="<?= htmlspecialchars(buildCatalogUrl($q, $page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to previous page">&larr; Previous</a>
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
									<a class="catalog-pagination__link" href="<?= htmlspecialchars(buildCatalogUrl($q, $i), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $i ?></a>
								<?php endif; ?>
							<?php endfor; ?>
						</div>

						<?php if ($page < $totalPages): ?>
							<a class="btn-ghost" href="<?= htmlspecialchars(buildCatalogUrl($q, $page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to next page">Next &rarr;</a>
						<?php else: ?>
							<span class="catalog-pagination__disabled" aria-disabled="true">Next &rarr;</span>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</section>
		</main>
	</div>

	<script>
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
