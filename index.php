<?php

/**
 * index.php — Public Landing Page
 *
 * Replaces the developer smoke test with a beautiful, user-facing landing page.
 */

$configPath = __DIR__ . '/config.php';

if (!file_exists($configPath)) {
  http_response_code(503);
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    . '<title>Setup Required</title></head><body>'
    . '<h1>Setup Required</h1>'
    . '<p>The file <code>config.php</code> is missing.</p>'
    . '<p>Copy <code>config.sample.php</code> to <code>config.php</code> '
    . 'and fill in your database credentials, then refresh this page.</p>'
    . '</body></html>';
  exit;
}

require_once __DIR__ . '/includes/db.php';
$pdo = get_db();

// Optional: Fetch some quick stats for the hero section
try {
  $books_count = (int) $pdo->query('SELECT COUNT(*) FROM Books')->fetchColumn();
  // Fetch 3 random books with covers for the hero section
  $hero_books = $pdo->query('
        SELECT b.id 
        FROM Books b 
        JOIN book_covers bc ON b.id = bc.book_id 
        ORDER BY RAND() 
        LIMIT 3
    ')->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
  $books_count = 0;
  $hero_books = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome | Library System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/libris.css">
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
      gap: var(--space-4);
      text-decoration: none;
      color: var(--ink);
      font-family: var(--font-serif);
      font-size: var(--text-2xl);
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    .brand img {
      max-width: min(58vw, 220px);
      width: auto;
      height: 48px;
    }

    .brand span {
      line-height: 1.08;
    }

    .nav-links {
      display: flex;
      gap: var(--space-8);
      flex-wrap: wrap;
    }

    .nav-links a {
      font-family: var(--font-sans);
      font-size: var(--text-sm);
      font-weight: 600;
      letter-spacing: 0.01em;
      color: var(--muted);
      text-decoration: none;
      transition: color 0.2s ease-out;
      min-height: 44px;
      display: inline-flex;
      align-items: center;
    }

    .nav-links a:hover,
    .nav-links a.active {
      color: var(--ink);
    }

    .nav-actions {
      display: flex;
      gap: var(--space-6);
      align-items: center;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .nav-actions a {
      font-family: var(--font-sans);
      font-size: var(--text-sm);
      font-weight: 600;
      letter-spacing: 0.01em;
      text-decoration: none;
      min-height: 44px;
      display: inline-flex;
      align-items: center;
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

    /* Editorial Grid Layout */
    .editorial-grid {
      display: grid;
      grid-template-columns: repeat(12, 1fr);
      gap: var(--space-4);
      flex: 1;
      padding: var(--space-8) var(--space-8);
      align-items: end;
      max-width: 1600px;
      margin: 0 auto;
      width: 100%;
      box-sizing: border-box;
      /* Keep grid padding from causing viewport overflow. */
    }

    .editorial-content {
      grid-column: 1 / 8;
      padding-bottom: var(--space-12);
    }

    .editorial-dateline {
      font-family: var(--font-mono);
      font-size: var(--text-xs);
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.15em;
      margin-bottom: var(--space-6);
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }

    .editorial-dateline::before {
      content: "";
      display: block;
      width: 60px;
      height: 1px;
      background: var(--ink);
    }

    .editorial-headline {
      font-family: var(--font-serif);
      font-size: clamp(4rem, 8vw, 9rem);
      line-height: 0.9;
      letter-spacing: -0.04em;
      color: var(--ink);
      margin: 0 0 var(--space-6) 0;
      font-weight: 400;
    }

    .editorial-headline i {
      font-style: italic;
      color: var(--sage);
      padding-right: 0.1em;
    }

    .editorial-sub {
      font-size: var(--text-lg);
      max-width: 42ch;
      color: var(--muted);
      margin-bottom: var(--space-10);
      line-height: 1.6;
    }

    .editorial-actions {
      display: flex;
      gap: var(--space-4);
      flex-wrap: wrap;
    }

    .editorial-btn {
      font-family: var(--font-sans);
      font-size: var(--text-sm);
      font-weight: 600;
      letter-spacing: 0.02em;
      padding: var(--space-4) var(--space-8);
      text-decoration: none;
      transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), background-color 0.2s;
      border: 1px solid var(--ink);
      border-radius: 0;
      min-height: 48px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .editorial-btn--primary {
      background: var(--ink);
      color: var(--paper);
    }

    .editorial-btn--primary:hover {
      transform: scale(0.98);
      background: color-mix(in srgb, var(--ink) 90%, transparent);
    }

    .editorial-btn--secondary {
      background: transparent;
      color: var(--ink);
    }

    .editorial-btn--secondary:hover {
      background: color-mix(in srgb, var(--ink) 5%, transparent);
    }

    /* Flat Overlapping Gallery */
    .editorial-gallery {
      grid-column: 8 / 13;
      position: relative;
      height: clamp(320px, 44vw, 600px);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      /* Keep decorative stack inside viewport on narrow screens. */
    }

    .gallery-book {
      position: absolute;
      width: min(260px, 40vw);
      aspect-ratio: 2 / 3;
      background: var(--cream);
      box-shadow: 0 32px 64px rgba(0, 0, 0, 0.15);
      border: 1px solid color-mix(in srgb, var(--border) 60%, transparent);
    }

    .gallery-book img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .book-bottom {
      top: 15%;
      left: 10%;
      z-index: 1;
      transform: rotate(-6deg);
    }

    .book-middle {
      top: 25%;
      left: 20%;
      z-index: 2;
      transform: rotate(3deg);
    }

    .book-top {
      top: 35%;
      left: 35%;
      z-index: 3;
      transform: rotate(-2deg);
    }

    @media (max-width: 1024px) {
      .editorial-grid {
        grid-template-columns: 1fr;
        padding-bottom: var(--space-8);
      }

      .editorial-content {
        grid-column: 1 / -1;
        padding-bottom: var(--space-6);
      }

      .editorial-gallery {
        grid-column: 1 / -1;
        height: 450px;
        justify-content: flex-start;
      }

      .book-bottom {
        top: 0;
        left: 0;
      }

      .book-middle {
        top: 10%;
        left: 15%;
      }

      .book-top {
        top: 20%;
        left: 30%;
      }
    }

    @media (max-width: 768px) {
      .editorial-nav {
        flex-direction: column;
        gap: var(--space-6);
        padding: var(--space-6) var(--space-4);
      }

      .brand img {
        height: 40px;
        max-width: 150px;
      }

      .nav-links {
        gap: var(--space-4);
        justify-content: center;
      }

      .editorial-headline {
        font-size: clamp(3.5rem, 12vw, 5rem);
      }

      .editorial-actions {
        flex-direction: column;
      }

      .editorial-btn {
        text-align: center;
      }

      .editorial-gallery {
        height: clamp(220px, 58vw, 300px);
      }

      .gallery-book {
        width: min(160px, 42vw);
      }

      .book-bottom {
        left: 0;
      }

      .book-middle {
        left: 18%;
      }

      .book-top {
        left: 34%;
      }
    }
  </style>
</head>

<body>

  <nav class="editorial-nav" aria-label="Main Navigation">
    <a href="<?= BASE_URL ?>index.php" class="brand">
      <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="Library System Logo" width="220" height="48" decoding="async" style="height: 48px; width: auto; object-fit: contain;" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
      <span>Library System</span>
    </a>

    <div class="nav-links">
      <a href="<?= BASE_URL ?>index.php" class="active">Index</a>
      <a href="<?= BASE_URL ?>catalog.php">Catalog</a>
    </div>

    <div class="nav-actions">
      <a href="<?= BASE_URL ?>login.php" class="btn-login">Sign In</a>
      <a href="<?= BASE_URL ?>register.php" class="btn-signup">Join Library</a>
    </div>
  </nav>

  <main class="editorial-grid page-fade-in">
    <div class="editorial-content">
      <div class="editorial-dateline">
        VOL. 1 • <?= $books_count > 0 ? number_format($books_count) : '5,000' ?>+ VOLUMES • ARCHIVE
      </div>
      <h1 class="editorial-headline">
        Find<br>
        next great <i>read.</i>
      </h1>
      <p class="editorial-sub">
        Access an extensive archive of literature, research materials, and historical texts. Knowledge meticulously curated and ready for exploration.
      </p>

      <div class="editorial-actions">
        <a href="<?= BASE_URL ?>catalog.php" class="editorial-btn editorial-btn--primary">Browse Archive</a>
        <a href="<?= BASE_URL ?>register.php" class="editorial-btn editorial-btn--secondary">Join Library</a>
      </div>
    </div>

    <div class="editorial-gallery" aria-hidden="true">
      <?php
      $cover_1 = !empty($hero_books[0]) ? BASE_URL . "book-cover-public.php?book_id={$hero_books[0]}" : BASE_URL . 'assets/images/placeholder-book.png';
      $cover_2 = !empty($hero_books[1]) ? BASE_URL . "book-cover-public.php?book_id={$hero_books[1]}" : BASE_URL . 'assets/images/placeholder-book.png';
      $cover_3 = !empty($hero_books[2]) ? BASE_URL . "book-cover-public.php?book_id={$hero_books[2]}" : BASE_URL . 'assets/images/placeholder-book.png';
      ?>
      <div class="gallery-book book-bottom">
        <img src="<?= htmlspecialchars($cover_3) ?>" alt="" loading="lazy" decoding="async" width="260" height="390" sizes="(max-width: 768px) 42vw, (max-width: 1024px) 30vw, 260px">
      </div>
      <div class="gallery-book book-middle">
        <img src="<?= htmlspecialchars($cover_2) ?>" alt="" loading="lazy" decoding="async" width="260" height="390" sizes="(max-width: 768px) 42vw, (max-width: 1024px) 30vw, 260px">
      </div>
      <div class="gallery-book book-top">
        <img src="<?= htmlspecialchars($cover_1) ?>" alt="" loading="lazy" decoding="async" width="260" height="390" sizes="(max-width: 768px) 42vw, (max-width: 1024px) 30vw, 260px">
      </div>
    </div>
  </main>

</body>

</html>