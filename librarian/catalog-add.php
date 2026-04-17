<?php

/**
 * librarian/catalog-add.php — Add a New Book (US2, FR-002, FR-005, FR-006, FR-011, FR-012)
 *
 * GET:  Render the add-book form.
 * POST: Validate, check uniqueness, INSERT via PDO, log, redirect with flash.
 *
 * Accessible to: librarian only
 */

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/../config.php';
}
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Borrower pre-check — before auth_guard to set a helpful redirect + flash
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

$errors = [];

// ─── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 1. CSRF validation (FR-011)
  csrf_verify();

  // 2. Collect and trim all fields
  $title           = trim($_POST['title']           ?? '');
  $author          = trim($_POST['author']          ?? '');
  $description     = trim($_POST['description']     ?? '');
  $isbn            = trim($_POST['isbn']            ?? '');
  $category        = trim($_POST['category']        ?? '');
  $total_copies    = trim($_POST['total_copies']    ?? '');
  $available_copies = trim($_POST['available_copies'] ?? '');

  // 3. Required-field + max-length validation (FR-005)
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

  // 4. Title + Author uniqueness check (FR-012)
  if (empty($errors)) {
    $pdo = get_db();
    $chk = $pdo->prepare('SELECT id FROM Books WHERE title = ? AND author = ?');
    $chk->execute([$title, $author]);
    if ($chk->fetchColumn() !== false) {
      $errors[] = 'A book with this title and author already exists.';
    }
  }

  // 5. Handle optional cover image upload (FR-001 to FR-003, FR-005, FR-013 — BLOB storage)
  $cover_image_data = null;
  $cover_mime       = null;
  if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['cover_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
      $errors[] = 'File upload failed (error code ' . (int)$file['error'] . ').';
    } elseif ($file['size'] > 2_097_152) {
      $errors[] = 'Cover image must be 2 MB or smaller.';
    } else {
      $finfo    = new finfo(FILEINFO_MIME_TYPE);
      $mime     = $finfo->file($file['tmp_name']);
      $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
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

  // 6. Insert if all validations pass (FR-006 — PDO prepared statement)
  if (empty($errors)) {
    $pdo  = get_db();
    $stmt = $pdo->prepare(
      'INSERT INTO Books (title, author, description, isbn, category, total_copies, available_copies)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
      $title,
      $author,
      $description !== '' ? $description : null,
      $isbn,
      $category,
      (int)$total_copies,
      (int)$available_copies,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    // 7. Audit log — book creation (Constitution Principle V)
    log_event(
      $pdo,
      'BOOK_CREATE',
      (int)$_SESSION['user_id'],
      'Books',
      $new_id,
      'SUCCESS',
      $_SESSION['role']
    );

    // 8. Store cover BLOB if one was uploaded (FR-004, FR-005)
    if ($cover_image_data !== null) {
      $cstmt = $pdo->prepare(
        'INSERT INTO book_covers (book_id, image_data, mime_type) VALUES (?, ?, ?)'
      );
      $cstmt->bindParam(1, $new_id, PDO::PARAM_INT);
      $cstmt->bindParam(2, $cover_image_data, PDO::PARAM_LOB);
      $cstmt->bindParam(3, $cover_mime, PDO::PARAM_STR);
      $cstmt->execute();

      // Audit log — cover upload (FR-017)
      log_event(
        $pdo,
        'COVER_UPLOAD',
        (int)$_SESSION['user_id'],
        'book_covers',
        $new_id,
        'SUCCESS',
        $_SESSION['role']
      );
    }

    // 9. Flash + redirect
    $_SESSION['flash_success'] = 'Book added successfully.';
    header('Location: catalog.php');
    exit;
  }

  // Validation failed — fall through to re-render form with $errors and $_POST values
}

$name       = htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
$logout_url = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');

// Repopulate form fields from POST on validation failure, or empty on first GET
$f = [
  'title'            => $_POST['title']            ?? '',
  'author'           => $_POST['author']           ?? '',
  'description'      => $_POST['description']      ?? '',
  'isbn'             => $_POST['isbn']             ?? '',
  'category'         => $_POST['category']         ?? '',
  'total_copies'     => $_POST['total_copies']     ?? '0',
  'available_copies' => $_POST['available_copies'] ?? '0',
];
$current_page = 'librarian.catalog';
$pageTitle    = 'Add Book | Library System';
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
        <h1>Add Book</h1>
      </div>

      <?php if (!empty($errors)): ?>
        <div id="catalog-add-error" data-message="<?= h($errors[0]) ?>" style="display: none;"></div>
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
        <form method="POST" action="catalog-add.php" enctype="multipart/form-data" novalidate class="librarian-catalog-form">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

          <div class="librarian-form-field">
            <label class="field-label" for="title">Title <span class="field-label__required">*</span></label>
            <input class="field-input" type="text" id="title" name="title" maxlength="255"
              value="<?= h($f['title']) ?>" required>
          </div>

          <div class="librarian-form-field">
            <label class="field-label" for="author">Author <span class="field-label__required">*</span></label>
            <input class="field-input" type="text" id="author" name="author" maxlength="255"
              value="<?= h($f['author']) ?>" required>
          </div>

          <div class="librarian-form-field">
            <label class="field-label" for="description">Description <span class="field-label__required">*</span></label>
            <textarea class="field-textarea" id="description" name="description" required><?= h($f['description']) ?></textarea>
          </div>

          <div class="librarian-form-row">
            <div class="librarian-form-col">
              <label class="field-label" for="isbn">ISBN <span class="field-label__required">*</span></label>
              <input class="field-input" type="text" id="isbn" name="isbn" maxlength="20"
                value="<?= h($f['isbn']) ?>" required>
            </div>
            <div class="librarian-form-col">
              <label class="field-label" for="category">Category <span class="field-label__required">*</span></label>
              <input class="field-input" type="text" id="category" name="category" maxlength="100"
                value="<?= h($f['category']) ?>" required>
            </div>
          </div>

          <div class="librarian-form-row">
            <div class="librarian-form-col">
              <label class="field-label" for="total_copies">Total Copies <span class="field-label__required">*</span></label>
              <input class="field-input" type="number" id="total_copies" name="total_copies" min="0"
                value="<?= h($f['total_copies']) ?>" required>
            </div>
            <div class="librarian-form-col">
              <label class="field-label" for="available_copies">Available Copies <span class="field-label__required">*</span></label>
              <input class="field-input" type="number" id="available_copies" name="available_copies" min="0"
                value="<?= h($f['available_copies']) ?>" required>
            </div>
          </div>

          <div class="librarian-form-field">
            <label class="field-label" for="cover_image">Cover Image <span class="field-label__hint">(optional — JPEG, PNG, WebP, or GIF, max 2 MB)</span></label>
            <input class="field-input" type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/webp,image/gif">
          </div>

          <div class="librarian-form-actions">
            <button type="submit" class="btn-primary">Add Book</button>
            <a href="catalog.php" class="btn-ghost">Cancel</a>
          </div>
        </form>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      'use strict';
      
      if (typeof sweetAlertUtils === 'undefined') {
        return;
      }
      
      const errorNotice = document.getElementById('catalog-add-error');
      
      if (errorNotice) {
        const message = errorNotice.getAttribute('data-message');
        if (message) {
          setTimeout(async function() {
            await sweetAlertUtils.showError('Error Adding Book', message);
          }, 300);
        }
      }
    });
  </script>
</body>

</html>
