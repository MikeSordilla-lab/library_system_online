<?php

/**
 * librarian/catalog-add.php — Add a New Book (US2, FR-002, FR-005, FR-006, FR-011, FR-012)
 *
 * GET:  Render the add-book form.
 * POST: Validate via catalog module, INSERT, log, redirect with flash.
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
require_once __DIR__ . '/../src/utils/catalog.php'; // h(), validate_book_fields, validate_cover_upload, add_book, handle_book_cover

$errors = [];
$pdo    = get_db();

// ─── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $actor_id   = (int) $_SESSION['user_id'];
  $actor_role = (string) $_SESSION['role'];

  // Validate book fields
  $errors = validate_book_fields($_POST);

  // Validate cover upload (only if no field errors)
  $cover = ['valid' => false, 'data' => null, 'mime' => null, 'error' => null];
  if (empty($errors) && isset($_FILES['cover_image'])) {
    $cover = validate_cover_upload($_FILES['cover_image']);
    if ($cover['error'] !== null) {
      $errors[] = $cover['error'];
    }
  }

  if (empty($errors)) {
    $result = add_book($pdo, $_POST, $actor_id, $actor_role);

    if ($result['success']) {
      $new_id = $result['book_id'];

      // Persist cover if uploaded
      if ($cover['data'] !== null) {
        handle_book_cover($pdo, $new_id, $cover['data'], $cover['mime'], false, $actor_id, $actor_role);
      }

      $_SESSION['flash_success'] = 'Book added successfully.';
      header('Location: catalog.php');
      exit;
    }
    $errors = $result['errors'];
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
