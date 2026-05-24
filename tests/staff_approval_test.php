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

assert_true(staff_login_block_message([
  'role' => 'librarian',
  'is_verified' => 1,
  'staff_approval_status' => 'pending',
]) === 'Your librarian account needs admin verification before you can sign in.', 'Pending librarian login message should be specific.');

assert_true(staff_requires_admin_login_gate([
  'role' => 'librarian',
  'is_verified' => 1,
  'staff_approval_status' => 'pending',
]) === true, 'Verified pending librarian rows should be blocked by admin gate before suspension.');

assert_true(staff_requires_admin_login_gate([
  'role' => 'librarian',
  'is_verified' => 1,
  'staff_approval_status' => 'approved',
]) === false, 'Approved librarian rows should not be blocked by admin gate.');

assert_true(staff_login_block_context([
  'role' => 'librarian',
  'is_verified' => 0,
  'staff_approval_status' => 'pending',
]) === 'unverified', 'Unverified librarian accounts should use email verification context.');

assert_true(staff_login_block_message([
  'role' => 'librarian',
  'is_verified' => 0,
  'staff_approval_status' => 'pending',
]) === 'Your librarian account needs email verification first, then admin verification before you can sign in.', 'Unverified librarian message should mention email verification before admin approval.');

assert_true(staff_login_block_context([
  'role' => 'librarian',
  'is_verified' => 1,
  'staff_approval_status' => 'pending',
]) === 'admin_approval', 'Verified pending librarian accounts should use admin approval context.');

assert_true(staff_login_block_context([
  'role' => 'librarian',
  'is_verified' => 0,
  'staff_approval_status' => 'rejected',
]) === 'admin_approval', 'Rejected librarian accounts should not offer email verification resend.');

echo "staff approval tests passed\n";
