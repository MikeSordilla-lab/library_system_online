-- Add reservations foreign keys only when compatible parent columns exist.
-- Shared-host safe: avoids CREATE TABLE failure when parent PK types differ.

SET @schema_name := DATABASE();

SET @reservations_table := (
  SELECT TABLE_NAME
    FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = @schema_name
     AND LOWER(TABLE_NAME) = 'reservations'
   LIMIT 1
);

SET @books_table := (
  SELECT TABLE_NAME
    FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = @schema_name
     AND LOWER(TABLE_NAME) = 'books'
   LIMIT 1
);

SET @users_table := (
  SELECT TABLE_NAME
    FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = @schema_name
     AND LOWER(TABLE_NAME) = 'users'
   LIMIT 1
);

SET @reservations_quoted := IF(@reservations_table IS NULL, NULL, CONCAT('`', REPLACE(@reservations_table, '`', '``'), '`'));
SET @books_quoted := IF(@books_table IS NULL, NULL, CONCAT('`', REPLACE(@books_table, '`', '``'), '`'));
SET @users_quoted := IF(@users_table IS NULL, NULL, CONCAT('`', REPLACE(@users_table, '`', '``'), '`'));

-- Helper checks for matching integer types (including unsigned flag) for FK compatibility.
SET @book_types_match := IF(
  @reservations_table IS NULL OR @books_table IS NULL,
  0,
  (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS r
      JOIN INFORMATION_SCHEMA.COLUMNS b
        ON b.TABLE_SCHEMA = r.TABLE_SCHEMA
       AND b.TABLE_NAME = @books_table
       AND b.COLUMN_NAME = 'id'
     WHERE r.TABLE_SCHEMA = @schema_name
       AND r.TABLE_NAME = @reservations_table
       AND r.COLUMN_NAME = 'book_id'
       AND LOWER(r.COLUMN_TYPE) = LOWER(b.COLUMN_TYPE)
  )
);

SET @user_types_match := IF(
  @reservations_table IS NULL OR @users_table IS NULL,
  0,
  (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS r
      JOIN INFORMATION_SCHEMA.COLUMNS u
        ON u.TABLE_SCHEMA = r.TABLE_SCHEMA
       AND u.TABLE_NAME = @users_table
       AND u.COLUMN_NAME = 'id'
     WHERE r.TABLE_SCHEMA = @schema_name
       AND r.TABLE_NAME = @reservations_table
       AND r.COLUMN_NAME = 'user_id'
       AND LOWER(r.COLUMN_TYPE) = LOWER(u.COLUMN_TYPE)
  )
);

SET @has_fk_book := IF(
  @reservations_table IS NULL,
  0,
  (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = @schema_name
       AND TABLE_NAME = @reservations_table
       AND CONSTRAINT_NAME = 'fk_reservations_book'
       AND REFERENCED_TABLE_NAME IS NOT NULL
  )
);

SET @has_fk_user := IF(
  @reservations_table IS NULL,
  0,
  (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = @schema_name
       AND TABLE_NAME = @reservations_table
       AND CONSTRAINT_NAME = 'fk_reservations_user'
       AND REFERENCED_TABLE_NAME IS NOT NULL
  )
);

SET @add_fk_book := IF(
  @reservations_table IS NULL,
  'SELECT "skip fk_reservations_book: reservations table missing"',
  IF(
    @books_table IS NULL,
    'SELECT "skip fk_reservations_book: books table missing"',
    IF(
      @has_fk_book > 0,
      'SELECT "skip fk_reservations_book: already exists"',
      IF(
        @book_types_match > 0,
        CONCAT(
          'ALTER TABLE ', @reservations_quoted,
          ' ADD CONSTRAINT `fk_reservations_book` FOREIGN KEY (`book_id`) REFERENCES ', @books_quoted, ' (`id`) ON DELETE CASCADE'
        ),
        'SELECT "skip fk_reservations_book: column type mismatch"'
      )
    )
  )
);
PREPARE stmt FROM @add_fk_book; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_fk_user := IF(
  @reservations_table IS NULL,
  'SELECT "skip fk_reservations_user: reservations table missing"',
  IF(
    @users_table IS NULL,
    'SELECT "skip fk_reservations_user: users table missing"',
    IF(
      @has_fk_user > 0,
      'SELECT "skip fk_reservations_user: already exists"',
      IF(
        @user_types_match > 0,
        CONCAT(
          'ALTER TABLE ', @reservations_quoted,
          ' ADD CONSTRAINT `fk_reservations_user` FOREIGN KEY (`user_id`) REFERENCES ', @users_quoted, ' (`id`) ON DELETE CASCADE'
        ),
        'SELECT "skip fk_reservations_user: column type mismatch"'
      )
    )
  )
);
PREPARE stmt FROM @add_fk_user; EXECUTE stmt; DEALLOCATE PREPARE stmt;
