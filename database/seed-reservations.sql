-- ============================================================
-- Seed: Pending Reservations for Testing (TC009 / TC010)
-- ============================================================
-- Inserts pending reservations using borrower & librarian accounts
-- that already exist in the database (seed-users.sql or live data).
-- Safe to run multiple times — ON DUPLICATE KEY is handled by
-- natural de-duplication via IGNORE.
--
-- Prerequisites:
--   • At least one borrower user must exist in Users (role = 'borrower')
--   • At least one book must exist in Books
--
-- Usage:
--   Apply via phpMyAdmin or: mysql -u root librarydb < database/seed-reservations.sql
-- ============================================================

SET NAMES utf8mb4;

-- ── Resolve IDs dynamically so this script is portable ───────────────────────

SET @borrower1_id := (
  SELECT id FROM Users
  WHERE role = 'borrower' AND is_suspended = 0
  ORDER BY id ASC LIMIT 1
);

SET @borrower2_id := (
  SELECT id FROM Users
  WHERE role = 'borrower' AND is_suspended = 0 AND id != @borrower1_id
  ORDER BY id ASC LIMIT 1
);

-- Fall back to borrower1 if there is only one borrower
SET @borrower2_id := IFNULL(@borrower2_id, @borrower1_id);

SET @book1_id := (
  SELECT id FROM Books ORDER BY id ASC LIMIT 1
);

SET @book2_id := (
  SELECT id FROM Books WHERE id != @book1_id ORDER BY id ASC LIMIT 1
);

SET @book2_id := IFNULL(@book2_id, @book1_id);

SET @book3_id := (
  SELECT id FROM Books WHERE id NOT IN (@book1_id, @book2_id) ORDER BY id ASC LIMIT 1
);

SET @book3_id := IFNULL(@book3_id, @book1_id);

-- ── Insert pending reservations ───────────────────────────────────────────────
-- These should be visible in the librarian's "pending" queue for TC009 / TC010

INSERT IGNORE INTO Reservations
  (user_id, book_id, reserved_at, expires_at, status)
VALUES
  -- Pending: borrower1 → book1 (expires 7 days from now)
  (@borrower1_id, @book1_id,
   NOW(),
   DATE_ADD(NOW(), INTERVAL 7 DAY),
   'pending'),

  -- Pending: borrower2 → book2 (expires 7 days from now)
  (@borrower2_id, @book2_id,
   DATE_SUB(NOW(), INTERVAL 1 HOUR),
   DATE_ADD(NOW(), INTERVAL 7 DAY),
   'pending'),

  -- Pending: borrower1 → book3 (expires 5 days from now) — for rejection test
  (@borrower1_id, @book3_id,
   DATE_SUB(NOW(), INTERVAL 2 HOUR),
   DATE_ADD(NOW(), INTERVAL 5 DAY),
   'pending');

-- ── Confirmation ─────────────────────────────────────────────────────────────
SELECT
  r.id              AS reservation_id,
  u.full_name       AS borrower,
  b.title           AS book,
  r.status,
  r.expires_at
FROM Reservations r
JOIN Users u ON r.user_id = u.id
JOIN Books b ON r.book_id = b.id
WHERE r.status = 'pending'
ORDER BY r.reserved_at ASC;
