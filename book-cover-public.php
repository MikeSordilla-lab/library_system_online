<?php

/**
 * book-cover-public.php — Public Book Cover Image Serving Endpoint
 *
 * GET ?book_id=X  Streams the stored BLOB for the requested book.
 *                 If no cover is stored or book_id is invalid, returns the placeholder image.
 *
 * Authentication: NONE - public access allowed (for public OPAC)
 *
 * Feature: 008-book-cover-blob (public variant)
 *
 * Security: Returns 200 with placeholder for all cases to prevent catalog mapping.
 */

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/config.php';
}

define('ROOT_PATH', __DIR__);

$placeholder_path = ROOT_PATH . '/assets/images/placeholder-book.png';

function serve_image(string $image_data, string $mime = 'image/png'): void {
  header('Content-Type: ' . $mime);
  header('Cache-Control: public, max-age=86400');
  header('Content-Length: ' . strlen($image_data));
  echo $image_data;
  exit;
}

// ── Validate book_id parameter ────────────────────────────────────────────────
$raw = $_GET['book_id'] ?? '';
if (!ctype_digit((string)$raw) || (int)$raw <= 0) {
  // Invalid book_id — serve placeholder with 200 (prevent catalog mapping)
  if (file_exists($placeholder_path)) {
    serve_image(file_get_contents($placeholder_path));
  } else {
    http_response_code(404);
    exit;
  }
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
  // No cover stored — serve placeholder with 200
  if (file_exists($placeholder_path)) {
    serve_image(file_get_contents($placeholder_path));
  } else {
    http_response_code(404);
    exit;
  }
}

// ── Serve image with caching headers ─────────────────────────────────────────
$image_data = $row['image_data'];
if (is_resource($image_data)) {
  $image_data = stream_get_contents($image_data);
}

if (!is_string($image_data) || $image_data === '') {
  if (file_exists($placeholder_path)) {
    serve_image(file_get_contents($placeholder_path));
  } else {
    http_response_code(404);
    exit;
  }
}

$mime = (string)$row['mime_type'];
$allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mime, $allowed_mime, true)) {
  $mime = 'application/octet-stream';
}

// ETag for cache validation
$etag = '"' . md5((string)$row['updated_at']) . '"';
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
  http_response_code(304);
  exit;
}

serve_image($image_data, $mime);
