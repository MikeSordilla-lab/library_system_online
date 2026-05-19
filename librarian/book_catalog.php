<?php

/**
 * librarian/book_catalog.php — Permanent redirect alias (TC007 fix)
 *
 * Some navigation links or bookmarks may reference this legacy path.
 * Redirect permanently to the canonical catalog URL.
 */

if (!defined('BASE_URL')) {
  // Bootstrap just enough to know BASE_URL
  require_once __DIR__ . '/../config.php';
}

header('HTTP/1.1 301 Moved Permanently');
header('Location: ' . BASE_URL . 'librarian/catalog.php');
exit;
