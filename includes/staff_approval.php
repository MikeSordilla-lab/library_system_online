<?php

declare(strict_types=1);

const STAFF_APPROVAL_NONE = 'none';
const STAFF_APPROVAL_PENDING = 'pending';
const STAFF_APPROVAL_APPROVED = 'approved';
const STAFF_APPROVAL_REJECTED = 'rejected';

function staff_normalize_registration_role(string $role): string
{
  $role = strtolower(trim($role));
  return in_array($role, ['borrower', 'librarian'], true) ? $role : 'borrower';
}

function staff_registration_state(string $requested_role): array
{
  $role = staff_normalize_registration_role($requested_role);

  if ($role === 'librarian') {
    return [
      'role' => 'librarian',
      'is_verified' => 0,
      'is_suspended' => 1,
      'staff_approval_status' => STAFF_APPROVAL_PENDING,
    ];
  }

  return [
    'role' => 'borrower',
    'is_verified' => 0,
    'is_suspended' => 0,
    'staff_approval_status' => STAFF_APPROVAL_NONE,
  ];
}

function staff_is_pending_librarian_request(array $user): bool
{
  return (string) ($user['role'] ?? '') === 'librarian'
    && (string) ($user['staff_approval_status'] ?? STAFF_APPROVAL_NONE) === STAFF_APPROVAL_PENDING;
}

function staff_is_rejected_librarian_request(array $user): bool
{
  return (string) ($user['role'] ?? '') === 'librarian'
    && (string) ($user['staff_approval_status'] ?? STAFF_APPROVAL_NONE) === STAFF_APPROVAL_REJECTED;
}

function staff_login_block_message(array $user): string
{
  if (staff_is_pending_librarian_request($user)) {
    return 'Your librarian registration is waiting for admin approval.';
  }

  if (staff_is_rejected_librarian_request($user)) {
    return 'Your librarian registration was not approved. Please contact the library.';
  }

  if ((string) ($user['role'] ?? '') === 'librarian' && (int) ($user['is_verified'] ?? 0) !== 1) {
    return 'Your librarian account needs admin verification before you can sign in.';
  }

  return 'Please verify your email before logging in.';
}

function staff_login_block_context(array $user): string
{
  if ((string) ($user['role'] ?? '') === 'librarian') {
    return 'admin_approval';
  }

  return 'unverified';
}

function staff_column_exists(PDO $pdo, string $column): bool
{
  $stmt = $pdo->prepare(
    'SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?'
  );
  $stmt->execute(['Users', $column]);
  return (int) $stmt->fetchColumn() > 0;
}

function staff_index_exists(PDO $pdo, string $index): bool
{
  $stmt = $pdo->prepare(
    'SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND INDEX_NAME = ?'
  );
  $stmt->execute(['Users', $index]);
  return (int) $stmt->fetchColumn() > 0;
}

function ensure_staff_approval_support(PDO $pdo): void
{
  $columns = [
    'staff_approval_status' => "ALTER TABLE Users ADD COLUMN staff_approval_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER is_suspended",
    'staff_approved_by' => 'ALTER TABLE Users ADD COLUMN staff_approved_by INT UNSIGNED NULL AFTER staff_approval_status',
    'staff_approved_at' => 'ALTER TABLE Users ADD COLUMN staff_approved_at DATETIME NULL AFTER staff_approved_by',
    'staff_rejected_by' => 'ALTER TABLE Users ADD COLUMN staff_rejected_by INT UNSIGNED NULL AFTER staff_approved_at',
    'staff_rejected_at' => 'ALTER TABLE Users ADD COLUMN staff_rejected_at DATETIME NULL AFTER staff_rejected_by',
    'staff_rejection_reason' => 'ALTER TABLE Users ADD COLUMN staff_rejection_reason VARCHAR(255) NULL AFTER staff_rejected_at',
  ];

  foreach ($columns as $column => $sql) {
    if (!staff_column_exists($pdo, $column)) {
      $pdo->exec($sql);
    }
  }

  if (!staff_index_exists($pdo, 'idx_users_staff_approval')) {
    $pdo->exec('CREATE INDEX idx_users_staff_approval ON Users (staff_approval_status, role)');
  }
}
