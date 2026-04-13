<?php

/**
 * book-cover-public.php — Public Book Cover Image Serving Endpoint
 *
 * GET ?book_id=X  Streams the stored BLOB for the requested book.
 *                 If no cover is stored, returns the placeholder image.
 *
 * Authentication: NONE - public access allowed (for public OPAC)
 *
 * Feature: 008-book-cover-blob (public variant)
 */

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/config.php';
}

// ── Validate book_id parameter ────────────────────────────────────────────────
$raw = $_GET['book_id'] ?? '';
if (!ctype_digit((string)$raw) || (int)$raw <= 0) {
  http_response_code(404);
  header('Content-Type: text/plain');
  echo 'Not found';
  exit;
}
$book_id = (int)$raw;

// ── Fetch BLOB from database ──────────────────────────────────────────────────
require_once __DIR__ . '/includes/db.php';
$pdo  = get_db();
$stmt = $pdo->prepare(
  'SELECT image_data, mime_type, updated_at FROM book_covers WHERE book_id = ?'
);
$stmt->execute([$book_id]);
$row = $stmt->fetch();

if ($row === false) {
  // No cover stored — redirect to placeholder
  header('Location: ' . BASE_URL . 'assets/images/placeholder-book.png', true, 302);
  exit;
}

// ── Serve image with caching headers ─────────────────────────────────────────
// Cache-Control: public allows CDN/proxy caching for public content.
// ETag derived from updated_at ensures stale covers are re-fetched after replace.
$etag = '"' . md5((string)$row['updated_at']) . '"';

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
  http_response_code(304);
  exit;
}

// PDO may return LONGBLOB as a stream resource in some environments.
$image_data = $row['image_data'];
if (is_resource($image_data)) {
  $image_data = stream_get_contents($image_data);
}

if (!is_string($image_data) || $image_data === '') {
  header('Location: ' . BASE_URL . 'assets/images/placeholder-book.png', true, 302);
  exit;
}

$mime = (string)$row['mime_type'];
$allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mime, $allowed_mime, true)) {
  $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Content-Length: ' . strlen($image_data));
echo $image_data;
exit;
