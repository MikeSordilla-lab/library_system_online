<?php

/**
 * librarian/catalog-edit.php — Edit an Existing Book (US3, FR-003, FR-005, FR-006, FR-011, FR-012)
 *
 * GET:  Load the book by id; render pre-populated edit form.
 * POST: Validate, check uniqueness (excluding self), UPDATE via PDO, log, redirect.
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

/** Null-safe HTML escape helper (FR-007) */
if (!function_exists('h')) {
  function h(?string $v): string
  {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
  }
}

$pdo    = get_db();
$errors = [];
$book   = null;

// ─── Resolve the book id ─────────────────────────────────────────────────────
// For GET: from query string. For POST: from hidden form field.
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

  // 1. CSRF validation (FR-011)
  csrf_verify();

  // 2. Confirm record still exists
  $chk = $pdo->prepare('SELECT id FROM Books WHERE id = ?');
  $chk->execute([$book_id]);
  if ($chk->fetchColumn() === false) {
    header('Location: catalog.php');
    exit;
  }

  // 3. Collect and trim fields
  $title            = trim($_POST['title']            ?? '');
  $author           = trim($_POST['author']           ?? '');
  $description      = trim($_POST['description']      ?? '');
  $isbn             = trim($_POST['isbn']             ?? '');
  $category         = trim($_POST['category']         ?? '');
  $total_copies     = trim($_POST['total_copies']     ?? '');
  $available_copies = trim($_POST['available_copies'] ?? '');

  // 4. Required-field + max-length validation (FR-005)
  if ($title === '') {
    $errors[] = 'Title is required.';
  } elseif (mb_strlen($title) > 255) {
    $errors[] = 'Title must be 255 characters or fewer.';
  }

  if ($author === '') {
    $errors[] = 'Author is required.';
  } elseif (mb_strlen($author) > 255) {
    $errors[] = 'Author must be 255 characters or fewer.';
  }

  if ($description === '') {
    $errors[] = 'Description is required.';
  }

  if ($isbn === '') {
    $errors[] = 'ISBN is required.';
  } elseif (mb_strlen($isbn) > 20) {
    $errors[] = 'ISBN must be 20 characters or fewer.';
  }

  if ($category === '') {
    $errors[] = 'Category is required.';
  } elseif (mb_strlen($category) > 100) {
    $errors[] = 'Category must be 100 characters or fewer.';
  }

  if (!ctype_digit($total_copies) || (int)$total_copies < 0) {
    $errors[] = 'Total copies must be a non-negative whole number.';
  }

  if (!ctype_digit($available_copies) || (int)$available_copies < 0) {
    $errors[] = 'Available copies must be a non-negative whole number.';
  }

  if (empty($errors) && (int)$available_copies > (int)$total_copies) {
    $errors[] = 'Available copies cannot exceed total copies.';
  }

  // 5. Title + Author uniqueness (excluding current record) (FR-012)
  if (empty($errors)) {
    $uniq = $pdo->prepare(
      'SELECT id FROM Books WHERE title = ? AND author = ? AND id != ?'
    );
    $uniq->execute([$title, $author, $book_id]);
    if ($uniq->fetchColumn() !== false) {
      $errors[] = 'A book with this title and author already exists.';
    }
  }

  // 6. Handle cover image upload and remove logic (Feature 008 — BLOB storage)
  $remove_cover   = isset($_POST['remove_cover']) && $_POST['remove_cover'] === '1';
  $has_new_file   = isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE;
  $cover_image_data = null;
  $cover_mime       = null;

  if ($has_new_file && empty($errors)) {
    $file = $_FILES['cover_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
      $errors[] = 'Cover image upload failed (error code ' . (int)$file['error'] . ').';
    } elseif ($file['size'] > 2_097_152) {
      $errors[] = 'Cover image must be 2 MB or smaller.';
    } else {
      $finfo   = new finfo(FILEINFO_MIME_TYPE);
      $mime    = $finfo->file($file['tmp_name']);
      $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
      if (!in_array($mime, $allowed, true)) {
        $errors[] = 'Only JPEG, PNG, WebP, and GIF images are accepted.';
      } else {
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false || @getimagesizefromstring($raw) === false) {
          $errors[] = 'The uploaded file does not appear to be a valid image.';
        } else {
          $cover_image_data = $raw;
          $cover_mime       = $mime;
        }
      }
    }
  }

  // 7. Update if all validations pass (FR-006 — PDO prepared statement)
  if (empty($errors)) {
    $stmt = $pdo->prepare(
      'UPDATE Books
                SET title=?, author=?, description=?, isbn=?,
                    category=?, total_copies=?, available_copies=?
              WHERE id=?'
    );
    $stmt->execute([
      $title,
      $author,
      $description !== '' ? $description : null,
      $isbn,
      $category,
      (int)$total_copies,
      (int)$available_copies,
      $book_id,
    ]);

    // 8. Audit log — book update (Constitution Principle V)
    log_event(
      $pdo,
      'BOOK_UPDATE',
      (int)$_SESSION['user_id'],
      'Books',
      $book_id,
      'SUCCESS',
      $_SESSION['role']
    );

    // 9. Cover image action (Feature 008 — BLOB storage, FR-008 to FR-017)
    if ($has_new_file && $cover_image_data !== null) {
      // Determine whether this is a new upload or a replacement (for audit log)
      $exists_chk = $pdo->prepare('SELECT 1 FROM book_covers WHERE book_id = ?');
      $exists_chk->execute([$book_id]);
      $cover_existed = (bool)$exists_chk->fetchColumn();

      $cstmt = $pdo->prepare(
        'INSERT INTO book_covers (book_id, image_data, mime_type)
               VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE image_data = VALUES(image_data),
                                        mime_type  = VALUES(mime_type),
                                        updated_at = NOW()'
      );
      $cstmt->bindParam(1, $book_id, PDO::PARAM_INT);
      $cstmt->bindParam(2, $cover_image_data, PDO::PARAM_LOB);
      $cstmt->bindParam(3, $cover_mime, PDO::PARAM_STR);
      $cstmt->execute();

      $action = $cover_existed ? 'COVER_REPLACE' : 'COVER_UPLOAD';
      log_event($pdo, $action, (int)$_SESSION['user_id'], 'book_covers', $book_id, 'SUCCESS', $_SESSION['role']);
    } elseif ($remove_cover && !$has_new_file) {
      // Remove cover (FR-015) — upload takes precedence over remove (FR-016)
      $pdo->prepare('DELETE FROM book_covers WHERE book_id = ?')->execute([$book_id]);
      log_event($pdo, 'COVER_DELETE', (int)$_SESSION['user_id'], 'book_covers', $book_id, 'SUCCESS', $_SESSION['role']);
    }
    // Else: no file uploaded, remove not checked — existing cover preserved unchanged (FR-009)

    $_SESSION['flash_success'] = 'Book updated successfully.';
    header('Location: catalog.php');
    exit;
  }

  // Validation failed — re-render form using POST values (preserve user input)
  $book = [
    'id'               => $book_id,
    'title'            => $title,
    'author'           => $author,
    'description'      => $description,
    'isbn'             => $isbn,
    'category'         => $category,
    'total_copies'     => $total_copies,
    'available_copies' => $available_copies,
  ];
  // Check cover status for re-render
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
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
