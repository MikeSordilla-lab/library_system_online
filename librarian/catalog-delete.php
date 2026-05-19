<?php

/**
 * librarian/catalog-delete.php — POST-only Delete Handler (US4, FR-004, FR-011)
 *
 * GET:  Redirect to catalog.php (no UI to display).
 * POST: CSRF verify → validate id → SELECT exists → DELETE → log → flash → redirect.
 *
 * Accessible to: librarian only
 */

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/../config.php';
}
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// GET guard — this endpoint is POST-only (FR-004)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: catalog.php');
  exit;
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

// 1. CSRF validation (FR-011)
csrf_verify();

$pdo = get_db();

// 2. Validate id
$raw_id  = $_POST['id'] ?? '';
$book_id = (ctype_digit((string)$raw_id) && (int)$raw_id > 0)
  ? (int)$raw_id
  : 0;

if ($book_id === 0) {
  header('Location: catalog.php');
  exit;
}

// 3. Confirm record exists
$chk = $pdo->prepare('SELECT id, title FROM Books WHERE id = ?');
$chk->execute([$book_id]);
$book = $chk->fetch();

if ($book === false) {
  // Already gone — redirect silently
  header('Location: catalog.php');
  exit;
}

// 4. Delete (FR-006 — PDO prepared statement)
try {
  $stmt = $pdo->prepare('DELETE FROM Books WHERE id = ?');
  $stmt->execute([$book_id]);
} catch (PDOException $e) {
  // Detect FK integrity violation via SQLSTATE ("23000") OR MySQL error code (1451/1452)
  $sqlstate   = (string) $e->getCode();          // PDO SQLSTATE e.g. "23000"
  $mysql_code = (int) ($e->errorInfo[1] ?? 0);   // MySQL-native error number
  if ($sqlstate === '23000' || $mysql_code === 1451 || $mysql_code === 1452) {
    $_SESSION['flash_error'] =
      'Cannot delete "' . htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') . '" '
      . 'because it has existing loan or reservation records. '
      . 'Archive or reassign those records before deleting this book.';
    header('Location: catalog.php');
    exit;
  }
  throw $e;
}

// 5. Audit log (Constitution Principle V)
log_event(
  $pdo,
  'BOOK_DELETE',
  (int)$_SESSION['user_id'],
  'Books',
  $book_id,
  'SUCCESS',
  $_SESSION['role']
);

$_SESSION['flash_success'] = 'Book deleted successfully.';
header('Location: catalog.php');
exit;
