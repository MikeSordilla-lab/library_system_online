<?php

require_once __DIR__ . '/BookAvailabilityModule.php';

/**
 * CatalogDiscoveryModule — Shared catalog query and availability semantics.
 */
class CatalogDiscoveryModule
{
  /**
   * Build the public catalog discovery model.
   *
   * @param array<string, mixed> $input
   * @return array<string, mixed>
   */
  public static function publicCatalog(PDO $pdo, array $input = [], int $perPage = 12): array
  {
    $q = trim((string) ($input['q'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));
    $availability = trim((string) ($input['availability'] ?? 'all'));
    $sort = trim((string) ($input['sort'] ?? 'title_asc'));
    $page = max(1, (int) ($input['page'] ?? 1));
    $perPage = max(1, min(100, $perPage));

    $allowedSorts = [
      'title_asc' => 'b.title ASC',
      'title_desc' => 'b.title DESC',
      'author_asc' => 'b.author ASC',
      'newest' => 'b.created_at DESC',
    ];
    $orderBy = $allowedSorts[$sort] ?? $allowedSorts['title_asc'];
    if (!isset($allowedSorts[$sort])) {
      $sort = 'title_asc';
    }

    $availabilityJoin = BookAvailabilityModule::catalogApprovedHoldJoinSql('b', 'availability_holds');
    $approvedHoldsSql = BookAvailabilityModule::approvedHoldsSql('availability_holds');
    $reservableCopiesSql = BookAvailabilityModule::reservableCopiesSql('b', 'availability_holds');

    $whereParts = [];
    $params = [];

    if ($q !== '') {
      $whereParts[] = '(b.title LIKE :q_title OR b.author LIKE :q_author OR b.isbn LIKE :q_isbn OR b.category LIKE :q_category)';
      $like = '%' . self::escapeLikeWildcards($q) . '%';
      $params[':q_title'] = $like;
      $params[':q_author'] = $like;
      $params[':q_isbn'] = $like;
      $params[':q_category'] = $like;
    }

    if ($category !== '') {
      $whereParts[] = 'b.category = :category';
      $params[':category'] = $category;
    }

    if ($availability === 'available') {
      $whereParts[] = '(' . $reservableCopiesSql . ') > 0';
    } elseif ($availability === 'unavailable') {
      $whereParts[] = '(' . $reservableCopiesSql . ') = 0';
    } else {
      $availability = 'all';
    }

    $whereSql = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    $countSql = 'SELECT COUNT(DISTINCT b.id) FROM Books b ' . $availabilityJoin . ' ' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalBooksCount = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalBooksCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT ' . self::bookSelectSql($approvedHoldsSql, $reservableCopiesSql) . '
              FROM Books b
              LEFT JOIN book_covers bc ON bc.book_id = b.id
              ' . $availabilityJoin . '
              ' . $whereSql . '
             ORDER BY ' . $orderBy . '
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();

    $featuredBooks = self::bookList($pdo, 'WHERE ' . $reservableCopiesSql . ' > 0', 'b.created_at DESC', 6);
    $recentBooks = self::bookList($pdo, '', 'b.created_at DESC', 6);
    $popularBooks = self::popularBooks($pdo, 6);

    $categoriesStmt = $pdo->query("SELECT DISTINCT category FROM Books WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
    $allCategories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

    $totalBooks = (int) $pdo->query('SELECT COUNT(*) FROM Books')->fetchColumn();
    $availableBooks = self::availableBookCount($pdo);
    $unavailableBooks = max(0, $totalBooks - $availableBooks);

    $isSearching = $q !== '' || $category !== '' || $availability !== 'all' || $sort !== 'title_asc' || $page > 1;
    $activeFilterCount = 0;
    if ($q !== '') {
      $activeFilterCount++;
    }
    if ($category !== '') {
      $activeFilterCount++;
    }
    if ($availability !== 'all') {
      $activeFilterCount++;
    }

    return [
      'filters' => [
        'q' => $q,
        'category' => $category,
        'availability' => $availability,
        'sort' => $sort,
        'page' => $page,
        'per_page' => $perPage,
      ],
      'books' => $books,
      'featured_books' => $featuredBooks,
      'recent_books' => $recentBooks,
      'popular_books' => $popularBooks,
      'all_categories' => $allCategories,
      'category_count' => count($allCategories),
      'total_books' => $totalBooks,
      'available_books' => $availableBooks,
      'unavailable_books' => $unavailableBooks,
      'total_books_count' => $totalBooksCount,
      'total_pages' => $totalPages,
      'offset' => $offset,
      'is_searching' => $isSearching,
      'active_filter_count' => $activeFilterCount,
    ];
  }

  /**
   * Fetch books for librarian checkout picker using hold-aware walk-in availability.
   *
   * @return array<int, array<string, mixed>>
   */
  public static function checkoutPickerBooks(PDO $pdo): array
  {
    $availabilityJoin = BookAvailabilityModule::catalogApprovedHoldJoinSql('b', 'availability_holds');
    $reservableCopiesSql = BookAvailabilityModule::reservableCopiesSql('b', 'availability_holds');
    $stmt = $pdo->prepare(
      'SELECT b.id, b.title, b.available_copies, ' . $reservableCopiesSql . ' AS reservable_copies
         FROM Books b ' . $availabilityJoin . '
        WHERE ' . $reservableCopiesSql . ' > 0
        ORDER BY b.title ASC'
    );
    $stmt->execute();
    return $stmt->fetchAll();
  }

  private static function bookList(PDO $pdo, string $whereSql, string $orderBy, int $limit): array
  {
    $availabilityJoin = BookAvailabilityModule::catalogApprovedHoldJoinSql('b', 'availability_holds');
    $approvedHoldsSql = BookAvailabilityModule::approvedHoldsSql('availability_holds');
    $reservableCopiesSql = BookAvailabilityModule::reservableCopiesSql('b', 'availability_holds');
    $sql = 'SELECT ' . self::bookSelectSql($approvedHoldsSql, $reservableCopiesSql) . '
              FROM Books b
              LEFT JOIN book_covers bc ON bc.book_id = b.id
              ' . $availabilityJoin . '
              ' . $whereSql . '
             ORDER BY ' . $orderBy . '
             LIMIT ' . (int) $limit;
    return $pdo->query($sql)->fetchAll();
  }

  private static function popularBooks(PDO $pdo, int $limit): array
  {
    $availabilityJoin = BookAvailabilityModule::catalogApprovedHoldJoinSql('b', 'availability_holds');
    $approvedHoldsSql = BookAvailabilityModule::approvedHoldsSql('availability_holds');
    $reservableCopiesSql = BookAvailabilityModule::reservableCopiesSql('b', 'availability_holds');
    $sql = 'SELECT ' . self::bookSelectSql($approvedHoldsSql, $reservableCopiesSql) . ',
                   COUNT(c.id) AS checkout_count
              FROM Books b
              LEFT JOIN book_covers bc ON bc.book_id = b.id
              ' . $availabilityJoin . '
              LEFT JOIN Circulation c ON c.book_id = b.id
             GROUP BY b.id
             ORDER BY checkout_count DESC
             LIMIT ' . (int) $limit;
    return $pdo->query($sql)->fetchAll();
  }

  private static function availableBookCount(PDO $pdo): int
  {
    $availabilityJoin = BookAvailabilityModule::catalogApprovedHoldJoinSql('b', 'availability_holds');
    $reservableCopiesSql = BookAvailabilityModule::reservableCopiesSql('b', 'availability_holds');
    $stmt = $pdo->query('SELECT COUNT(*) FROM Books b ' . $availabilityJoin . ' WHERE ' . $reservableCopiesSql . ' > 0');
    return (int) $stmt->fetchColumn();
  }

  private static function bookSelectSql(string $approvedHoldsSql, string $reservableCopiesSql): string
  {
    return 'b.id, b.title, b.author, b.isbn, b.category, b.available_copies, b.total_copies,
            ' . $approvedHoldsSql . ' AS approved_holds,
            ' . $reservableCopiesSql . ' AS reservable_copies,
            CASE WHEN b.total_copies >= 1 AND ' . $reservableCopiesSql . ' > 0 THEN 1 ELSE 0 END AS has_reservable_copy,
            (bc.book_id IS NOT NULL) AS has_cover';
  }

  private static function escapeLikeWildcards(string $value): string
  {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
  }
}
