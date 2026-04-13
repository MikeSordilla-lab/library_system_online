<?php
/**
 * api/search-suggestions.php - Search autocomplete suggestions
 * Returns JSON suggestions for title, author, and category matches
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));

if (strlen($q) < 2) {
  echo json_encode(['suggestions' => []]);
  exit;
}

$pdo = get_db();
$search_term = '%' . $q . '%';

try {
  // Get title matches
  $titles_sql = 'SELECT DISTINCT b.title FROM Books b 
                 WHERE b.title LIKE :q 
                 ORDER BY b.title ASC 
                 LIMIT 5';
  $titles_stmt = $pdo->prepare($titles_sql);
  $titles_stmt->execute([':q' => $search_term]);
  $titles = $titles_stmt->fetchAll(PDO::FETCH_COLUMN);

  // Get author matches
  $authors_sql = 'SELECT DISTINCT b.author FROM Books b 
                  WHERE b.author LIKE :q 
                  ORDER BY b.author ASC 
                  LIMIT 5';
  $authors_stmt = $pdo->prepare($authors_sql);
  $authors_stmt->execute([':q' => $search_term]);
  $authors = $authors_stmt->fetchAll(PDO::FETCH_COLUMN);

  // Get category matches
  $categories_sql = 'SELECT DISTINCT b.category FROM Books b 
                     WHERE b.category LIKE :q 
                     ORDER BY b.category ASC 
                     LIMIT 5';
  $categories_stmt = $pdo->prepare($categories_sql);
  $categories_stmt->execute([':q' => $search_term]);
  $categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

  $suggestions = [];

  // Add title suggestions
  foreach ($titles as $title) {
    $suggestions[] = [
      'type' => 'title',
      'text' => $title,
      'icon' => '📖'
    ];
  }

  // Add author suggestions
  foreach ($authors as $author) {
    $suggestions[] = [
      'type' => 'author',
      'text' => $author,
      'icon' => '✍️'
    ];
  }

  // Add category suggestions
  foreach ($categories as $category) {
    $suggestions[] = [
      'type' => 'category',
      'text' => $category,
      'icon' => '📚'
    ];
  }

  echo json_encode([
    'suggestions' => array_slice($suggestions, 0, 10)
  ]);

} catch (PDOException $e) {
  error_log('Search suggestions error: ' . $e->getMessage());
  echo json_encode(['suggestions' => []]);
}
