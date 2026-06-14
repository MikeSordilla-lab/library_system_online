<?php

require_once __DIR__ . '/constants.php';

/**
 * BookAvailabilityModule — Central book copy and approved-hold capacity rules.
 *
 * One place answers whether a book can be reserved or checked out while accounting
 * for approved, non-expired holds that already consume available copies.
 */
class BookAvailabilityModule
{
  /**
   * Return a copy/hold snapshot for a book.
   *
   * @return array{
   *   exists: bool,
   *   book: array|null,
   *   reason_code: string|null,
   *   total_copies: int,
   *   available_copies: int,
   *   approved_holds: int,
   *   reservable_copies: int,
   *   can_reserve: bool,
   *   can_checkout: bool
   * }
   */
  public static function getAvailability(PDO $pdo, int $bookId, bool $lockBook = false, bool $lockApprovedHolds = false): array
  {
    if ($bookId < 1) {
      return self::missingBookSnapshot();
    }

    $lockSql = $lockBook ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
      'SELECT id, title, total_copies, available_copies
         FROM Books
        WHERE id = ?' . $lockSql
    );
    $stmt->execute([$bookId]);
    $book = $stmt->fetch();

    if (!$book) {
      return self::missingBookSnapshot();
    }

    $totalCopies = (int) ($book['total_copies'] ?? 0);
    $availableCopies = (int) ($book['available_copies'] ?? 0);
    $approvedHolds = self::approvedHoldCount($pdo, $bookId, $lockApprovedHolds);
    $reservableCopies = max(0, $availableCopies - $approvedHolds);
    $canCheckout = $availableCopies > 0;
    $canReserve = $totalCopies >= 1 && $reservableCopies > 0;

    $reason = null;
    if ($totalCopies < 1) {
      $reason = 'book_not_borrowable';
    } elseif (!$canReserve) {
      $reason = 'no_available_copies';
    }

    return [
      'exists' => true,
      'book' => $book,
      'reason_code' => $reason,
      'total_copies' => $totalCopies,
      'available_copies' => $availableCopies,
      'approved_holds' => $approvedHolds,
      'reservable_copies' => $reservableCopies,
      'can_reserve' => $canReserve,
      'can_checkout' => $canCheckout,
    ];
  }

  /**
   * Return whether the book has reservable capacity.
   *
   * @return array{allowed: bool, reason_code: string|null, availability: array}
   */
  public static function canReserve(PDO $pdo, int $bookId, bool $lockBook = false, bool $lockApprovedHolds = false): array
  {
    $availability = self::getAvailability($pdo, $bookId, $lockBook, $lockApprovedHolds);

    if (!$availability['exists']) {
      return [
        'allowed' => false,
        'reason_code' => 'book_not_found',
        'availability' => $availability,
      ];
    }

    if (!$availability['can_reserve']) {
      return [
        'allowed' => false,
        'reason_code' => (string) ($availability['reason_code'] ?? 'no_available_copies'),
        'availability' => $availability,
      ];
    }

    return [
      'allowed' => true,
      'reason_code' => null,
      'availability' => $availability,
    ];
  }

  /**
   * Return whether checkout can use a physical copy under approved-hold rules.
   *
   * @return array{allowed: bool, reason_code: string|null, reservation_id?: int, availability: array}
   */
  public static function checkoutPolicy(PDO $pdo, int $userId, int $bookId, ?array $availability = null, bool $lockApprovedHold = true): array
  {
    $availability = $availability ?? self::getAvailability($pdo, $bookId, true, false);

    if (!$availability['exists'] || (int) ($availability['available_copies'] ?? 0) < 1) {
      return [
        'allowed' => false,
        'reason_code' => 'no_available_copies',
        'availability' => $availability,
      ];
    }

    $approved = self::firstApprovedHold($pdo, $bookId, $lockApprovedHold);
    if ($approved && (int) $approved['user_id'] !== $userId) {
      return [
        'allowed' => false,
        'reason_code' => 'reserved_for_another_borrower',
        'reservation_id' => (int) $approved['id'],
        'availability' => $availability,
      ];
    }

    return [
      'allowed' => true,
      'reason_code' => null,
      'reservation_id' => $approved ? (int) $approved['id'] : null,
      'availability' => $availability,
    ];
  }

  /**
   * Find the first approved hold for a book in pickup priority order.
   */
  public static function firstApprovedHold(PDO $pdo, int $bookId, bool $forUpdate = false): ?array
  {
    $lockSql = $forUpdate ? ' FOR UPDATE' : '';
    $orderSql = self::approvedHoldOrderSql($pdo);
    $stmt = $pdo->prepare(
      "SELECT id, user_id, book_id, status
         FROM Reservations
        WHERE book_id = ?
          AND status = ?
          AND expires_at >= NOW()
        ORDER BY {$orderSql}
        LIMIT 1" . $lockSql
    );
    $stmt->execute([$bookId, RESERVATION_STATUS_APPROVED]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  /**
   * Find the approved hold a borrower should fulfill for direct checkout.
   */
  public static function approvedHoldForBorrower(PDO $pdo, int $userId, int $bookId, bool $forUpdate = false): ?array
  {
    $lockSql = $forUpdate ? ' FOR UPDATE' : '';
    $orderSql = self::approvedHoldOrderSql($pdo);
    $stmt = $pdo->prepare(
      "SELECT id, status
         FROM Reservations
        WHERE user_id = ?
          AND book_id = ?
          AND status = ?
          AND expires_at >= NOW()
        ORDER BY {$orderSql}
        LIMIT 1" . $lockSql
    );
    $stmt->execute([$userId, $bookId, RESERVATION_STATUS_APPROVED]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  /**
   * Count approved, non-expired holds that consume available copies.
   */
  public static function approvedHoldCount(PDO $pdo, int $bookId, bool $forUpdate = false): int
  {
    if ($forUpdate) {
      $stmt = $pdo->prepare(
        'SELECT id
           FROM Reservations
          WHERE book_id = ?
            AND status = ?
            AND expires_at >= NOW()
          FOR UPDATE'
      );
      $stmt->execute([$bookId, RESERVATION_STATUS_APPROVED]);
      return count($stmt->fetchAll());
    }

    $stmt = $pdo->prepare(
      'SELECT COUNT(*)
         FROM Reservations
        WHERE book_id = ?
          AND status = ?
          AND expires_at >= NOW()'
    );
    $stmt->execute([$bookId, RESERVATION_STATUS_APPROVED]);
    return (int) $stmt->fetchColumn();
  }

  /**
   * SQL adapter helper for catalog queries that need policy-aware hold counts.
   */
  public static function catalogApprovedHoldJoinSql(string $bookAlias = 'b', string $holdAlias = 'availability_holds'): string
  {
    $bookAlias = self::safeSqlIdentifier($bookAlias, 'b');
    $holdAlias = self::safeSqlIdentifier($holdAlias, 'availability_holds');

    return " LEFT JOIN (
              SELECT book_id, COUNT(*) AS approved_holds
                FROM Reservations
               WHERE status = '" . RESERVATION_STATUS_APPROVED . "'
                 AND expires_at >= NOW()
               GROUP BY book_id
            ) {$holdAlias} ON {$holdAlias}.book_id = {$bookAlias}.id";
  }

  public static function approvedHoldsSql(string $holdAlias = 'availability_holds'): string
  {
    $holdAlias = self::safeSqlIdentifier($holdAlias, 'availability_holds');
    return "COALESCE({$holdAlias}.approved_holds, 0)";
  }

  public static function reservableCopiesSql(string $bookAlias = 'b', string $holdAlias = 'availability_holds'): string
  {
    $bookAlias = self::safeSqlIdentifier($bookAlias, 'b');
    return 'GREATEST(' . $bookAlias . '.available_copies - ' . self::approvedHoldsSql($holdAlias) . ', 0)';
  }

  /**
   * Add availability fields to catalog rows without changing the page query interface.
   *
   * @param array<int, array<string, mixed>> $books
   * @return array<int, array<string, mixed>>
   */
  public static function appendAvailability(PDO $pdo, array $books): array
  {
    if (empty($books)) {
      return [];
    }

    $bookIds = [];
    foreach ($books as $book) {
      $bookId = (int) ($book['id'] ?? 0);
      if ($bookId > 0 && !in_array($bookId, $bookIds, true)) {
        $bookIds[] = $bookId;
      }
    }

    if (empty($bookIds)) {
      return $books;
    }

    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
    $stmt = $pdo->prepare(
      "SELECT book_id, COUNT(*) AS approved_holds
         FROM Reservations
        WHERE book_id IN ($placeholders)
          AND status = ?
          AND expires_at >= NOW()
        GROUP BY book_id"
    );
    $stmt->execute(array_merge($bookIds, [RESERVATION_STATUS_APPROVED]));

    $approvedByBook = [];
    foreach ($stmt->fetchAll() as $row) {
      $approvedByBook[(int) $row['book_id']] = (int) $row['approved_holds'];
    }

    foreach ($books as &$book) {
      $bookId = (int) ($book['id'] ?? 0);
      $approvedHolds = $approvedByBook[$bookId] ?? 0;
      $availableCopies = (int) ($book['available_copies'] ?? 0);
      $totalCopies = (int) ($book['total_copies'] ?? 0);
      $reservableCopies = max(0, $availableCopies - $approvedHolds);

      $book['approved_holds'] = $approvedHolds;
      $book['reservable_copies'] = $reservableCopies;
      $book['has_reservable_copy'] = $totalCopies >= 1 && $reservableCopies > 0 ? 1 : 0;
    }
    unset($book);

    return $books;
  }

  private static function safeSqlIdentifier(string $identifier, string $fallback): string
  {
    return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) === 1 ? $identifier : $fallback;
  }

  private static function missingBookSnapshot(): array
  {
    return [
      'exists' => false,
      'book' => null,
      'reason_code' => 'book_not_found',
      'total_copies' => 0,
      'available_copies' => 0,
      'approved_holds' => 0,
      'reservable_copies' => 0,
      'can_reserve' => false,
      'can_checkout' => false,
    ];
  }

  private static function approvedHoldOrderSql(PDO $pdo): string
  {
    if (function_exists('reservation_column_exists') && reservation_column_exists($pdo, 'approved_at')) {
      return 'approved_at ASC, reserved_at ASC, id ASC';
    }

    return 'reserved_at ASC, id ASC';
  }
}
