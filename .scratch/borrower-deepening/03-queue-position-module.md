# AFK: Extract QueuePositionModule

## What to build

Extract queue-position logic into `includes/QueuePositionModule::compute($pdo, $bookId, $reservedAt, $reservationId)`. Returns 1-based FIFO position within a book's pending+approved reservation queue, counting only non-expired entries. Replaces the triplicated O(n²) nested loops in `borrower/index.php`, `borrower/my_books.php`, and `borrower/reserve.php`.

## Acceptance criteria

- [ ] Function returns 1 when no earlier reservations exist
- [ ] Function returns N when N-1 earlier non-expired reservations exist
- [ ] Expired reservations (`expires_at < NOW()`) are excluded from count
- [ ] Same-timestamp tie-breaking uses reservation ID ascending (older ID = higher priority)
- [ ] Triplicated loop code in the three borrower pages is replaced by calls to the module