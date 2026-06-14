<?php

require_once __DIR__ . '/constants.php';

/**
 * QueuePositionModule — Authoritative FIFO reservation queue policy.
 *
 * Queue positions are computed across non-expired pending + approved reservations
 * for the same book, ordered by reserved_at ASC and id ASC.
 */
class QueuePositionModule
{
  /**
   * Compute queue position for a book reservation.
   *
   * @param PDO $pdo Active database connection
   * @param int $bookId Book ID
   * @param int $reservedAt Unix timestamp of the reservation's reserved_at
   * @param int $reservationId Reservation ID used for tie-breaking
   * @return int 1-based position; 1 means first in queue
   */
  public static function compute(PDO $pdo, int $bookId, int $reservedAt, int $reservationId): int
  {
    $stmt = $pdo->prepare(
      'SELECT COUNT(*) + 1 AS position
         FROM Reservations
        WHERE book_id = ?
          AND status IN (?, ?)
          AND expires_at >= NOW()
          AND (reserved_at < FROM_UNIXTIME(?) OR (reserved_at = FROM_UNIXTIME(?) AND id < ?))'
    );
    $stmt->execute([$bookId, RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED, $reservedAt, $reservedAt, $reservationId]);
    $row = $stmt->fetch();
    return (int) ($row['position'] ?? 1);
  }

  /**
   * Batch compute queue positions for reservations.
   *
   * @param PDO $pdo Active database connection
   * @param array<int, array<string, mixed>> $reservations Rows with id, book_id, reserved_at
   * @return array<int, int> Map of reservation_id => queue_position
   */
  public static function computeBatch(PDO $pdo, array $reservations): array
  {
    if (empty($reservations)) {
      return [];
    }

    $bookIds = [];
    foreach ($reservations as $reservation) {
      $bookId = (int) ($reservation['book_id'] ?? 0);
      if ($bookId > 0 && !in_array($bookId, $bookIds, true)) {
        $bookIds[] = $bookId;
      }
    }

    if (empty($bookIds)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));

    $sql = "SELECT id, book_id, reserved_at
              FROM Reservations
             WHERE book_id IN ($placeholders)
               AND status IN (?, ?)
               AND expires_at >= NOW()
          ORDER BY book_id, reserved_at ASC, id ASC";
    $params = array_merge($bookIds, [RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allRows = $stmt->fetchAll();

    $bookQueues = [];
    foreach ($allRows as $row) {
      $bookQueues[(int) $row['book_id']][] = $row;
    }

    $result = [];
    foreach ($reservations as $res) {
      $bookId = (int) ($res['book_id'] ?? 0);
      $reservedAt = strtotime((string) ($res['reserved_at'] ?? '')) ?: 0;
      $resId = (int) ($res['id'] ?? 0);
      if ($bookId < 1 || $resId < 1) {
        continue;
      }

      $position = 1;
      if (isset($bookQueues[$bookId])) {
        foreach ($bookQueues[$bookId] as $other) {
          $otherTs = strtotime((string) ($other['reserved_at'] ?? '')) ?: 0;
          if ($otherTs < $reservedAt || ($otherTs === $reservedAt && (int) $other['id'] < $resId)) {
            $position++;
          }
        }
      }
      $result[$resId] = $position;
    }

    return $result;
  }

  /**
   * Fetch pending reservation rows for the librarian queue with canonical positions.
   *
   * @return array<int, array<string, mixed>>
   */
  public static function getPendingQueue(PDO $pdo): array
  {
    $stmt = $pdo->prepare(
      'SELECT r.id, r.user_id, r.book_id, r.status, r.reserved_at, r.expires_at,
              u.full_name AS borrower_name, u.email AS borrower_email,
              b.title AS book_title, b.author AS book_author
         FROM Reservations r
         JOIN Users u ON r.user_id = u.id
         JOIN Books b ON r.book_id = b.id
        WHERE r.status = ?
          AND r.expires_at >= NOW()
        ORDER BY r.reserved_at ASC, r.id ASC'
    );
    $stmt->execute([RESERVATION_STATUS_PENDING]);
    $rows = $stmt->fetchAll();

    $positions = self::computeBatch($pdo, $rows);
    foreach ($rows as &$row) {
      $reservationId = (int) ($row['id'] ?? 0);
      $row['queue_position'] = $positions[$reservationId] ?? 1;
    }
    unset($row);

    return $rows;
  }
}
