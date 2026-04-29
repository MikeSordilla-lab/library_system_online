<?php

/**
 * librarian/catalog-edit.php — Edit an Existing Book (US3, FR-003, FR-005, FR-006, FR-011, FR-012)
 *
 * GET:  Load the book by id; render pre-populated edit form.
 * POST: Validate via catalog module, UPDATE, handle cover, redirect with flash.
 *
 * Accessible to: librarian only
 */

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/../config.php';
}
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Borrower pre-check
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'borrower') {
  $_SESSION['flash_error'] = 'You do not have permission to access that page.';
  header('Location: ' . BASE_URL . 'borrower/index.php');
  exit;
}

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../src/utils/catalog.php'; // h(), validate_book_fields, validate_cover_upload, update_book, handle_book_cover

$pdo    = get_db();
$errors = [];
$book   = null;

// ─── Resolve the book id ─────────────────────────────────────────────────────
$raw_id = ($_SERVER['REQUEST_METHOD'] === 'POST')
  ? ($_POST['id'] ?? '')
  : ($_GET['id']  ?? '');

$book_id = (ctype_digit((string)$raw_id) && (int)$raw_id > 0)
  ? (int)$raw_id
  : 0;

if ($book_id === 0) {
  header('Location: catalog.php');
  exit;
}

// ─── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $actor_id   = (int) $_SESSION['user_id'];
  $actor_role = (string) $_SESSION['role'];

  // Handle cover image upload/remove logic
  $remove_cover   = isset($_POST['remove_cover']) && $_POST['remove_cover'] === '1';
  $has_new_file   = isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE;

  // Validate book fields
  $errors = validate_book_fields($_POST);

  // Validate cover upload
  $cover = ['valid' => false, 'data' => null, 'mime' => null, 'error' => null];
  if ($has_new_file && empty($errors)) {
    $cover = validate_cover_upload($_FILES['cover_image']);
    if ($cover['error'] !== null) {
      $errors[] = $cover['error'];
    }
  }

  if (empty($errors)) {
    $result = update_book($pdo, $book_id, $_POST, $actor_id, $actor_role);

    if ($result['success']) {
      // Handle cover: upload takes precedence over remove
      if ($cover['data'] !== null) {
        handle_book_cover($pdo, $book_id, $cover['data'], $cover['mime'], false, $actor_id, $actor_role);
      } elseif ($remove_cover && !$has_new_file) {
        handle_book_cover($pdo, $book_id, null, null, true, $actor_id, $actor_role);
      }

      $_SESSION['flash_success'] = 'Book updated successfully.';
      header('Location: catalog.php');
      exit;
    }
    $errors = $result['errors'];
  }

  // Validation failed — re-render form using POST values
  $book = [
    'id'               => $book_id,
    'title'            => trim($_POST['title'] ?? ''),
    'author'           => trim($_POST['author'] ?? ''),
    'description'      => trim($_POST['description'] ?? ''),
    'isbn'             => trim($_POST['isbn'] ?? ''),
    'category'         => trim($_POST['category'] ?? ''),
    'total_copies'     => trim($_POST['total_copies'] ?? ''),
    'available_copies' => trim($_POST['available_copies'] ?? ''),
  ];
  $cv = $pdo->prepare('SELECT 1 FROM book_covers WHERE book_id = ?');
  $cv->execute([$book_id]);
  $has_cover = (bool)$cv->fetchColumn();
} else {
  // ─── GET: load existing book ─────────────────────────────────────────────
  $stmt = $pdo->prepare('SELECT * FROM Books WHERE id = ?');
  $stmt->execute([$book_id]);
  $book = $stmt->fetch();

  if ($book === false) {
    header('Location: catalog.php');
    exit;
  }

  // Check if a cover is stored for this book
  $cv = $pdo->prepare('SELECT 1 FROM book_covers WHERE book_id = ?');
  $cv->execute([$book_id]);
  $has_cover = (bool)$cv->fetchColumn();
}

$name       = htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
$logout_url = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');
$current_page = 'librarian.catalog';
$pageTitle    = 'Edit Book | Library System';
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
    <main class="main-content librarian-catalog-form-page">
      <div class="page-header">
        <h1>Edit Book</h1>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true">
          <strong>Please fix the following errors:</strong>
          <ul>
            <?php foreach ($errors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Book Details</span>
        </div>
        <form method="POST" action="catalog-edit.php" enctype="multipart/form-data" novalidate class="librarian-catalog-form">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= (int)$book['id'] ?>">

          <div class="librarian-form-field">
            <label class="field-label" for="title">Title <span class="field-label__required">*</span></label>
            <input class="field-input" type="text" id="title" name="title" maxlength="255"
              value="<?= h($book['title'] ?? '') ?>" required>
          </div>

          <div class="librarian-form-field">
            <label class="field-label" for="author">Author <span class="field-label__required">*</span></label>
            <input class="field-input" type="text" id="author" name="author" maxlength="255"
              value="<?= h($book['author'] ?? '') ?>" required>
          </div>

          <div class="librarian-form-field">
            <label class="field-label" for="description">Description <span class="field-label__required">*</span></label>
            <textarea class="field-textarea" id="description" name="description" required><?= h($book['description'] ?? '') ?></textarea>
          </div>

          <div class="librarian-form-row">
            <div class="librarian-form-col">
              <label class="field-label" for="isbn">ISBN <span class="field-label__required">*</span></label>
              <input class="field-input" type="text" id="isbn" name="isbn" maxlength="20"
                value="<?= h($book['isbn'] ?? '') ?>" required>
            </div>
            <div class="librarian-form-col">
              <label class="field-label" for="category">Category <span class="field-label__required">*</span></label>
              <input class="field-input" type="text" id="category" name="category" maxlength="100"
                value="<?= h($book['category'] ?? '') ?>" required>
            </div>
          </div>

          <div class="librarian-form-row">
            <div class="librarian-form-col">
              <label class="field-label" for="total_copies">Total Copies <span class="field-label__required">*</span></label>
              <input class="field-input" type="number" id="total_copies" name="total_copies" min="0"
                value="<?= h((string)($book['total_copies'] ?? 0)) ?>" required>
            </div>
            <div class="librarian-form-col">
              <label class="field-label" for="available_copies">Available Copies <span class="field-label__required">*</span></label>
              <input class="field-input" type="number" id="available_copies" name="available_copies" min="0"
                value="<?= h((string)($book['available_copies'] ?? 0)) ?>" required>
            </div>
          </div>

          <div class="librarian-form-field">
            <label class="field-label" for="cover_image">Cover Image <span class="field-label__hint">(optional — JPEG, PNG, WebP, or GIF, max 2 MB)</span></label>
            <?php if ($has_cover): ?>
              <div class="librarian-cover-preview">
                <img src="<?= h(BASE_URL . 'public/book-cover.php?book_id=' . (int)$book['id']) ?>" alt="Current cover"
                  onerror="this.onerror=null;this.src='<?= h(BASE_URL . 'assets/images/placeholder-book.png') ?>';"
                  style="height:80px;width:60px;object-fit:cover;border-radius:4px;border:1px solid var(--border)">

                <p class="librarian-cover-help">Current cover — upload a new file to replace it.</p>
                <label class="librarian-cover-remove-label">
                  <input type="checkbox" name="remove_cover" value="1"> Remove current cover
                </label>
              </div>
            <?php endif; ?>
            <input class="field-input" type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/webp,image/gif">
          </div>

          <div class="librarian-form-actions">
            <button type="submit" class="btn-primary">Save Changes</button>
            <a href="catalog.php" class="btn-ghost">Cancel</a>
          </div>
        </form>
      </div>
    </main>
  </div>
</body>

</html>
