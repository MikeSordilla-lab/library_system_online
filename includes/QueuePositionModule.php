<?php

require_once __DIR__ . '/constants.php';

/**
 * QueuePositionModule — Compute 1-based FIFO position within a book's reservation queue.
 *
 * Counts non-expired pending+approved reservations placed before the given reservation.
 * Tie-breaking: older reservation ID = higher priority (position 1).
 */
class QueuePositionModule
{
  /**
   * Compute queue position for a book reservation.
   *
   * @param PDO   $pdo          Active database connection
   * @param int   $bookId      Book ID
   * @param int   $reservedAt  Unix timestamp of the reservation's reserved_at
   * @param int   $reservationId Reservation ID (used for tie-breaking)
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
   * Batch compute queue positions for multiple reservations of the same user.
   * More efficient than calling compute() N times when N is small vs fetching all reservations once.
   *
   * @param PDO   $pdo            Active database connection
   * @param array $reservations   Array of reservation rows (must have id, book_id, reserved_at keys)
   * @return array Map of reservation_id => queue_position
   */
  public static function computeBatch(PDO $pdo, array $reservations): array
  {
    if (empty($reservations)) {
      return [];
    }

    $bookIds = array_unique(array_column($reservations, 'book_id'));

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
      $bookQueues[$row['book_id']][] = $row;
    }

    $result = [];
    foreach ($reservations as $res) {
      $bookId = (int) $res['book_id'];
      $reservedAt = strtotime($res['reserved_at']);
      $resId = (int) $res['id'];
      $position = 1;
      if (isset($bookQueues[$bookId])) {
        foreach ($bookQueues[$bookId] as $other) {
          $otherTs = strtotime($other['reserved_at']);
          if ($otherTs < $reservedAt || ($otherTs === $reservedAt && (int) $other['id'] < $resId)) {
            $position++;
          }
        }
      }
      $result[$resId] = $position;
    }

    return $result;
  }
}