<?php

/**
 * src/utils/catalog.php — Book Catalog Helpers (deep module)
 *
 * Centralises validation, cover-image handling, and CRUD logic for the
 * librarian catalog pages.  Keeps page controllers thin.
 *
 * Usage:
 *   require_once __DIR__ . '/../../src/utils/catalog.php';
 */

if (defined('CATALOG_PHP_LOADED')) {
  return;
}
define('CATALOG_PHP_LOADED', true);

if (!function_exists('h')) {
  function h(?string $v): string
  {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
  }
}

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------

/**
 * Validate book text/metadata fields.
 *
 * @param array<string, string> $d  Raw POST values (title, author, description, isbn, category, total_copies, available_copies)
 * @return array<int, string>       List of error messages (empty = valid)
 */
function validate_book_fields(array $d): array
{
  $errors = [];

  $title           = trim($d['title'] ?? '');
  $author          = trim($d['author'] ?? '');
  $description     = trim($d['description'] ?? '');
  $isbn            = trim($d['isbn'] ?? '');
  $category        = trim($d['category'] ?? '');
  $total_copies    = trim($d['total_copies'] ?? '');
  $available_copies = trim($d['available_copies'] ?? '');

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

  if (!ctype_digit($total_copies) || (int) $total_copies < 0) {
    $errors[] = 'Total copies must be a non-negative whole number.';
  }

  if (!ctype_digit($available_copies) || (int) $available_copies < 0) {
    $errors[] = 'Available copies must be a non-negative whole number.';
  }

  if (empty($errors) && (int) $available_copies > (int) $total_copies) {
    $errors[] = 'Available copies cannot exceed total copies.';
  }

  return $errors;
}

/**
 * Validate an uploaded cover image file.
 *
 * @param array $file $_FILES['cover_image'] element
 * @return array{valid: bool, data: string|null, mime: string|null, error: string|null}
 */
function validate_cover_upload(array $file): array
{
  if ($file['error'] === UPLOAD_ERR_NO_FILE) {
    return ['valid' => false, 'data' => null, 'mime' => null, 'error' => null]; // no-op
  }
  if ($file['error'] !== UPLOAD_ERR_OK) {
    return ['valid' => false, 'data' => null, 'mime' => null, 'error' => 'File upload failed (error code ' . (int) $file['error'] . ').'];
  }
  if ($file['size'] > 2_097_152) {
    return ['valid' => false, 'data' => null, 'mime' => null, 'error' => 'Cover image must be 2 MB or smaller.'];
  }

  $finfo   = new finfo(FILEINFO_MIME_TYPE);
  $mime    = $finfo->file($file['tmp_name']);
  $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  if (!in_array($mime, $allowed, true)) {
    return ['valid' => false, 'data' => null, 'mime' => null, 'error' => 'Only JPEG, PNG, WebP, and GIF images are accepted.'];
  }

  $raw = file_get_contents($file['tmp_name']);
  if ($raw === false || @getimagesizefromstring($raw) === false) {
    return ['valid' => false, 'data' => null, 'mime' => null, 'error' => 'The uploaded file does not appear to be a valid image.'];
  }

  return ['valid' => true, 'data' => $raw, 'mime' => $mime, 'error' => null];
}

/**
 * Check title + author uniqueness.
 *
 * @param PDO    $pdo
 * @param string $title
 * @param string $author
 * @param int|null $exclude_id  When editing, exclude the current book ID.
 * @return bool  True if a duplicate exists.
 */
function book_title_author_exists(PDO $pdo, string $title, string $author, ?int $exclude_id = null): bool
{
  if ($exclude_id) {
    $stmt = $pdo->prepare('SELECT id FROM Books WHERE title = ? AND author = ? AND id != ?');
    $stmt->execute([$title, $author, $exclude_id]);
  } else {
    $stmt = $pdo->prepare('SELECT id FROM Books WHERE title = ? AND author = ?');
    $stmt->execute([$title, $author]);
  }
  return $stmt->fetchColumn() !== false;
}

// ---------------------------------------------------------------------------
// Cover-image persistence
// ---------------------------------------------------------------------------

/**
 * Upsert or delete a book cover in the book_covers table.
 *
 * @param PDO         $pdo
 * @param int         $book_id
 * @param string|null $image_data  Binary image data (null = no change unless remove_cover)
 * @param string|null $mime_type
 * @param bool        $remove_cover  True to delete the cover (ignored when new data provided)
 * @param int         $actor_id
 * @param string      $actor_role
 * @return string|null  Audit action performed, or null if no-op
 */
function handle_book_cover(
  PDO $pdo,
  int $book_id,
  ?string $image_data,
  ?string $mime_type,
  bool $remove_cover,
  int $actor_id,
  string $actor_role
): ?string {
  if ($image_data !== null) {
    $exists_chk = $pdo->prepare('SELECT 1 FROM book_covers WHERE book_id = ?');
    $exists_chk->execute([$book_id]);
    $cover_existed = (bool) $exists_chk->fetchColumn();

    $cstmt = $pdo->prepare(
      'INSERT INTO book_covers (book_id, image_data, mime_type)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE image_data = VALUES(image_data),
                                     mime_type  = VALUES(mime_type),
                                     updated_at = NOW()'
    );
    $cstmt->bindParam(1, $book_id, PDO::PARAM_INT);
    $cstmt->bindParam(2, $image_data, PDO::PARAM_LOB);
    $cstmt->bindParam(3, $mime_type, PDO::PARAM_STR);
    $cstmt->execute();

    log_event($pdo, $cover_existed ? 'COVER_REPLACE' : 'COVER_UPLOAD', $actor_id, 'book_covers', $book_id, 'SUCCESS', $actor_role);
    return $cover_existed ? 'COVER_REPLACE' : 'COVER_UPLOAD';
  }

  if ($remove_cover) {
    $pdo->prepare('DELETE FROM book_covers WHERE book_id = ?')->execute([$book_id]);
    log_event($pdo, 'COVER_DELETE', $actor_id, 'book_covers', $book_id, 'SUCCESS', $actor_role);
    return 'COVER_DELETE';
  }

  return null; // no-op
}

// ---------------------------------------------------------------------------
// High-level CRUD
// ---------------------------------------------------------------------------

/**
 * Add a new book to the catalog and log the event.
 *
 * @param PDO    $pdo
 * @param array  $data     Associative array of book fields
 * @param int    $actor_id
 * @param string $actor_role
 * @return array{success: bool, book_id: int|null, errors: array}
 */
function add_book(PDO $pdo, array $data, int $actor_id, string $actor_role): array
{
  $errors = validate_book_fields($data);
  if (!empty($errors)) {
    return ['success' => false, 'book_id' => null, 'errors' => $errors];
  }

  $title           = trim($data['title']);
  $author          = trim($data['author']);
  $description     = trim($data['description']);
  $isbn            = trim($data['isbn']);
  $category        = trim($data['category']);
  $total_copies    = (int) $data['total_copies'];
  $available_copies = (int) $data['available_copies'];

  if (book_title_author_exists($pdo, $title, $author)) {
    return ['success' => false, 'book_id' => null, 'errors' => ['A book with this title and author already exists.']];
  }

  $stmt = $pdo->prepare(
    'INSERT INTO Books (title, author, description, isbn, category, total_copies, available_copies)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $title, $author,
    $description !== '' ? $description : null,
    $isbn, $category,
    $total_copies, $available_copies,
  ]);
  $new_id = (int) $pdo->lastInsertId();

  log_event($pdo, 'BOOK_CREATE', $actor_id, 'Books', $new_id, 'SUCCESS', $actor_role);

  return ['success' => true, 'book_id' => $new_id, 'errors' => []];
}

/**
 * Update an existing book.
 *
 * @param PDO    $pdo
 * @param int    $book_id
 * @param array  $data    Associative array of book fields
 * @param int    $actor_id
 * @param string $actor_role
 * @return array{success: bool, errors: array}
 */
function update_book(PDO $pdo, int $book_id, array $data, int $actor_id, string $actor_role): array
{
  // Confirm record exists
  $chk = $pdo->prepare('SELECT id FROM Books WHERE id = ?');
  $chk->execute([$book_id]);
  if ($chk->fetchColumn() === false) {
    return ['success' => false, 'errors' => ['Book not found.']];
  }

  $errors = validate_book_fields($data);
  if (!empty($errors)) {
    return ['success' => false, 'errors' => $errors];
  }

  $title           = trim($data['title']);
  $author          = trim($data['author']);
  $description     = trim($data['description']);
  $isbn            = trim($data['isbn']);
  $category        = trim($data['category']);
  $total_copies    = (int) $data['total_copies'];
  $available_copies = (int) $data['available_copies'];

  if (book_title_author_exists($pdo, $title, $author, $book_id)) {
    return ['success' => false, 'errors' => ['A book with this title and author already exists.']];
  }

  $stmt = $pdo->prepare(
    'UPDATE Books
        SET title=?, author=?, description=?, isbn=?, category=?,
            total_copies=?, available_copies=?
      WHERE id=?'
  );
  $stmt->execute([
    $title, $author,
    $description !== '' ? $description : null,
    $isbn, $category,
    $total_copies, $available_copies,
    $book_id,
  ]);

  log_event($pdo, 'BOOK_UPDATE', $actor_id, 'Books', $book_id, 'SUCCESS', $actor_role);

  return ['success' => true, 'errors' => []];
}

/**
 * Delete a book from the catalog.
 *
 * @param PDO    $pdo
 * @param int    $book_id
 * @param int    $actor_id
 * @param string $actor_role
 * @return array{success: bool, errors: array}
 */
function delete_book(PDO $pdo, int $book_id, int $actor_id, string $actor_role): array
{
  $chk = $pdo->prepare('SELECT id, title FROM Books WHERE id = ?');
  $chk->execute([$book_id]);
  $book = $chk->fetch();

  if ($book === false) {
    return ['success' => false, 'errors' => ['Book not found.']];
  }

  $stmt = $pdo->prepare('DELETE FROM Books WHERE id = ?');
  $stmt->execute([$book_id]);

  log_event($pdo, 'BOOK_DELETE', $actor_id, 'Books', $book_id, 'SUCCESS', $actor_role);

  return ['success' => true, 'errors' => []];
}
