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

      <?php
      // Collect unique categories for the filter dropdown
      $categories = [];
      foreach ($books as $bk) {
        $cat = trim((string)($bk['category'] ?? ''));
        if ($cat !== '' && !in_array($cat, $categories, true)) {
          $categories[] = $cat;
        }
      }
      sort($categories);
      ?>

      <!-- ── Search & Filter bar ── -->
      <div class="cat-toolbar">
        <div class="cat-toolbar__search-wrap">
          <span class="cat-toolbar__search-icon" aria-hidden="true">&#128269;</span>
          <input
            class="cat-toolbar__search"
            id="cat-search"
            type="search"
            placeholder="Search title, author, ISBN…"
            autocomplete="off"
            spellcheck="false">
        </div>

        <select class="cat-toolbar__select" id="cat-filter-category">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
          <?php endforeach; ?>
        </select>

        <select class="cat-toolbar__select" id="cat-filter-avail">
          <option value="">All Availability</option>
          <option value="available">Available</option>
          <option value="unavailable">Unavailable</option>
        </select>

        <button class="cat-toolbar__reset" id="cat-reset" type="button" title="Clear filters">&#10005; Reset</button>
      </div>

      <!-- ── Result count ── -->
      <div class="cat-count" id="cat-count" aria-live="polite"></div>

      <div class="section-card">
        <?php if (empty($books)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#128218;</span>
            <p>No books in the catalog yet. <a href="catalog-add.php">Add the first one.</a></p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl" id="catalog-table">
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
              <tbody id="catalog-tbody">
                <?php foreach ($books as $book): ?>
                  <tr
                    class="cat-row"
                    data-title="<?= h(strtolower((string)$book['title'])) ?>"
                    data-author="<?= h(strtolower((string)($book['author'] ?? ''))) ?>"
                    data-isbn="<?= h(strtolower((string)($book['isbn'] ?? ''))) ?>"
                    data-category="<?= h((string)($book['category'] ?? '')) ?>"
                    data-available="<?= (int)$book['available_copies'] > 0 ? 'available' : 'unavailable' ?>">
                    <td data-label="Title">
                      <strong class="cat-title"><?= h($book['title']) ?></strong>
                      <?php if (!empty($book['description'])): ?>
                        <div class="cat-desc"><?= h(mb_strimwidth((string)$book['description'], 0, 80, '…')) ?></div>
                      <?php endif; ?>
                    </td>
                    <td data-label="Author"><?= h($book['author']) ?></td>
                    <td data-label="ISBN"><span class="cat-isbn"><?= h($book['isbn']) ?></span></td>
                    <td data-label="Category">
                      <?php if (!empty($book['category'])): ?>
                        <span class="cat-chip"><?= h($book['category']) ?></span>
                      <?php else: ?>
                        <span class="cat-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Available">
                      <?php if ((int)$book['available_copies'] > 0): ?>
                        <span class="badge badge-green"><?= h((string)$book['available_copies']) ?></span>
                      <?php else: ?>
                        <span class="badge badge-red">0</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Total"><?= h((string)$book['total_copies']) ?></td>
                    <td data-label="Actions">
                      <div class="cat-actions">
                        <a href="catalog-edit.php?id=<?= (int)$book['id'] ?>" class="cat-btn cat-btn--edit" title="Edit book">
                          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                          </svg>
                          Edit
                        </a>
                        <form method="POST" action="catalog-delete.php" class="delete-book-form" data-title="<?= h($book['title']) ?>">
                          <input type="hidden" name="id" value="<?= (int)$book['id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                          <button type="submit" class="cat-btn cat-btn--delete" title="Delete book">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <polyline points="3 6 5 6 21 6" />
                              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                              <path d="M10 11v6" />
                              <path d="M14 11v6" />
                              <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                            </svg>
                            Delete
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Empty search state -->
            <div class="cat-empty-search" id="cat-empty-search" style="display:none;">
              <span style="font-size:2rem;">&#128218;</span>
              <p>No books match your search.</p>
              <button class="btn-ghost" id="cat-reset2" type="button">Clear filters</button>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <style>
    /* ── Toolbar ── */
    .cat-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-3);
      align-items: center;
      margin-bottom: var(--space-3);
    }

    .cat-toolbar__search-wrap {
      position: relative;
      flex: 1 1 220px;
      min-width: 180px;
    }

    .cat-toolbar__search-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 15px;
      pointer-events: none;
      opacity: .5;
    }

    .cat-toolbar__search {
      width: 100%;
      padding: 9px 12px 9px 36px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      font-size: var(--text-sm);
      color: var(--ink);
      background: #fff;
      transition: border-color .15s, box-shadow .15s;
    }

    .cat-toolbar__search:focus {
      outline: none;
      border-color: var(--accent, #8b6f47);
      box-shadow: 0 0 0 3px rgba(139, 111, 71, .15);
    }

    .cat-toolbar__select {
      padding: 9px 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      font-size: var(--text-sm);
      color: var(--ink);
      background: #fff;
      cursor: pointer;
      min-width: 150px;
    }

    .cat-toolbar__select:focus {
      outline: none;
      border-color: var(--accent, #8b6f47);
      box-shadow: 0 0 0 3px rgba(139, 111, 71, .15);
    }

    .cat-toolbar__reset {
      padding: 9px 14px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      font-size: var(--text-sm);
      background: #fff;
      color: var(--muted);
      cursor: pointer;
      white-space: nowrap;
      transition: background .15s, color .15s;
    }

    .cat-toolbar__reset:hover {
      background: var(--surface);
      color: var(--ink);
    }

    /* ── Count label ── */
    .cat-count {
      font-size: var(--text-sm);
      color: var(--muted);
      margin-bottom: var(--space-3);
      min-height: 20px;
    }

    /* ── Table extras ── */
    .cat-desc {
      font-size: var(--text-xs);
      color: var(--muted);
      margin-top: 2px;
      font-weight: 400;
    }

    .cat-isbn {
      font-family: monospace;
      font-size: var(--text-xs);
      color: var(--muted);
    }

    .cat-chip {
      display: inline-block;
      padding: 2px 10px;
      background: var(--surface, #f5f0eb);
      border: 1px solid var(--border);
      border-radius: 999px;
      font-size: var(--text-xs);
      color: var(--ink);
      white-space: nowrap;
    }

    .cat-muted {
      color: var(--muted);
    }

    /* ── Empty search state ── */
    .cat-empty-search {
      padding: var(--space-10) var(--space-6);
      text-align: center;
      color: var(--muted);
    }

    .cat-empty-search p {
      margin: var(--space-2) 0 var(--space-4);
    }

    /* ── Highlight matched text ── */
    mark.cat-hl {
      background: #fef08a;
      color: inherit;
      border-radius: 2px;
      padding: 0 1px;
    }

    /* ── Action buttons ── */
    .cat-actions {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .cat-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid transparent;
      text-decoration: none;
      transition: background .15s, color .15s, border-color .15s, box-shadow .15s;
      white-space: nowrap;
      line-height: 1.4;
      letter-spacing: .01em;
    }

    .cat-btn--edit {
      background: #fff;
      color: #3a5a8c;
      border-color: #b8cceb;
    }

    .cat-btn--edit:hover {
      background: #eff4fc;
      border-color: #3a5a8c;
      box-shadow: 0 1px 4px rgba(58, 90, 140, .12);
    }

    .cat-btn--delete {
      background: #fff;
      color: #b91c1c;
      border-color: #fca5a5;
    }

    .cat-btn--delete:hover {
      background: #fef2f2;
      border-color: #b91c1c;
      box-shadow: 0 1px 4px rgba(185, 28, 28, .12);
    }

    /* ── Hidden row ── */
    .cat-row--hidden {
      display: none;
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      'use strict';

      // ── Elements ──
      const searchInput = document.getElementById('cat-search');
      const filterCat = document.getElementById('cat-filter-category');
      const filterAvail = document.getElementById('cat-filter-avail');
      const resetBtn = document.getElementById('cat-reset');
      const resetBtn2 = document.getElementById('cat-reset2');
      const rows = document.querySelectorAll('.cat-row');
      const countEl = document.getElementById('cat-count');
      const emptySearch = document.getElementById('cat-empty-search');

      let totalBooks = rows.length;

      // ── Highlight helper ──
      function highlight(text, query) {
        if (!query) return text;
        const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return text.replace(new RegExp('(' + escaped + ')', 'gi'), '<mark class="cat-hl">$1</mark>');
      }

      // ── Reset title cells to original text ──
      const originalTitles = {};
      const originalAuthors = {};
      rows.forEach(row => {
        const id = row.dataset.title;
        const titleEl = row.querySelector('.cat-title');
        const authorEl = row.querySelector('[data-label="Author"]');
        if (titleEl) originalTitles[id] = titleEl.textContent;
        if (authorEl) originalAuthors[id] = authorEl.textContent;
      });

      // ── Main filter function ──
      function applyFilters() {
        const q = searchInput.value.trim().toLowerCase();
        const cat = filterCat.value;
        const avail = filterAvail.value;

        let visible = 0;

        rows.forEach(row => {
          const matchSearch = !q ||
            row.dataset.title.includes(q) ||
            row.dataset.author.includes(q) ||
            row.dataset.isbn.includes(q);

          const matchCat = !cat || row.dataset.category === cat;
          const matchAvail = !avail || row.dataset.available === avail;

          const show = matchSearch && matchCat && matchAvail;
          row.classList.toggle('cat-row--hidden', !show);

          if (show) {
            visible++;
            // Apply highlight
            const titleEl = row.querySelector('.cat-title');
            const authorEl = row.querySelector('[data-label="Author"]');
            const origT = originalTitles[row.dataset.title] || '';
            const origA = originalAuthors[row.dataset.title] || '';
            if (titleEl) titleEl.innerHTML = highlight(origT, q);
            if (authorEl) authorEl.innerHTML = highlight(origA, q);
          }
        });

        // Update count label
        if (q || cat || avail) {
          countEl.textContent = visible + ' of ' + totalBooks + ' book' + (totalBooks !== 1 ? 's' : '') + ' shown';
        } else {
          countEl.textContent = totalBooks + ' book' + (totalBooks !== 1 ? 's' : '') + ' in catalog';
        }

        // Toggle empty state
        if (emptySearch) {
          emptySearch.style.display = visible === 0 ? 'block' : 'none';
        }
      }

      // ── Reset function ──
      function resetFilters() {
        searchInput.value = '';
        filterCat.value = '';
        filterAvail.value = '';
        applyFilters();
        searchInput.focus();
      }

      // ── Event listeners ──
      searchInput.addEventListener('input', applyFilters);
      filterCat.addEventListener('change', applyFilters);
      filterAvail.addEventListener('change', applyFilters);
      resetBtn.addEventListener('click', resetFilters);
      if (resetBtn2) resetBtn2.addEventListener('click', resetFilters);

      // Run once on load to set count
      applyFilters();

      // ── Delete confirmations ──
      document.querySelectorAll('.delete-book-form').forEach(form => {
        form.addEventListener('submit', async function(e) {
          const title = form.getAttribute('data-title');
          if (typeof sweetAlertUtils !== 'undefined') {
            e.preventDefault();
            const result = await sweetAlertUtils.confirmAction(
              'Delete Book?',
              `<p>Are you sure you want to delete "<strong>${title}</strong>"?</p><p>This cannot be undone.</p>`,
              'Delete', 'Cancel'
            );
            if (result.isConfirmed) form.submit();
          } else {
            if (!confirm(`Delete "${title}"? This cannot be undone.`)) e.preventDefault();
          }
        });
      });

      // ── Flash messages ──
      if (typeof sweetAlertUtils !== 'undefined') {
        const successNotice = document.getElementById('catalog-success');
        const errorNotice = document.getElementById('catalog-error');
        if (successNotice?.dataset.message) {
          setTimeout(() => sweetAlertUtils.showSuccess('Success', successNotice.dataset.message, 3000), 300);
        }
        if (errorNotice?.dataset.message) {
          setTimeout(() => sweetAlertUtils.showError('Error', errorNotice.dataset.message), 300);
        }
      }
    });
  </script>
</body>

</html>