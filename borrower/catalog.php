<?php

/**
 * borrower/catalog.php — Book Catalog for Borrowers
 * Uses libris.css design system theme.
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';

$pdo = get_db();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        'SELECT b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
                (bc.book_id IS NOT NULL) AS has_cover
         FROM Books b
         LEFT JOIN book_covers bc ON bc.book_id = b.id
         WHERE b.title LIKE :q1 OR b.author LIKE :q2 OR b.isbn LIKE :q3 OR b.category LIKE :q4
         ORDER BY b.title ASC'
    );
    $stmt->bindValue(':q1', $like, PDO::PARAM_STR);
    $stmt->bindValue(':q2', $like, PDO::PARAM_STR);
    $stmt->bindValue(':q3', $like, PDO::PARAM_STR);
    $stmt->bindValue(':q4', $like, PDO::PARAM_STR);
    $stmt->execute();
} else {
    $stmt = $pdo->prepare(
        'SELECT b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
                (bc.book_id IS NOT NULL) AS has_cover
         FROM Books b
         LEFT JOIN book_covers bc ON bc.book_id = b.id
         ORDER BY b.title ASC'
    );
    $stmt->execute();
}
$books = $stmt->fetchAll();

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$q_escaped = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');

$current_page = 'borrower.catalog';
$pageTitle    = 'Browse Catalog | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/libris.css">
  <style>
    .catalog-hero {
      background: var(--ink);
      color: var(--paper);
      padding: 32px 24px;
      text-align: center;
    }
    .catalog-hero h1 {
      font-family: var(--font-serif);
      font-size: 2rem;
      font-weight: 700;
      margin-bottom: 8px;
    }
    .catalog-hero p {
      color: var(--muted);
    }
    .catalog-main {
      max-width: 1200px;
      margin: 0 auto;
      padding: 32px 24px;
    }
    .search-wrap {
       margin-bottom: 64px;
       padding: 48px 24px;
       background: var(--cream);
       border-radius: var(--radius-lg);
       margin-top: -16px;
      }
     .search-box {
       display: flex;
       gap: 16px;
       background: var(--card);
       padding: 12px;
       border-radius: var(--radius);
       box-shadow: var(--shadow);
       border: 1px solid var(--border);
       max-width: 700px;
       margin: 0 auto;
     }
     .search-box input {
       flex: 1;
       border: none;
       padding: 14px 18px;
       font-size: 1rem;
       background: transparent;
       outline: none;
       color: var(--ink);
     }
     .search-box input:focus {
       outline: 2px solid var(--accent);
       outline-offset: -2px;
     }
     .search-box button {
       padding: 14px 28px;
       background: var(--accent);
       color: white;
       border: none;
       border-radius: var(--radius-sm);
       font-weight: 600;
       cursor: pointer;
       transition: background 0.2s cubic-bezier(0.4, 0, 0.2, 1);
       min-height: 44px;
       display: flex;
       align-items: center;
       justify-content: center;
     }
     .search-box button:hover {
       background: var(--accent-dark);
     }
     .search-box button:focus-visible {
       outline: 2px solid var(--ink);
       outline-offset: 2px;
     }
     .search-box a.clear-btn {
       padding: 14px 20px;
       background: var(--cream);
       color: var(--muted);
       border-radius: var(--radius-sm);
       font-weight: 500;
       min-height: 44px;
       display: inline-flex;
       align-items: center;
       justify-content: center;
     }
     .results-bar {
       color: var(--muted);
       margin-bottom: 48px;
       margin-top: 24px;
       font-size: 0.9rem;
       padding-bottom: 24px;
       border-bottom: 1px solid var(--border);
     }
     .books-grid {
       display: grid;
       grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
       gap: 32px;
       margin-top: 48px;
     }
     .book-card {
       background: var(--card);
       border-radius: var(--radius-lg);
       overflow: hidden;
       box-shadow: var(--shadow-sm);
       transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1);
       border: 1px solid var(--border);
     }
     .book-card:first-child {
       grid-column: span 1;
     }
     @media (min-width: 1400px) {
       .book-card:first-child {
         grid-column: span 2;
         grid-row: span 2;
       }
       .book-card:first-child .book-cover-wrap {
         aspect-ratio: 280 / 400;
       }
       .book-card:first-child .book-details {
         padding: 24px;
       }
     }
    .book-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
    }
     .book-cover-wrap {
       position: relative;
       aspect-ratio: 180 / 280;
       overflow: hidden;
     }
     .book-cover {
       width: 100%;
       height: 100%;
       object-fit: cover;
       background: var(--cream);
     }
    .book-badge {
      position: absolute;
      top: 12px;
      right: 12px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: uppercase;
    }
    .badge-avail {
      background: var(--sage);
      color: white;
    }
     .badge-unavail {
       background: var(--accent);
       color: white;
     }
     .book-details {
      padding: 16px;
    }
     .book-title {
       font-family: var(--font-serif);
       font-size: 1.05rem;
       font-weight: 700;
       color: var(--ink);
       margin-bottom: 6px;
       line-height: 1.4;
       display: -webkit-box;
       -webkit-line-clamp: 2;
       -webkit-box-orient: vertical;
       overflow: hidden;
     }
     .book-author {
       color: var(--muted);
       font-size: 0.85rem;
       margin-bottom: 16px;
       font-style: italic;
     }
     .book-meta {
       display: flex;
       flex-wrap: wrap;
       gap: 8px;
       margin-bottom: 16px;
     }
    .book-cat {
      background: var(--cream);
      color: var(--muted);
      padding: 3px 8px;
      border-radius: var(--radius-sm);
      font-size: 0.7rem;
    }
    .book-isbn {
      color: var(--muted);
      font-size: 0.65rem;
    }
     .book-action {
       padding-top: 16px;
       border-top: 1px solid var(--border);
     }
     .btn-reserve {
       display: block;
       width: 100%;
       padding: 12px;
       background: var(--sage);
       color: white;
       text-align: center;
       border-radius: var(--radius-sm);
       font-weight: 600;
       font-size: 0.85rem;
       border: none;
       cursor: pointer;
       min-height: 44px;
       display: flex;
       align-items: center;
       justify-content: center;
       transition: background 0.2s cubic-bezier(0.4, 0, 0.2, 1);
     }
     .btn-reserve:hover {
       background: var(--sage-dark);
     }
     .btn-reserve:focus-visible {
       outline: 2px solid var(--ink);
       outline-offset: 2px;
     }
     .btn-waitlist {
       display: flex;
       align-items: center;
       justify-content: center;
       width: 100%;
       padding: 12px;
       background: var(--cream);
       color: var(--muted);
       text-align: center;
       border-radius: var(--radius-sm);
       font-size: 0.8rem;
       min-height: 44px;
     }
    .empty-state {
      grid-column: 1 / -1;
      text-align: center;
      padding: 80px 24px;
      color: var(--muted);
    }
    .empty-icon {
      font-size: 3rem;
      margin-bottom: 16px;
    }
    .empty-state h3 {
      font-family: var(--font-serif);
      font-size: 1.5rem;
      color: var(--ink);
      margin-bottom: 8px;
    }
    .flash {
      max-width: 700px;
      margin: 0 auto 24px;
      padding: 12px 16px;
      border-radius: var(--radius);
    }
     .flash-error {
       background: var(--error-bg);
       color: var(--error-text);
       border: 1px solid var(--error-border);
     }
     .flash-success {
       background: var(--success-bg);
       color: var(--success-text);
       border: 1px solid var(--success-border);
     }
     @media (max-width: 1024px) {
       .books-grid { gap: 28px; }
       .book-card:first-child { grid-column: span 1; grid-row: span 1; }
       .book-card:first-child .book-cover-wrap { aspect-ratio: 180 / 280; }
     }
      @media (max-width: 768px) {
        .search-wrap { padding: 32px 16px; }
        .catalog-main { padding: 24px 16px; }
        .books-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 32px; }
      }
     @media (max-width: 640px) {
       .catalog-hero h1 { font-size: 1.5rem; }
       .search-box { flex-direction: column; gap: 12px; }
       .search-wrap { margin-bottom: 32px; padding: 24px 12px; }
       .results-bar { margin-bottom: 32px; margin-top: 16px; }
       .books-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 24px; }
       .book-details { padding: 12px; }
       .book-meta { gap: 4px; }
     }
     @media (max-width: 480px) {
       .books-grid { grid-template-columns: 1fr; gap: 16px; }
       .search-wrap { padding: 20px 12px; margin-bottom: 24px; }
     }
  </style>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>

    <main class="main-content" style="padding: 0;">
      <div class="catalog-hero">
        <h1>Browse Catalog</h1>
        <p>Find and reserve books from our collection</p>
      </div>

      <div class="catalog-main">
        <div class="search-wrap">
           <form class="search-box" method="GET" action="">
             <input type="text" name="q" placeholder="Search by title, author, ISBN, or category..." value="<?= $q_escaped ?>" aria-label="Search catalog by title, author, ISBN, or category">
             <button type="submit">Search</button>
            <?php if ($q !== ''): ?>
              <a href="catalog.php" class="clear-btn">Clear</a>
            <?php endif; ?>
          </form>
        </div>

        <?php if ($q !== ''): ?>
          <div class="results-bar">
            Found <strong><?= count($books) ?></strong> book(s) matching "<?= $q_escaped ?>"
          </div>
        <?php endif; ?>

        <?php if ($flash_error !== ''): ?>
          <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flash_success !== ''): ?>
          <div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="books-grid">
          <?php if (empty($books)): ?>
            <div class="empty-state">
              <div class="empty-icon" aria-hidden="true">Search</div>
              <h3>No books found</h3>
              <p><?= $q !== '' ? 'Try a different search term' : 'The catalog is empty' ?></p>
            </div>
          <?php else: ?>
            <?php foreach ($books as $book): ?>
              <div class="book-card">
                <div class="book-cover-wrap">
                   <?php if (!empty($book['has_cover'])): ?>
                     <img class="book-cover" 
                          src="<?= BASE_URL ?>book-cover.php?book_id=<?= (int)$book['id'] ?>" 
                          alt="Cover of <?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?>"
                          width="180"
                          height="280"
                          sizes="(max-width: 768px) 150px, 180px"
                          loading="lazy"
                          decoding="async"
                          data-fallback-src="<?= BASE_URL ?>assets/images/placeholder-book.png">
                   <?php else: ?>
                     <img class="book-cover" 
                          src="<?= BASE_URL ?>assets/images/placeholder-book.png" 
                          alt="No cover available"
                          width="180"
                          height="280"
                          loading="lazy"
                          decoding="async">
                  <?php endif; ?>
                  <?php if ((int)$book['available_copies'] > 0): ?>
                    <span class="book-badge badge-avail">Available (<?= (int)$book['available_copies'] ?>)</span>
                  <?php else: ?>
                    <span class="book-badge badge-unavail">Unavailable</span>
                  <?php endif; ?>
                </div>
                <div class="book-details">
                  <h3 class="book-title"><?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <p class="book-author">by <?= htmlspecialchars($book['author'], ENT_QUOTES, 'UTF-8') ?></p>
                  <div class="book-meta">
                    <span class="book-cat"><?= htmlspecialchars($book['category'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="book-isbn"><?= htmlspecialchars($book['isbn'], ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                  <div class="book-action">
                    <?php if ((int)$book['available_copies'] > 0): ?>
                      <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="place">
                        <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                        <button type="submit" class="btn-reserve">Reserve This Book</button>
                      </form>
                    <?php else: ?>
                      <span class="btn-waitlist">Join waitlist when available</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </main>
  </div>
</body>

<script>
  document.addEventListener('error', function(event) {
    const target = event.target;
    if (!target || target.tagName !== 'IMG') {
      return;
    }

    const fallback = target.getAttribute('data-fallback-src');
    if (!fallback || target.src.endsWith(fallback)) {
      return;
    }

    target.src = fallback;
  }, true);
</script>

</html>
