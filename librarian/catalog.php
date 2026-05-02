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
  <style>
    .pagination-controls {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 16px;
    }
    .pagination-wrapper {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 16px;
      margin-top: 24px;
      padding-top: 16px;
      border-top: 1px solid var(--border, #e2e8f0);
      min-height: 40px;
    }
    @media (max-width: 600px) {
      .pagination-wrapper {
        flex-direction: column;
        gap: 16px;
      }
    }
    .pagination-btn {
      padding: 8px 16px;
      border: 1px solid var(--border, #e2e8f0);
      background: var(--bg-surface, #fff);
      color: var(--text-primary, #1e293b);
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.2s;
    }
    .pagination-btn:hover:not(:disabled) {
      background: var(--bg-hover, #f1f5f9);
    }
    .pagination-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .pagination-info {
      font-size: 0.9rem;
      color: var(--text-secondary, #64748b);
    }
    .cat-row--hidden {
      display: none !important;
    }
  </style>
</head>

<body class="librarian-themed">
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
          <div class="tbl-wrapper" id="tbl-wrapper">
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
                      <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <img src="<?= h(BASE_URL . 'public/book-cover.php?book_id=' . (int)$book['id']) ?>"
                             alt="Cover"
                             onerror="this.onerror=null;this.src='<?= h(BASE_URL . 'assets/images/placeholder-book.png') ?>';"
                             style="width: 40px; height: 60px; object-fit: cover; border-radius: 4px; flex-shrink: 0; border: 1px solid var(--border, #e2e8f0);">
                        <div>
                          <strong class="cat-title"><?= h($book['title']) ?></strong>
                          <?php if (!empty($book['description'])): ?>
                            <div class="cat-desc"><?= h(mb_strimwidth((string)$book['description'], 0, 80, '…')) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
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

            <div class="pagination-wrapper" id="pagination-wrapper" style="display: <?php echo empty($books) ? 'none' : 'flex'; ?>">
              <select class="cat-toolbar__select" id="cat-items-per-page" style="width: auto; padding: 6px 32px 6px 12px; margin: 0;" title="Items per page" aria-label="Items per page">
                <option value="5">5</option>
                <option value="10">10</option>
                <option value="15" selected>15</option>
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="1000000">All</option>
              </select>
              <div id="pagination-container"></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>


  <script>
    document.addEventListener('DOMContentLoaded', function() {
      'use strict';

      // ── Elements ──
      const searchInput = document.getElementById('cat-search');
      const filterCat = document.getElementById('cat-filter-category');
      const filterAvail = document.getElementById('cat-filter-avail');
      const itemsPerPageSelect = document.getElementById('cat-items-per-page');
      const resetBtn = document.getElementById('cat-reset');
      const resetBtn2 = document.getElementById('cat-reset2');
      const rows = document.querySelectorAll('.cat-row');
      const countEl = document.getElementById('cat-count');
      const emptySearch = document.getElementById('cat-empty-search');
      const paginationContainer = document.getElementById('pagination-container');
      const paginationWrapper = document.getElementById('pagination-wrapper');

      let totalBooks = rows.length;

      // ── Pagination State ──
      let itemsPerPage = itemsPerPageSelect ? parseInt(itemsPerPageSelect.value, 10) : 15;
      let currentPage = 1;
      let matchingRows = [];

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

      // ── Pagination render function ──
      function renderPagination() {
        if (!paginationContainer) return;
        paginationContainer.innerHTML = '';

        const totalPages = Math.ceil(matchingRows.length / itemsPerPage);
        if (totalPages <= 1) return;

        const controls = document.createElement('div');
        controls.className = 'pagination-controls';

        const prevBtn = document.createElement('button');
        prevBtn.className = 'pagination-btn';
        prevBtn.textContent = 'Previous';
        prevBtn.disabled = currentPage === 1;
        prevBtn.addEventListener('click', () => {
          if (currentPage > 1) {
            currentPage--;
            showCurrentPage();
            renderPagination();
          }
        });

        const info = document.createElement('span');
        info.className = 'pagination-info';
        info.textContent = `Page ${currentPage} of ${totalPages}`;

        const nextBtn = document.createElement('button');
        nextBtn.className = 'pagination-btn';
        nextBtn.textContent = 'Next';
        nextBtn.disabled = currentPage === totalPages;
        nextBtn.addEventListener('click', () => {
          if (currentPage < totalPages) {
            currentPage++;
            showCurrentPage();
            renderPagination();
          }
        });

        controls.appendChild(prevBtn);
        controls.appendChild(info);
        controls.appendChild(nextBtn);
        paginationContainer.appendChild(controls);
      }

      // ── Show current page function ──
      function showCurrentPage() {
        // Hide all rows first
        rows.forEach(row => {
          row.style.display = 'none';
          row.classList.add('cat-row--hidden');
        });

        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;

        for (let i = 0; i < matchingRows.length; i++) {
          if (i >= start && i < end) {
            matchingRows[i].style.display = '';
            matchingRows[i].classList.remove('cat-row--hidden');
          }
        }
      }

      // ── Main filter function ──
      function applyFilters(resetPage = true) {
        if (resetPage) currentPage = 1;

        const q = searchInput.value.trim().toLowerCase();
        const cat = filterCat.value;
        const avail = filterAvail.value;

        matchingRows = [];

        rows.forEach(row => {
          const matchSearch = !q ||
            row.dataset.title.includes(q) ||
            row.dataset.author.includes(q) ||
            row.dataset.isbn.includes(q);

          const matchCat = !cat || row.dataset.category === cat;
          const matchAvail = !avail || row.dataset.available === avail;

          const show = matchSearch && matchCat && matchAvail;

          if (show) {
            matchingRows.push(row);
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
          countEl.textContent = matchingRows.length + ' of ' + totalBooks + ' book' + (totalBooks !== 1 ? 's' : '') + ' found';
        } else {
          countEl.textContent = totalBooks + ' book' + (totalBooks !== 1 ? 's' : '') + ' in catalog';
        }

        // Toggle empty state
        if (emptySearch) {
          emptySearch.style.display = matchingRows.length === 0 ? 'block' : 'none';
        }

        if (paginationWrapper) {
          if (matchingRows.length === 0) {
            paginationWrapper.style.display = 'none';
          } else {
            paginationWrapper.style.display = 'flex';
          }
        }

        showCurrentPage();
        renderPagination();
      }

      // ── Reset function ──
      function resetFilters() {
        searchInput.value = '';
        filterCat.value = '';
        filterAvail.value = '';
        if (itemsPerPageSelect) {
          itemsPerPageSelect.value = '15';
        }
        itemsPerPage = 15;
        applyFilters(true);
        searchInput.focus();
      }

      // ── Event listeners ──
      searchInput.addEventListener('input', () => applyFilters(true));
      filterCat.addEventListener('change', () => applyFilters(true));
      filterAvail.addEventListener('change', () => applyFilters(true));
      if (itemsPerPageSelect) {
        itemsPerPageSelect.addEventListener('change', (e) => {
          itemsPerPage = parseInt(e.target.value, 10);
          applyFilters(true);
        });
      }
      resetBtn.addEventListener('click', resetFilters);
      if (resetBtn2) resetBtn2.addEventListener('click', resetFilters);

      // Run once on load to set count
      applyFilters(true);

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
