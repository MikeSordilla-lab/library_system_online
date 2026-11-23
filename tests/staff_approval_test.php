<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/staff_approval.php';

function assert_true(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

$borrower = staff_registration_state('borrower');
assert_true($borrower['role'] === 'borrower', 'Borrower registration should keep borrower role.');
assert_true((int) $borrower['is_verified'] === 0, 'Borrower registration should still require email verification.');
assert_true((int) $borrower['is_suspended'] === 0, 'Borrower registration should not be suspended.');
assert_true($borrower['staff_approval_status'] === 'none', 'Borrower registration should not enter staff approval.');

$librarian = staff_registration_state('librarian');
assert_true($librarian['role'] === 'librarian', 'Librarian registration should request librarian role.');
assert_true((int) $librarian['is_verified'] === 0, 'Librarian request should not be verified before approval.');
assert_true((int) $librarian['is_suspended'] === 1, 'Librarian request should be blocked before approval.');
assert_true($librarian['staff_approval_status'] === 'pending', 'Librarian request should be pending.');

assert_true(staff_is_pending_librarian_request([
  'role' => 'librarian',
  'staff_approval_status' => 'pending',
]) === true, 'Pending librarian rows should be detected.');

assert_true(staff_is_pending_librarian_request([
  'role' => 'librarian',
  'staff_approval_status' => 'approved',
]) === false, 'Approved librarian rows should not be pending.');

assert_true(staff_is_rejected_librarian_request([
  'role' => 'librarian',
  'staff_approval_status' => 'rejected',
]) === true, 'Rejected librarian rows should be detected.');

assert_true(staff_is_rejected_librarian_request([
  'role' => 'borrower',
  'staff_approval_status' => 'rejected',
]) === false, 'Rejected borrower rows should not be treated as librarian requests.');

assert_true(staff_login_block_message([
  'role' => 'librarian',
  'staff_approval_status' => 'pending',
]) === 'Your librarian registration is waiting for admin approval.', 'Pending librarian login message should be specific.');

assert_true(staff_login_block_message([
  'role' => 'librarian',
  'is_verified' => 0,
  'staff_approval_status' => 'rejected',
]) === 'Your librarian registration was not approved. Please contact the library.', 'Rejected librarian login message should be specific.');

assert_true(staff_login_block_context([
  'role' => 'librarian',
  'is_verified' => 0,
  'staff_approval_status' => 'none',
]) === 'admin_approval', 'Unverified librarian accounts should use admin approval context.');

assert_true(staff_login_block_message([
  'role' => 'librarian',
  'is_verified' => 0,
  'staff_approval_status' => 'none',
]) === 'Your librarian account needs admin verification before you can sign in.', 'Unverified librarian message should mention admin verification.');

assert_true(staff_approval_status_column_is_compatible([
  'DATA_TYPE' => 'varchar',
  'CHARACTER_MAXIMUM_LENGTH' => 20,
  'IS_NULLABLE' => 'NO',
  'COLUMN_DEFAULT' => 'none',
]) === true, 'Production-compatible staff approval status schema should pass.');

assert_true(staff_approval_status_column_is_compatible([
  'DATA_TYPE' => 'varchar',
  'CHARACTER_MAXIMUM_LENGTH' => 20,
  'IS_NULLABLE' => 'NO',
  'COLUMN_DEFAULT' => "'none'",
]) === true, 'MariaDB quoted staff approval default should pass.');

assert_true(staff_approval_status_column_is_compatible([
  'DATA_TYPE' => 'enum',
  'CHARACTER_MAXIMUM_LENGTH' => null,
  'IS_NULLABLE' => 'NO',
  'COLUMN_DEFAULT' => 'none',
]) === false, 'Enum staff approval status schema should be repaired because it may reject new statuses.');

assert_true(staff_column_is_nullable_varchar([
  'DATA_TYPE' => 'varchar',
  'CHARACTER_MAXIMUM_LENGTH' => 255,
  'IS_NULLABLE' => 'YES',
], 255) === true, 'Nullable rejection reason schema should pass.');

assert_true(staff_column_is_nullable_varchar([
  'DATA_TYPE' => 'varchar',
  'CHARACTER_MAXIMUM_LENGTH' => 255,
  'IS_NULLABLE' => 'NO',
], 255) === false, 'Non-null rejection reason schema should be repaired.');

assert_true(staff_column_is_nullable_datetime([
  'DATA_TYPE' => 'datetime',
  'IS_NULLABLE' => 'YES',
]) === true, 'Nullable staff timestamp schema should pass.');

assert_true(staff_column_is_nullable_datetime([
  'DATA_TYPE' => 'date',
  'IS_NULLABLE' => 'YES',
]) === false, 'Date-only staff timestamp schema should be repaired.');

assert_true(staff_column_is_nullable_unsigned_int([
  'DATA_TYPE' => 'int',
  'COLUMN_TYPE' => 'int(10) unsigned',
  'IS_NULLABLE' => 'YES',
]) === true, 'Nullable unsigned staff actor schema should pass.');

assert_true(staff_column_is_nullable_unsigned_int([
  'DATA_TYPE' => 'int',
  'COLUMN_TYPE' => 'int(11)',
  'IS_NULLABLE' => 'YES',
]) === false, 'Signed staff actor schema should be repaired.');

echo "staff approval tests passed\n";
