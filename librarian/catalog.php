<?php

/**
 * librarian/catalog.php — Book Catalog Management (US1, FR-001, FR-007–FR-009)
 *
 * Accessible to: librarian only
 * Borrowers are redirected to their dashboard with a flash error.
 * Unauthenticated users are redirected to login.php via auth_guard.
 */

// Load config for BASE_URL before the session-dependent pre-check
if (!defined('BASE_URL')) {
  require_once __DIR__ . '/../config.php';
}

// Start session for Borrower pre-check (auth_guard will skip if already active)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Borrower pre-check — must run before auth_guard (which would emit a 403 instead)
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'borrower') {
  $_SESSION['flash_error'] = 'You do not have permission to access that page.';
  header('Location: ' . BASE_URL . 'borrower/index.php');
  exit;
}

// RBAC guard — handles unauthenticated (→ login.php) and other unauthorised roles (→ 403)
$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';

// CSRF helper (needed for per-row delete forms on this page)
require_once __DIR__ . '/../includes/csrf.php';

// Read and clear flash messages set by create / edit / delete handlers
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Fetch all books ordered alphabetically by title
$pdo  = get_db();
$stmt = $pdo->prepare(
  'SELECT id, title, author, description, isbn, category, total_copies, available_copies
       FROM Books
      ORDER BY title ASC'
);
$stmt->execute();
$books = $stmt->fetchAll();

$name        = htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
$logout_url  = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');
$catalog_url = htmlspecialchars(BASE_URL . 'librarian/catalog.php', ENT_QUOTES, 'UTF-8');

/**
 * Helper: escape a value for HTML output, treating NULL as empty string.
 * Prevents XSS (FR-007) and PHP 8.2 TypeError on nullable `description`.
 */
function h(?string $v): string
{
  return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
$current_page = 'librarian.catalog';
$pageTitle    = 'Book Catalog | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php
    if ($_SESSION['role'] === 'admin') {
      require_once __DIR__ . '/../includes/sidebar-admin.php';
    } else {
      require_once __DIR__ . '/../includes/sidebar-librarian.php';
    }
    ?>
    <main class="main-content">
      <div class="page-header">
        <h1>Book Catalog</h1>
        <a href="catalog-add.php" class="btn-primary">+ Add Book</a>
      </div>

      <?php if ($flash_success !== ''): ?>
        <div id="catalog-success" data-message="<?= h($flash_success) ?>" style="display: none;"></div>
        <div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true"><?= h($flash_success) ?></div>
      <?php endif; ?>
      <?php if ($flash_error !== ''): ?>
        <div id="catalog-error" data-message="<?= h($flash_error) ?>" style="display: none;"></div>
        <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true"><?= h($flash_error) ?></div>
      <?php endif; ?>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">All Books</span>
        </div>

        <?php if (empty($books)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#128218;</span>
            <p>No books in the catalog yet. <a href="catalog-add.php">Add the first one.</a></p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Author</th>
                  <th>ISBN</th>
                  <th>Category</th>
                  <th>Available</th>
                  <th>Total</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($books as $book): ?>
                  <tr>
                    <td data-label="Title"><strong><?= h($book['title']) ?></strong></td>
                    <td data-label="Author"><?= h($book['author']) ?></td>
                    <td data-label="ISBN"><?= h($book['isbn']) ?></td>
                    <td data-label="Category"><?= h($book['category']) ?></td>
                    <td data-label="Available">
                      <?php if ((int)$book['available_copies'] > 0): ?>
                        <span class="badge badge-green"><?= h((string)$book['available_copies']) ?></span>
                      <?php else: ?>
                        <span class="badge badge-red">0</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Total"><?= h((string)$book['total_copies']) ?></td>
                    <td data-label="Actions">
                      <div class="actions-inline">
                        <a href="catalog-edit.php?id=<?= (int)$book['id'] ?>" class="btn-ghost">Edit</a>
                        <form method="POST" action="catalog-delete.php" class="delete-book-form" data-title="<?= h($book['title']) ?>">
                          <input type="hidden" name="id" value="<?= (int)$book['id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                          <button type="submit" class="btn-accent">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      'use strict';
      
      // Setup delete confirmations
      const deleteForms = document.querySelectorAll('.delete-book-form');
      deleteForms.forEach(form => {
        form.addEventListener('submit', async function(e) {
          if (typeof sweetAlertUtils !== 'undefined') {
            e.preventDefault();
            const title = form.getAttribute('data-title');
            
            const result = await sweetAlertUtils.confirmAction(
              'Delete Book?',
              `<p>Are you sure you want to delete "<strong>${title}</strong>"?</p><p>This cannot be undone.</p>`,
              'Delete',
              'Cancel'
            );
            
            if (result.isConfirmed) {
              form.submit();
            }
          } else {
            // Fallback
            const title = form.getAttribute('data-title');
            if (!confirm(`Delete "${title}"? This cannot be undone.`)) {
              e.preventDefault();
            }
          }
        });
      });
      
      // Handle flash messages
      if (typeof sweetAlertUtils !== 'undefined') {
        const successNotice = document.getElementById('catalog-success');
        const errorNotice = document.getElementById('catalog-error');
        
        if (successNotice) {
          const message = successNotice.getAttribute('data-message');
          if (message) {
            setTimeout(async function() {
              await sweetAlertUtils.showSuccess('Success', message, 3000);
            }, 300);
          }
        }
        
        if (errorNotice) {
          const message = errorNotice.getAttribute('data-message');
          if (message) {
            setTimeout(async function() {
              await sweetAlertUtils.showError('Error', message);
            }, 300);
          }
        }
      }
    });
  </script>
</body>

</html>
