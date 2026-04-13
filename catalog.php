<?php

/**
 * catalog.php — Public Online Public Access Catalog (OPAC) - IMPROVED
 *
 * Public page for searching and browsing books without login.
 * Features: Pagination, copy counts, book detail modal, discovery sections.
 */

require_once __DIR__ . '/includes/db.php';

$pdo = get_db();

$q = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$availability = trim((string) ($_GET['availability'] ?? 'all'));
$sort = trim((string) ($_GET['sort'] ?? 'title_asc'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 12;

$allowed_sorts = [
  'title_asc' => 'b.title ASC',
  'title_desc' => 'b.title DESC',
  'author_asc' => 'b.author ASC',
  'newest' => 'b.created_at DESC',
];
$order_by = $allowed_sorts[$sort] ?? $allowed_sorts['title_asc'];

$where_parts = [];
$params = [];

if ($q !== '') {
  $where_parts[] = '(b.title LIKE :q OR b.author LIKE :q OR b.isbn LIKE :q OR b.category LIKE :q)';
  $params[':q'] = '%' . $q . '%';
}

if ($category !== '') {
  $where_parts[] = 'b.category = :category';
  $params[':category'] = $category;
}

if ($availability === 'available') {
  $where_parts[] = 'b.available_copies > 0';
} elseif ($availability === 'unavailable') {
  $where_parts[] = 'b.available_copies = 0';
}

$where_sql = '';
if (!empty($where_parts)) {
  $where_sql = 'WHERE ' . implode(' AND ', $where_parts);
}

$sql = 'SELECT b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
               (bc.book_id IS NOT NULL) AS has_cover
           FROM Books b
           LEFT JOIN book_covers bc ON bc.book_id = b.id
           ' . $where_sql . '
           ORDER BY ' . $order_by;

// Get total count for pagination
try {
  $count_sql = 'SELECT COUNT(DISTINCT b.id) FROM Books b ' . $where_sql;
  $count_stmt = $pdo->prepare($count_sql);
  $count_stmt->execute($params);
  $total_books_count = (int) $count_stmt->fetchColumn();
} catch (PDOException $e) {
  error_log('Count query error: ' . $e->getMessage());
  $total_books_count = 0;
}

// Calculate pagination
$total_pages = max(1, ceil($total_books_count / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Add LIMIT to main query
$sql .= ' LIMIT ' . $per_page . ' OFFSET ' . $offset;

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $books = $stmt->fetchAll();
} catch (PDOException $e) {
  error_log('Catalog query error: ' . $e->getMessage());
  $books = [];
}

// Featured books (newest, available)
$featured_sql = 'SELECT b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
                        (bc.book_id IS NOT NULL) AS has_cover
                   FROM Books b
                   LEFT JOIN book_covers bc ON bc.book_id = b.id
                   WHERE b.available_copies > 0
                   ORDER BY b.created_at DESC
                   LIMIT 6';
$featured_stmt = $pdo->query($featured_sql);
$featured_books = $featured_stmt->fetchAll();

// Recently Added section
$recent_sql = 'SELECT b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
                       (bc.book_id IS NOT NULL) AS has_cover
                  FROM Books b
                  LEFT JOIN book_covers bc ON bc.book_id = b.id
                  ORDER BY b.created_at DESC
                  LIMIT 6';
$recent_stmt = $pdo->query($recent_sql);
$recent_books = $recent_stmt->fetchAll();

// Most Popular section (based on checkout count)
$popular_sql = 'SELECT b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
                        (bc.book_id IS NOT NULL) AS has_cover,
            COUNT(c.id) as checkout_count
                   FROM Books b
                   LEFT JOIN book_covers bc ON bc.book_id = b.id
          LEFT JOIN Circulation c ON c.book_id = b.id
                   GROUP BY b.id
                   ORDER BY checkout_count DESC
                   LIMIT 6';
try {
  $popular_stmt = $pdo->query($popular_sql);
  $popular_books = $popular_stmt->fetchAll();
} catch (PDOException $e) {
  error_log('Popular books query error: ' . $e->getMessage());
  $popular_books = [];
}

// Get all unique categories with counts
$categories_stmt = $pdo->query('SELECT DISTINCT category FROM Books ORDER BY category ASC');
$all_categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
$category_count = count($all_categories);

$total_books = (int) $pdo->query('SELECT COUNT(*) FROM Books')->fetchColumn();
$available_books = (int) $pdo->query('SELECT COUNT(*) FROM Books WHERE available_copies > 0')->fetchColumn();
$unavailable_books = $total_books - $available_books;

$q_escaped = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
$category_escaped = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
$pageTitle = 'Public Catalog | Library System';

// Determine if we're in search mode
$is_searching = $q !== '' || $category !== '' || $availability !== 'all' || $sort !== 'title_asc' || $page > 1;
$active_filter_count = 0;
if ($q !== '') {
  $active_filter_count++;
}
if ($category !== '') {
  $active_filter_count++;
}
if ($availability !== 'all') {
  $active_filter_count++;
}

// Helper function to build query string for pagination
function build_query_string($q, $category, $availability, $sort, $exclude_page = false)
{

  $params = [];
  if ($q !== '') $params['q'] = $q;
  if ($category !== '') $params['category'] = $category;
  if ($availability !== 'all') $params['availability'] = $availability;
  if ($sort !== 'title_asc') $params['sort'] = $sort;
  if (!$exclude_page) {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    if ($page > 1) $params['page'] = $page;
  }
  return http_build_query($params);
}

function esc($value)
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function render_book_card(array $book): void
{
  $book_id = (int) ($book['id'] ?? 0);
  $title = esc($book['title'] ?? 'Untitled');
  $author = esc($book['author'] ?? 'Unknown Author');
  $isbn = esc($book['isbn'] ?? '');
  $category = esc($book['category'] ?? 'Uncategorized');
  $available = (int) ($book['available_copies'] ?? 0);
  $total = (int) ($book['total_copies'] ?? 0);
  $has_cover = !empty($book['has_cover']);
?>
  <article class="book-card btn-view-details" tabindex="0" role="button"
    data-book-id="<?= $book_id ?>"
    data-title="<?= $title ?>"
    data-author="<?= $author ?>"
    data-isbn="<?= $isbn ?>"
    data-category="<?= $category ?>"
    data-available="<?= $available ?>"
    data-total="<?= $total ?>">

    <div class="book-meta-top">
      <h3 class="book-title"><?= $title ?></h3>
      <p class="book-author">by <?= $author ?></p>
    </div>

    <div class="book-cover-wrap">
      <?php if ($has_cover): ?>
        <img class="book-cover" src="book-cover-public.php?book_id=<?= $book_id ?>" alt="Cover of <?= $title ?>" loading="lazy" data-fallback-src="assets/images/placeholder-book.png">
      <?php else: ?>
        <img class="book-cover" src="assets/images/placeholder-book.png" alt="No cover available" loading="lazy">
      <?php endif; ?>
    </div>

    <div class="book-meta-bottom">
      <span class="book-cat"><?= $category ?></span>
      <div class="book-status">
        <?php if ($available > 0): ?>
          <span class="status-avail">Avail</span>
        <?php else: ?>
          <span class="status-unavail">Out</span>
        <?php endif; ?>
      </div>
    </div>
  </article>
<?php
}

function render_book_section(string $title, array $books): void
{
  if (empty($books)) {
    return;
  }
?>
  <section class="discovery-section">
    <h2 class="discovery-header"><?= esc($title) ?></h2>
    <div class="discovery-grid">
      <?php foreach ($books as $book): ?>
        <?php render_book_card($book); ?>
      <?php endforeach; ?>
    </div>
  </section>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="assets/css/libris.css">
  <style>
    body {
      background-color: var(--paper);
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      overflow-x: hidden;
      margin: 0;
      padding: 0;
    }

    /* Editorial Navigation */
    .editorial-nav {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: var(--space-4) var(--space-8);
      border-bottom: 1px solid var(--border);
      background: var(--paper);
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 16px;
      text-decoration: none;
      color: var(--ink);
      font-family: var(--font-serif);
      font-size: var(--text-2xl);
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    .nav-links {
      display: flex;
      gap: var(--space-8);
    }

    .nav-links a {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
      text-decoration: none;
      transition: color 0.2s ease-out;
    }

    .nav-links a:hover,
    .nav-links a.active {
      color: var(--ink);
    }

    .nav-actions {
      display: flex;
      gap: var(--space-6);
      align-items: center;
    }

    .nav-actions a {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      text-decoration: none;
    }

    .btn-login {
      color: var(--ink);
    }

    .btn-signup {
      border: 1px solid var(--ink);
      padding: var(--space-2) var(--space-4);
      color: var(--ink);
      transition: all 0.2s ease-out;
    }

    .btn-signup:hover {
      background: var(--ink);
      color: var(--paper);
    }

    .nav-menu-toggle {
      display: none;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      border: 1px solid var(--border);
      background: var(--paper);
      color: var(--ink);
      cursor: pointer;
    }

    .nav-menu-toggle svg {
      width: 20px;
      height: 20px;
      display: block;
    }

    /* OPAC Shell & Layout */
    .opac-shell {
      max-width: 1600px;
      margin: 0 auto;
      padding: var(--space-8) var(--space-8);
      width: 100%;
      flex: 1;
    }

    /* ── Hero Section ─────────────────────────────────── */
    .catalog-hero {
      margin-bottom: var(--space-10);
      border-bottom: 1px solid var(--border);
      padding-bottom: var(--space-6);
    }

    .catalog-hero h1 {
      font-family: var(--font-serif);
      font-size: clamp(3rem, 6vw, 5rem);
      font-weight: 400;
      line-height: 1;
      letter-spacing: -0.03em;
      color: var(--ink);
      margin: 0 0 var(--space-4) 0;
    }

    .catalog-hero h1 i {
      font-style: italic;
      color: var(--sage);
      padding-right: 0.1em;
    }

    .catalog-hero p {
      max-width: 60ch;
      color: var(--muted);
      font-size: var(--text-lg);
      line-height: 1.6;
      margin: 0;
    }

    .catalog-hero-stats {
      margin-top: var(--space-6);
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: var(--space-3);
      max-width: 980px;
    }

    .hero-stat {
      border: 1px solid var(--border);
      padding: var(--space-4);
      background: linear-gradient(180deg, var(--card) 0%, color-mix(in srgb, var(--cream) 26%, var(--card)) 100%);
      position: relative;
      overflow: hidden;
    }

    .hero-stat::before {
      content: "";
      position: absolute;
      inset: 0 auto auto 0;
      width: 100%;
      height: 2px;
      background: linear-gradient(90deg, var(--gold), transparent);
    }

    .hero-stat-value {
      display: block;
      font-family: var(--font-serif);
      font-size: clamp(1.6rem, 3vw, 2.3rem);
      line-height: 1;
      letter-spacing: -0.02em;
      color: var(--ink);
      margin-bottom: var(--space-2);
    }

    .hero-stat-label {
      display: block;
      font-family: var(--font-mono);
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.09em;
      color: var(--muted);
    }

    /* ── Search & Filters ─────────────────────────────────── */
    .search-wrap {
      background: transparent;
      border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      padding: var(--space-6) 0;
      margin-bottom: var(--space-8);
    }

    .search-wrap h2 {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--muted);
      margin-bottom: var(--space-4);
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }

    .search-wrap h2::before {
      content: "";
      display: block;
      width: 40px;
      height: 1px;
      background: var(--ink);
    }

    .search-box {
      display: grid;
      grid-template-columns: 2fr 1fr 1fr 1fr auto;
      gap: var(--space-4);
      align-items: end;
    }

    .search-input-wrapper {
      position: relative;
    }

    .search-box input,
    .search-box select {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 0;
      padding: 14px 16px;
      background: var(--paper);
      color: var(--ink);
      font-size: var(--text-sm);
      font-family: var(--font-mono);
      transition: border-color 0.2s;
      appearance: none;
    }

    .search-box select {
      background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%230F0E0C%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
      background-repeat: no-repeat;
      background-position: right 1rem top 50%;
      background-size: 0.65rem auto;
      padding-right: 2.5rem;
    }

    .search-box input:focus-visible,
    .search-box select:focus-visible {
      outline: none;
      border-color: var(--ink);
      background: var(--card);
    }

    .search-box button {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 14px 24px;
      background: var(--ink);
      color: var(--paper);
      border: 1px solid var(--ink);
      border-radius: 0;
      cursor: pointer;
      min-height: 48px;
      transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), background-color 0.2s;
    }

    .search-box button:hover {
      transform: scale(0.98);
      background: color-mix(in srgb, var(--ink) 90%, transparent);
    }

    .search-box a.clear-btn {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 14px 24px;
      background: transparent;
      color: var(--ink);
      border: 1px solid var(--ink);
      border-radius: 0;
      text-decoration: none;
      text-align: center;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      transition: background-color 0.2s;
    }

    .search-box a.clear-btn:hover {
      background: color-mix(in srgb, var(--ink) 5%, transparent);
    }

    /* Auto-suggest */
    .search-suggestions {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: var(--paper);
      border: 1px solid var(--ink);
      border-top: none;
      max-height: 300px;
      overflow-y: auto;
      z-index: 100;
      display: none;
    }

    .search-suggestions.active {
      display: block;
    }

    .suggestion-item {
      width: 100%;
      border: 0;
      background: transparent;
      text-align: left;
      padding: 12px 16px;
      cursor: pointer;
      border-bottom: 1px solid var(--border);
      font-family: var(--font-sans);
      font-size: var(--text-sm);
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--ink);
    }

    .suggestion-item:last-child {
      border-bottom: none;
    }

    .suggestion-item:hover,
    .suggestion-item.active {
      background: var(--cream);
    }

    .suggestion-icon {
      font-family: var(--font-mono);
      font-size: var(--text-xs);
      color: var(--muted);
    }

    .suggestion-text {
      flex: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      font-weight: 500;
    }

    .suggestion-type {
      font-family: var(--font-mono);
      font-size: 0.65rem;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    /* Results Bar */
    .results-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: var(--space-4);
      margin-bottom: var(--space-8);
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      padding: var(--space-3) 0;
    }

    .results-bar {
      color: var(--ink);
      font-weight: 500;
    }

    .results-summary-emphasis {
      color: var(--sage);
      font-weight: 700;
    }

    .results-tags {
      display: flex;
      gap: var(--space-3);
      flex-wrap: wrap;
    }

    .tag {
      padding: 4px 12px;
      border: 1px solid var(--ink);
      background: transparent;
      color: var(--ink);
      font-size: 0.75rem;
      white-space: nowrap;
    }

    /* ── Book Grid ─────────────────────────────────── */
    .discovery-header {
      font-family: var(--font-serif);
      font-size: var(--text-3xl);
      font-weight: 400;
      color: var(--ink);
      margin-bottom: var(--space-6);
      padding-bottom: var(--space-4);
      border-bottom: 1px solid var(--border);
      letter-spacing: -0.02em;
    }

    .discovery-grid,
    .books-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: var(--space-8) var(--space-6);
      margin-bottom: var(--space-12);
    }

    .book-card {
      background: transparent;
      border: none;
      display: flex;
      flex-direction: column;
      cursor: pointer;
      text-align: left;
      text-decoration: none;
      color: inherit;
      outline: none;
      position: relative;
    }

    .book-cover-wrap {
      width: 100%;
      aspect-ratio: 2/3;
      background: var(--cream);
      border: 1px solid color-mix(in srgb, var(--border) 60%, transparent);
      box-shadow: 0 16px 32px rgba(0, 0, 0, 0.08);
      margin-bottom: var(--space-4);
      overflow: hidden;
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease;
      position: relative;
    }

    .book-cover-wrap::after {
      content: "";
      position: absolute;
      inset: auto 0 0 0;
      height: 28%;
      background: linear-gradient(0deg, rgba(15, 14, 12, 0.18), rgba(15, 14, 12, 0));
      opacity: 0;
      transition: opacity 0.3s ease;
      pointer-events: none;
    }

    .book-card:hover .book-cover-wrap {
      transform: scale(0.98);
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
    }

    .book-card:hover .book-cover-wrap::after {
      opacity: 1;
    }

    .book-card:focus-visible .book-cover-wrap {
      outline: 2px solid var(--ink);
      outline-offset: 4px;
    }

    .book-cover {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .book-meta-top {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: var(--space-1);
      min-height: 86px;
    }

    .book-title {
      font-family: var(--font-serif);
      font-size: 1.25rem;
      font-weight: 700;
      line-height: 1.2;
      color: var(--ink);
      margin: 0;
      letter-spacing: -0.01em;
    }

    .book-author {
      font-size: 0.9rem;
      color: var(--muted);
      margin: 0 0 var(--space-2) 0;
      font-family: var(--font-sans);
    }

    .book-meta-bottom {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      margin-top: var(--space-2);
      font-family: var(--font-mono);
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .book-cat {
      color: var(--muted);
    }

    .book-status {
      font-weight: 600;
    }

    .status-avail {
      color: var(--ink);
    }

    .status-unavail {
      color: var(--accent);
      text-decoration: line-through;
    }

    .book-popular-badge {
      position: absolute;
      top: var(--space-4);
      right: var(--space-4);
      z-index: 1;
      font-family: var(--font-mono);
      font-size: 0.62rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      border: 1px solid color-mix(in srgb, var(--gold) 48%, var(--ink));
      background: color-mix(in srgb, var(--paper) 82%, var(--gold));
      color: var(--ink);
      padding: 4px 8px;
    }

    /* ── Pagination ─────────────────────────────────── */
    .pagination {
      display: flex;
      justify-content: center;
      gap: var(--space-2);
      margin-top: var(--space-10);
      padding-top: var(--space-8);
      border-top: 1px solid var(--border);
    }

    .pagination a,
    .pagination span {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      padding: var(--space-3) var(--space-4);
      border: 1px solid var(--border);
      color: var(--ink);
      text-decoration: none;
      transition: background-color 0.2s, border-color 0.2s;
    }

    .pagination a:hover {
      background: color-mix(in srgb, var(--ink) 5%, transparent);
      border-color: var(--ink);
    }

    .pagination span.current {
      background: var(--ink);
      color: var(--paper);
      border-color: var(--ink);
    }

    .pagination span.disabled {
      opacity: 0.3;
      border-color: var(--border);
    }

    /* ── Empty State ─────────────────────────────────── */
    .empty-state {
      grid-column: 1 / -1;
      padding: var(--space-12) 0;
      border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      text-align: center;
    }

    .empty-state h3 {
      font-family: var(--font-serif);
      font-size: 3rem;
      font-weight: 400;
      margin: 0 0 var(--space-4) 0;
      letter-spacing: -0.02em;
    }

    .empty-state p {
      font-size: var(--text-lg);
      color: var(--muted);
      max-width: 60ch;
      margin: 0 auto var(--space-8) auto;
    }

    .empty-suggestions {
      display: flex;
      gap: var(--space-4);
      justify-content: center;
      flex-wrap: wrap;
    }

    .suggestion-link {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: var(--space-3) var(--space-6);
      border: 1px solid var(--border);
      color: var(--ink);
      text-decoration: none;
      transition: background-color 0.2s, border-color 0.2s;
    }

    .suggestion-link:hover {
      border-color: var(--ink);
      background: color-mix(in srgb, var(--ink) 5%, transparent);
    }

    /* ── Modal ─────────────────────────────────── */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      inset: 0;
      background: rgba(247, 244, 238, 0.9);
      /* var(--paper) with opacity */
      backdrop-filter: blur(4px);
    }

    .modal.active {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .modal-content {
      background: var(--paper);
      border: 1px solid var(--ink);
      padding: var(--space-8);
      width: 90%;
      max-width: 700px;
      box-shadow: 20px 20px 0px color-mix(in srgb, var(--ink) 10%, transparent);
      /* Brutalist shadow */
      position: relative;
      max-height: 90vh;
      overflow-y: auto;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: var(--space-6);
      padding-bottom: var(--space-4);
      border-bottom: 1px solid var(--ink);
    }

    .modal-title {
      font-family: var(--font-serif);
      font-size: 2.5rem;
      font-weight: 400;
      line-height: 1.1;
      letter-spacing: -0.02em;
      margin: 0;
    }

    .modal-close {
      background: none;
      border: 1px solid transparent;
      font-family: var(--font-mono);
      font-size: 2rem;
      line-height: 1;
      cursor: pointer;
      color: var(--ink);
      padding: 0;
      width: 48px;
      height: 48px;
      transition: background-color 0.2s;
    }

    .modal-close:hover {
      background: color-mix(in srgb, var(--ink) 5%, transparent);
      border-color: var(--ink);
    }

    .modal-body {
      display: grid;
      grid-template-columns: 200px 1fr;
      gap: var(--space-8);
      margin-bottom: var(--space-8);
    }

    .modal-cover {
      width: 100%;
      aspect-ratio: 2/3;
      object-fit: cover;
      border: 1px solid var(--border);
    }

    .modal-info {
      display: flex;
      flex-direction: column;
      gap: var(--space-4);
    }

    .modal-author {
      font-size: 1.2rem;
      color: var(--muted);
      margin: 0;
    }

    .modal-meta-grid {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: var(--space-2) var(--space-4);
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      color: var(--ink);
    }

    .modal-meta-label {
      color: var(--muted);
      text-transform: uppercase;
    }

    .modal-availability {
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      padding: var(--space-3);
      border: 1px solid var(--ink);
      text-align: center;
      margin-top: auto;
    }

    .modal-available {
      background: var(--paper);
      color: var(--ink);
    }

    .modal-unavailable {
      background: color-mix(in srgb, var(--accent) 10%, transparent);
      border-color: var(--accent);
      color: var(--accent);
    }

    .btn-reserve,
    .btn-waitlist {
      display: block;
      width: 100%;
      padding: var(--space-4);
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      text-align: center;
      border: 1px solid var(--ink);
      text-decoration: none;
      transition: transform 0.2s, background-color 0.2s;
      cursor: pointer;
    }

    .btn-reserve {
      background: var(--ink);
      color: var(--paper);
    }

    .btn-reserve:hover {
      transform: scale(0.98);
      background: color-mix(in srgb, var(--ink) 90%, transparent);
    }

    .btn-waitlist {
      background: transparent;
      color: var(--muted);
      border-color: var(--muted);
      cursor: not-allowed;
    }

    /* Footer */
    .catalog-footer {
      border-top: 1px solid var(--border);
      padding: var(--space-8) 0;
      text-align: center;
      font-family: var(--font-mono);
      font-size: var(--text-sm);
      color: var(--muted);
    }

    .catalog-footer a {
      color: var(--ink);
      text-decoration: none;
      border-bottom: 1px solid var(--ink);
    }

    .catalog-footer a:hover {
      background: color-mix(in srgb, var(--ink) 5%, transparent);
    }

    @media (max-width: 980px) {
      .search-box {
        grid-template-columns: 1fr 1fr;
      }

      .search-box input {
        grid-column: 1 / -1;
      }

      .catalog-hero-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .discovery-grid,
      .books-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      }

      .modal-body {
        grid-template-columns: 1fr;
      }

      .modal-cover {
        max-width: 200px;
        margin: 0 auto;
      }

      .results-toolbar {
        flex-direction: column;
        align-items: flex-start;
      }
    }

    @media (max-width: 640px) {
      .editorial-nav {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: var(--space-3);
        padding: var(--space-4);
      }

      .brand {
        font-size: var(--text-xl);
        gap: 12px;
      }

      .nav-menu-toggle {
        display: inline-flex;
      }

      .nav-links {
        grid-column: 1 / -1;
        display: none;
        gap: var(--space-4);
        padding-top: var(--space-3);
        border-top: 1px solid var(--border);
      }

      .nav-links.menu-open {
        display: flex;
      }

      .nav-actions {
        grid-column: 1 / -1;
        display: none;
        gap: var(--space-4);
      }

      .nav-actions.menu-open {
        display: flex;
      }

      .search-box {
        grid-template-columns: 1fr;
      }

      .discovery-grid,
      .books-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-4);
      }

      .catalog-hero h1 {
        font-size: 2.5rem;
      }

      .catalog-hero p {
        font-size: var(--text-base);
      }

      .catalog-hero-stats {
        grid-template-columns: 1fr;
      }

      .modal-content {
        padding: var(--space-4);
      }

      .modal-title {
        font-size: 1.8rem;
      }

      .results-tags {
        width: 100%;
        overflow-x: auto;
        padding-bottom: 2px;
      }

      .pagination {
        justify-content: flex-start;
        overflow-x: auto;
        padding-bottom: var(--space-2);
      }

      .pagination a,
      .pagination span {
        white-space: nowrap;
      }
    }
  </style>
</head>

<body>
  <nav class="editorial-nav" aria-label="Main Navigation">
    <a href="index.php" class="brand">
      <img src="assets/images/library_logo_cropped.png" alt="Library System Logo" style="height: 48px; width: auto; object-fit: contain;" onerror="this.onerror=null;this.src='assets/images/logo.svg';">
      <span>Library System</span>
    </a>

    <button class="nav-menu-toggle" id="navMenuToggle" type="button" aria-expanded="false" aria-controls="catalogNavLinks catalogNavActions" aria-label="Toggle menu">
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="square" />
      </svg>
    </button>

    <div class="nav-links" id="catalogNavLinks">
      <a href="index.php">Index</a>
      <a href="catalog.php" class="active">Catalog</a>
    </div>

    <div class="nav-actions" id="catalogNavActions">
      <a href="login.php" class="btn-login">Sign In</a>
      <a href="register.php" class="btn-signup">Join Library</a>
    </div>
  </nav>

  <div class="opac-shell page-fade-in">

    <!-- Hero Section -->
    <section class="catalog-hero">
      <h1>Library <i>Archive.</i></h1>
      <p>Search, filter, and explore the complete collection of knowledge and resources.</p>
      <div class="catalog-hero-stats" aria-label="Catalog metrics">
        <article class="hero-stat">
          <span class="hero-stat-value"><?= number_format($total_books) ?></span>
          <span class="hero-stat-label">Total Titles</span>
        </article>
        <article class="hero-stat">
          <span class="hero-stat-value"><?= number_format($available_books) ?></span>
          <span class="hero-stat-label">Available Now</span>
        </article>
        <article class="hero-stat">
          <span class="hero-stat-value"><?= number_format($unavailable_books) ?></span>
          <span class="hero-stat-label">Currently Out</span>
        </article>
        <article class="hero-stat">
          <span class="hero-stat-value"><?= number_format($category_count) ?></span>
          <span class="hero-stat-label">Categories</span>
        </article>
      </div>
    </section>

    <!-- Main Content -->
    <main class="catalog-main">
      <!-- Search & Filter Section -->
      <section class="search-wrap">
        <h2>Refine Query</h2>
        <form class="search-box" method="GET" action="">
          <div class="search-input-wrapper">
            <label for="catalog-search" class="sr-only">Search by title, author, or ISBN</label>
            <input id="catalog-search" type="text" name="q" placeholder="Enter keyword or ISBN" value="<?= $q_escaped ?>" aria-label="Search by title, author, or ISBN" autocomplete="off" aria-autocomplete="list" aria-controls="searchSuggestions" aria-expanded="false">
            <div class="search-suggestions" id="searchSuggestions" role="listbox" aria-label="Search suggestions"></div>
          </div>

          <select name="category" aria-label="Filter by category">
            <option value="">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
              <?php $cat_safe = htmlspecialchars((string) $cat, ENT_QUOTES, 'UTF-8'); ?>
              <option value="<?= $cat_safe ?>" <?= $category === $cat ? 'selected' : '' ?>><?= $cat_safe ?></option>
            <?php endforeach; ?>
          </select>

          <select name="availability" aria-label="Filter by availability">
            <option value="all" <?= $availability === 'all' ? 'selected' : '' ?>>All Books</option>
            <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Available</option>
            <option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>Checked Out</option>
          </select>

          <select name="sort" aria-label="Sort results">
            <option value="title_asc" <?= $sort === 'title_asc' ? 'selected' : '' ?>>Title A–Z</option>
            <option value="title_desc" <?= $sort === 'title_desc' ? 'selected' : '' ?>>Title Z–A</option>
            <option value="author_asc" <?= $sort === 'author_asc' ? 'selected' : '' ?>>Author A–Z</option>
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
          </select>

          <button type="submit">Execute</button>
          <?php if ($is_searching): ?>
            <a href="catalog.php" class="clear-btn">Clear</a>
          <?php endif; ?>
        </form>
      </section>

      <!-- Results Toolbar -->
      <?php if ($is_searching): ?>
        <section class="results-toolbar" aria-label="Search results info">
          <div class="results-bar">
            Showing <span class="results-summary-emphasis"><?= number_format(count($books)) ?></span> of <span class="results-summary-emphasis"><?= number_format($total_books_count) ?></span> matched title<?= $total_books_count === 1 ? '' : 's' ?>
          </div>
          <div class="results-tags">
            <span class="tag">PAGE: <?= $page ?>/<?= $total_pages ?></span>
            <span class="tag">FILTERS: <?= $active_filter_count ?></span>
            <?php if ($q !== ''): ?>
              <span class="tag">QUERY: <?= $q_escaped ?></span>
            <?php endif; ?>
            <?php if ($category !== ''): ?>
              <span class="tag">CAT: <?= $category_escaped ?></span>
            <?php endif; ?>
            <?php if ($availability === 'available'): ?>
              <span class="tag">STATUS: AVAIL</span>
            <?php elseif ($availability === 'unavailable'): ?>
              <span class="tag">STATUS: OUT</span>
            <?php endif; ?>
          </div>
        </section>
      <?php else: ?>
        <!-- Discovery Sections -->
        <?php render_book_section('Featured Selection', $featured_books); ?>
        <?php render_book_section('Highly Requested', $popular_books); ?>
        <?php render_book_section('Recent Additions', $recent_books); ?>
      <?php endif; ?>

      <!-- All Books Section -->
      <section class="all-books-wrapper">
        <?php if (!$is_searching): ?>
          <h2 class="discovery-header">Complete Archive</h2>
        <?php endif; ?>

        <!-- Books Grid -->
        <div class="books-grid" aria-label="Catalog book cards">
          <?php if (empty($books)): ?>
            <div class="empty-state">
              <h3>No Records Found</h3>
              <?php if ($q !== ''): ?>
                <p>We searched the archive but found no matches for "<?= $q_escaped ?>".</p>
              <?php else: ?>
                <p>Your filter combination yielded zero results.</p>
              <?php endif; ?>

              <div class="empty-suggestions">
                <a href="catalog.php" class="suggestion-link">View Complete Archive</a>
                <a href="?sort=newest" class="suggestion-link" title="View newly added books">Recent Additions</a>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($books as $book): ?>
              <?php render_book_card($book); ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1 && !empty($books)): ?>
          <div class="pagination">
            <?php if ($page > 1): ?>
              <a href="?page=1<?php if (build_query_string($q, $category, $availability, $sort, true)) echo '&' . build_query_string($q, $category, $availability, $sort, true); ?>">FIRST</a>
              <a href="?page=<?= $page - 1 ?><?php if (build_query_string($q, $category, $availability, $sort, true)) echo '&' . build_query_string($q, $category, $availability, $sort, true); ?>">PREV</a>
            <?php else: ?>
              <span class="disabled">FIRST</span>
              <span class="disabled">PREV</span>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);

            if ($start > 1): ?>
              <a href="?page=1<?php if (build_query_string($q, $category, $availability, $sort, true)) echo '&' . build_query_string($q, $category, $availability, $sort, true); ?>">1</a>
              <?php if ($start > 2): ?>
                <span class="disabled">...</span>
              <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
              <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
              <?php else: ?>
                <a href="?page=<?= $i ?><?php if (build_query_string($q, $category, $availability, $sort, true)) echo '&' . build_query_string($q, $category, $availability, $sort, true); ?>"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
              <?php if ($end < $total_pages - 1): ?>
                <span class="disabled">...</span>
              <?php endif; ?>
              <a href="?page=<?= $total_pages ?><?php if (build_query_string($q, $category, $availability, $sort, true)) echo '&' . build_query_string($q, $category, $availability, $sort, true); ?>"><?= $total_pages ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
              <a href="?page=<?= $page + 1 ?><?php if (build_query_string($q, $category, $availability, $sort, true)) echo '&' . build_query_string($q, $category, $availability, $sort, true); ?>">NEXT</a>
              <a href="?page=<?= $total_pages ?><?php if (build_query_string($q, $category, $availability, $sort, true)) echo '&' . build_query_string($q, $category, $availability, $sort, true); ?>">LAST</a>
            <?php else: ?>
              <span class="disabled">NEXT</span>
              <span class="disabled">LAST</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <!-- Footer -->
    <footer class="catalog-footer">
      <div>Account required for loans. <a href="login.php">Sign in</a> or <a href="register.php">Create an account</a>.</div>
    </footer>
  </div>

  <!-- Book Detail Modal -->
  <div id="bookModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-content" tabindex="-1">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Book Details</h2>
        <button type="button" class="modal-close" id="modalCloseButton" aria-label="Close book details">&times;</button>
      </div>
      <div class="modal-body">
        <img id="modalCover" class="modal-cover" src="" alt="Book cover">
        <div class="modal-info">
          <p class="modal-author" id="modalAuthor"></p>
          <div class="modal-meta-grid">
            <span class="modal-meta-label">ISBN</span>
            <span id="modalISBN"></span>
            <span class="modal-meta-label">Category</span>
            <span id="modalCategory"></span>
          </div>
          <div id="modalAvailability" class="modal-availability"></div>
        </div>
      </div>
      <div class="modal-action" id="modalAction"></div>
    </div>
  </div>

  <script>
    // Image fallback
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

    const modal = document.getElementById('bookModal');
    const modalPanel = modal ? modal.querySelector('.modal-content') : null;
    const closeModalButton = document.getElementById('modalCloseButton');
    const searchInput = document.getElementById('catalog-search');
    const suggestionsBox = document.getElementById('searchSuggestions');
    const navMenuToggle = document.getElementById('navMenuToggle');
    const navLinks = document.getElementById('catalogNavLinks');
    const navActions = document.getElementById('catalogNavActions');

    let lastFocusedBeforeModal = null;
    let suggestionsData = [];
    let activeSuggestionIndex = -1;
    let debounceTimer;

    function setMobileMenuState(open) {
      if (!navLinks || !navActions || !navMenuToggle) {
        return;
      }

      navLinks.classList.toggle('menu-open', open);
      navActions.classList.toggle('menu-open', open);
      navMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (navMenuToggle) {
      navMenuToggle.addEventListener('click', function() {
        const isOpen = navLinks && navLinks.classList.contains('menu-open');
        setMobileMenuState(!isOpen);
      });

      window.addEventListener('resize', function() {
        if (window.innerWidth > 640) {
          setMobileMenuState(false);
        }
      });
    }

    const focusableSelector = [
      'a[href]',
      'button:not([disabled])',
      'input:not([disabled])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    function isModalOpen() {
      return modal && modal.classList.contains('active');
    }

    function openBookDetail(id, title, author, isbn, category, available, total) {
      if (!modal) {
        return;
      }

      const coverImg = document.getElementById('modalCover');
      document.getElementById('modalTitle').textContent = title;
      document.getElementById('modalAuthor').textContent = 'by ' + author;
      document.getElementById('modalISBN').textContent = isbn ? isbn : 'N/A';
      document.getElementById('modalCategory').textContent = category;

      coverImg.src = 'book-cover-public.php?book_id=' + id;
      coverImg.onerror = function() {
        this.src = 'assets/images/placeholder-book.png';
      };

      const availDiv = document.getElementById('modalAvailability');
      if (available > 0) {
        availDiv.className = 'modal-availability modal-available';
        availDiv.textContent = 'STATUS: AVAILABLE (' + available + '/' + total + ')';
      } else {
        availDiv.className = 'modal-availability modal-unavailable';
        availDiv.textContent = 'STATUS: CHECKED OUT';
      }

      const actionDiv = document.getElementById('modalAction');
      if (available > 0) {
        actionDiv.innerHTML = '<a href="login.php?redirect=borrower/catalog.php" class="btn-reserve">Sign In to Reserve</a>';
      } else {
        actionDiv.innerHTML = '<button class="btn-waitlist" disabled>Unavailable for Reservation</button>';
      }

      lastFocusedBeforeModal = document.activeElement;
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');

      // Trap focus
      setTimeout(() => {
        const focusableElements = modal.querySelectorAll(focusableSelector);
        if (focusableElements.length > 0) {
          focusableElements[0].focus();
        } else {
          modalPanel.focus();
        }
      }, 50);

      document.body.style.overflow = 'hidden';
    }

    function closeBookDetail() {
      if (!modal) return;
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (lastFocusedBeforeModal) {
        lastFocusedBeforeModal.focus();
      }
    }

    // Modal Events
    if (modal) {
      modal.addEventListener('click', function(e) {
        if (e.target === modal) {
          closeBookDetail();
        }
      });
    }

    if (closeModalButton) {
      closeModalButton.addEventListener('click', closeBookDetail);
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && isModalOpen()) {
        closeBookDetail();
      }
    });

    // Trap focus inside modal
    if (modal) {
      modal.addEventListener('keydown', function(e) {
        if (e.key !== 'Tab') return;

        const focusableElements = modal.querySelectorAll(focusableSelector);
        if (focusableElements.length === 0) return;

        const first = focusableElements[0];
        const last = focusableElements[focusableElements.length - 1];

        if (e.shiftKey) {
          if (document.activeElement === first) {
            last.focus();
            e.preventDefault();
          }
        } else {
          if (document.activeElement === last) {
            first.focus();
            e.preventDefault();
          }
        }
      });
    }

    // Attach click listeners to cards
    document.querySelectorAll('.btn-view-details').forEach(card => {
      card.addEventListener('click', function(e) {
        // Prevent opening if they clicked a link inside
        if (e.target.tagName.toLowerCase() === 'a') return;

        openBookDetail(
          this.dataset.bookId,
          this.dataset.title,
          this.dataset.author,
          this.dataset.isbn,
          this.dataset.category,
          parseInt(this.dataset.available, 10),
          parseInt(this.dataset.total, 10)
        );
      });

      card.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.click();
        }
      });
    });

    // --- Auto-suggest Logic ---
    function renderSuggestions() {
      if (!suggestionsData || suggestionsData.length === 0) {
        suggestionsBox.innerHTML = '<div class="suggestion-item"><span class="suggestion-text" style="color:var(--muted)">No matches found.</span></div>';
        suggestionsBox.classList.add('active');
        searchInput.setAttribute('aria-expanded', 'true');
        return;
      }

      suggestionsBox.innerHTML = '';
      suggestionsData.forEach((item, index) => {
        const div = document.createElement('button');
        div.type = 'button';
        div.className = 'suggestion-item';
        if (index === activeSuggestionIndex) {
          div.classList.add('active');
        }
        div.setAttribute('role', 'option');
        div.setAttribute('aria-selected', index === activeSuggestionIndex ? 'true' : 'false');
        div.id = 'suggestion-' + index;

        let icon = '📖';
        if (item.type === 'author') icon = '👤';
        if (item.type === 'isbn') icon = '🏷️';

        div.innerHTML = `
          <span class="suggestion-icon">${icon}</span>
          <span class="suggestion-text">${item.text}</span>
          <span class="suggestion-type">${item.type}</span>
        `;

        div.addEventListener('click', () => {
          searchInput.value = item.text;
          closeSuggestions();
          searchInput.closest('form').submit();
        });

        div.addEventListener('mousemove', () => {
          activeSuggestionIndex = index;
          updateSuggestionHighlight();
        });

        suggestionsBox.appendChild(div);
      });

      suggestionsBox.classList.add('active');
      searchInput.setAttribute('aria-expanded', 'true');

      if (activeSuggestionIndex >= 0) {
        searchInput.setAttribute('aria-activedescendant', 'suggestion-' + activeSuggestionIndex);
      } else {
        searchInput.removeAttribute('aria-activedescendant');
      }
    }

    function closeSuggestions() {
      suggestionsBox.classList.remove('active');
      suggestionsBox.innerHTML = '';
      activeSuggestionIndex = -1;
      searchInput.setAttribute('aria-expanded', 'false');
      searchInput.removeAttribute('aria-activedescendant');
    }

    function updateSuggestionHighlight() {
      const items = suggestionsBox.querySelectorAll('.suggestion-item');
      items.forEach((item, idx) => {
        if (idx === activeSuggestionIndex) {
          item.classList.add('active');
          item.setAttribute('aria-selected', 'true');
          searchInput.setAttribute('aria-activedescendant', item.id);
          item.scrollIntoView({
            block: 'nearest'
          });
        } else {
          item.classList.remove('active');
          item.setAttribute('aria-selected', 'false');
        }
      });
    }

    if (searchInput && suggestionsBox) {
      searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(debounceTimer);

        if (query.length < 2) {
          closeSuggestions();
          return;
        }

        debounceTimer = setTimeout(() => {
          fetch('api/search-suggestions.php?q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
              suggestionsData = data.suggestions || [];
              activeSuggestionIndex = -1;
              renderSuggestions();
            })
            .catch(err => {
              console.error('Suggestion error:', err);
              closeSuggestions();
            });
        }, 300);
      });

      searchInput.addEventListener('keydown', function(e) {
        if (!suggestionsBox.classList.contains('active')) return;

        const items = suggestionsBox.querySelectorAll('.suggestion-item');
        if (items.length === 0) return;

        if (e.key === 'ArrowDown') {
          e.preventDefault();
          activeSuggestionIndex = (activeSuggestionIndex + 1) % items.length;
          updateSuggestionHighlight();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          activeSuggestionIndex = (activeSuggestionIndex - 1 + items.length) % items.length;
          updateSuggestionHighlight();
        } else if (e.key === 'Enter') {
          if (activeSuggestionIndex >= 0) {
            e.preventDefault();
            items[activeSuggestionIndex].click();
          }
        } else if (e.key === 'Escape') {
          closeSuggestions();
        }
      });

      // Close on outside click
      document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
          closeSuggestions();
        }
      });
    }
  </script>
</body>

</html>