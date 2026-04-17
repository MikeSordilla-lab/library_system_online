<?php

/**
 * includes/receipts.php — Receipt/Ticket helpers (Phase 1)
 */

if (defined('RECEIPTS_PHP_LOADED')) {
  return;
}
define('RECEIPTS_PHP_LOADED', true);

require_once __DIR__ . '/circulation.php';

if (!defined('RECEIPT_SIGNATURE_FALLBACK_KEY')) {
  define('RECEIPT_SIGNATURE_FALLBACK_KEY', 'receipt-phase1-fallback-key-change-me');
}

function receipt_table_columns(PDO $pdo, string $table): array
{
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) {
    return $cache[$key];
  }

  try {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (Throwable $e) {
    $cache[$key] = [];
    return [];
  }
  $cols = [];
  foreach ($rows as $row) {
    $name = (string) ($row['Field'] ?? '');
    if ($name !== '') {
      $cols[$name] = true;
    }
  }
  $cache[$key] = $cols;
  return $cols;
}

function receipt_phase1_enabled(PDO $pdo): bool
{
  $raw = strtolower(trim((string) get_setting($pdo, 'receipt_phase1_enabled', '1')));
  return !in_array($raw, ['0', 'off', 'false', 'disabled', 'no'], true);
}

function receipt_has_column(PDO $pdo, string $table, string $column): bool
{
  $cols = receipt_table_columns($pdo, $table);
  return isset($cols[$column]);
}

/**
 * Confirm Receipt_Tickets table is available.
 *
 * When missing, this helper can either fail closed with a clear exception
 * (write paths) or fail open with empty results (read/list paths).
 */
function receipt_require_table(PDO $pdo, bool $throw = false): bool
{
  static $missing_logged = false;

  $exists = !empty(receipt_table_columns($pdo, 'Receipt_Tickets'));
  if ($exists) {
    return true;
  }

  $msg = 'Receipt_Tickets table is missing. Apply migration: php admin/migrations/runner.php receipts-phase1';
  if ($throw) {
    throw new RuntimeException($msg);
  }

  if (!$missing_logged) {
    error_log('[receipts.php] ' . $msg);
    $missing_logged = true;
  }
  return false;
}
function receipt_select_clause(PDO $pdo): string
{
  $cols = receipt_table_columns($pdo, 'Receipt_Tickets');
  $defaults = [
    "'issued' AS status",
    "'thermal' AS format",
    "'en_US' AS locale",
    "'UTC' AS timezone",
    "'USD' AS currency",
    "NULL AS payload_hash",
    "NULL AS payload_signature",
    "NULL AS branch_id",
    "'web' AS channel",
  ];

  foreach ($defaults as $i => $sql) {
    $parts = explode(' AS ', $sql);
    $alias = trim((string) ($parts[1] ?? ''));
    if ($alias !== '' && isset($cols[$alias])) {
      $defaults[$i] = '`' . $alias . '`';
    }
  }

  return '*, ' . implode(', ', $defaults);
}

function receipt_recursive_sort($value)
{
  if (!is_array($value)) {
    return $value;
  }

  $is_assoc = array_keys($value) !== range(0, count($value) - 1);
  if ($is_assoc) {
    ksort($value, SORT_STRING);
  }

  foreach ($value as $k => $v) {
    $value[$k] = receipt_recursive_sort($v);
  }

  return $value;
}

function receipt_json(array $data): string
{
  $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return $json === false ? '{}' : $json;
}

function receipt_hmac_key(?PDO $pdo = null): string
{
  $key = trim((string) getenv('APP_KEY'));
  if ($key !== '') {
    return $key;
  }

  if ($pdo !== null) {
    try {
      $from_settings = trim((string) get_setting($pdo, 'receipt_hmac_key', ''));
      if ($from_settings !== '') {
        return $from_settings;
      }
    } catch (Throwable $e) {
      // Ignore settings lookup errors and use fallback.
    }
  }

  return RECEIPT_SIGNATURE_FALLBACK_KEY;
}

function receipt_canonical_payload(array $receipt, array $payload): string
{
  $canonical = [
    'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
    'type' => (string) ($receipt['type'] ?? ''),
    'issued_at' => (string) ($receipt['issued_at'] ?? ''),
    'reference_table' => (string) ($receipt['reference_table'] ?? ''),
    'reference_id' => (int) ($receipt['reference_id'] ?? 0),
    'amount' => ($receipt['amount'] ?? null) !== null ? (float) $receipt['amount'] : null,
    'currency' => (string) ($receipt['currency'] ?? 'USD'),
    'status' => (string) ($receipt['status'] ?? 'issued'),
    'format' => (string) ($receipt['format'] ?? 'thermal'),
    'locale' => (string) ($receipt['locale'] ?? 'en_US'),
    'timezone' => (string) ($receipt['timezone'] ?? 'UTC'),
    'channel' => (string) ($receipt['channel'] ?? 'web'),
    'payload' => receipt_recursive_sort($payload),
  ];

  return receipt_json($canonical);
}

function receipt_generate_integrity(array $receipt, array $payload, ?PDO $pdo = null): array
{
  $canonical = receipt_canonical_payload($receipt, $payload);
  $hash = hash('sha256', $canonical);
  $sig = hash_hmac('sha256', $canonical, receipt_hmac_key($pdo));
  return [$canonical, $hash, $sig];
}

/**
 * Create a readable receipt number.
 */
function receipt_generate_no(int $receipt_id, ?string $issued_at = null): string
{
  $stamp = $issued_at !== null ? strtotime($issued_at) : time();
  if ($stamp === false) {
    $stamp = time();
  }

  return 'RCP-' . date('Ymd', $stamp) . '-' . str_pad((string) $receipt_id, 4, '0', STR_PAD_LEFT);
}

/**
 * Issue a receipt ticket, idempotent per idempotency_key.
 */
function issue_receipt_ticket(PDO $pdo, array $data): array
{
  receipt_require_table($pdo, true);
  $type = trim((string) ($data['type'] ?? ''));
  $actor_user_id = (int) ($data['actor_user_id'] ?? 0);
  $patron_user_id = (int) ($data['patron_user_id'] ?? 0);
  $reference_table = trim((string) ($data['reference_table'] ?? ''));
  $reference_id = (int) ($data['reference_id'] ?? 0);

  if ($type === '' || $actor_user_id < 1 || $patron_user_id < 1 || $reference_table === '' || $reference_id < 1) {
    throw new InvalidArgumentException('Invalid receipt payload.');
  }

  $idempotency_key = trim((string) ($data['idempotency_key'] ?? ''));
  if ($idempotency_key === '') {
    $idempotency_key = $type . ':' . $reference_table . ':' . $reference_id;
  }

  $existing = $pdo->prepare('SELECT ' . receipt_select_clause($pdo) . ' FROM Receipt_Tickets WHERE idempotency_key = ? LIMIT 1');
  $existing->execute([$idempotency_key]);
  $row = $existing->fetch();
  if ($row) {
    return $row;
  }

  $payload = $data['payload'] ?? [];
  if (!is_array($payload)) {
    $payload = [];
  }
  $payload = receipt_recursive_sort($payload);

  $amount = isset($data['amount']) ? (float) $data['amount'] : null;
  $currency = strtoupper(trim((string) ($data['currency'] ?? 'USD')));
  if ($currency === '') {
    $currency = 'USD';
  }

  $status = trim((string) ($data['status'] ?? 'issued'));
  if ($status === '') {
    $status = 'issued';
  }

  $format = strtolower(trim((string) ($data['format'] ?? 'thermal')));
  if ($format !== 'a4') {
    $format = 'thermal';
  }

  $locale = trim((string) ($data['locale'] ?? 'en_US'));
  if ($locale === '') {
    $locale = 'en_US';
  }

  $timezone = trim((string) ($data['timezone'] ?? 'UTC'));
  if ($timezone === '') {
    $timezone = 'UTC';
  }

  $channel = trim((string) ($data['channel'] ?? 'web'));
  if ($channel === '') {
    $channel = 'web';
  }

  $branch_id = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
  if ($branch_id !== null && $branch_id < 1) {
    $branch_id = null;
  }

  $library_label = trim((string) ($data['library_label'] ?? ''));
  if ($library_label === '') {
    $library_label = (string) get_setting($pdo, 'library_name', 'Library System');
  }

  $payload_json = receipt_json($payload);
  $temp_no = 'TMP-' . bin2hex(random_bytes(8));

  $integrity_base = [
    'receipt_no' => $temp_no,
    'type' => $type,
    'issued_at' => date('Y-m-d H:i:s'),
    'reference_table' => $reference_table,
    'reference_id' => $reference_id,
    'amount' => $amount,
    'currency' => $currency,
    'status' => $status,
    'format' => $format,
    'locale' => $locale,
    'timezone' => $timezone,
    'channel' => $channel,
  ];
  [, $payload_hash, $payload_signature] = receipt_generate_integrity($integrity_base, $payload, $pdo);

  $insert_data = [
    'receipt_no' => $temp_no,
    'idempotency_key' => $idempotency_key,
    'type' => $type,
    'library_label' => $library_label,
    'actor_user_id' => $actor_user_id,
    'patron_user_id' => $patron_user_id,
    'reference_table' => $reference_table,
    'reference_id' => $reference_id,
    'amount' => $amount,
    'currency' => $currency,
    'payload_json' => $payload_json,
    'status' => $status,
    'format' => $format,
    'locale' => $locale,
    'timezone' => $timezone,
    'payload_hash' => $payload_hash,
    'payload_signature' => $payload_signature,
    'branch_id' => $branch_id,
    'channel' => $channel,
  ];

  $columns = receipt_table_columns($pdo, 'Receipt_Tickets');
  $fields = [];
  $values = [];
  $bind = [];
  foreach ($insert_data as $field => $value) {
    if (!isset($columns[$field])) {
      continue;
    }
    $fields[] = '`' . $field . '`';
    $values[] = '?';
    $bind[] = $value;
  }

  if (empty($fields)) {
    throw new RuntimeException('Receipt_Tickets table has no expected fields.');
  }

  $insert_sql = 'INSERT INTO Receipt_Tickets (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
  $insert = $pdo->prepare($insert_sql);

  try {
    $insert->execute($bind);
  } catch (PDOException $e) {
    if (stripos($e->getMessage(), 'Duplicate') !== false) {
      $dupe = $pdo->prepare('SELECT ' . receipt_select_clause($pdo) . ' FROM Receipt_Tickets WHERE idempotency_key = ? LIMIT 1');
      $dupe->execute([$idempotency_key]);
      $dupe_row = $dupe->fetch();
      if ($dupe_row) {
        return $dupe_row;
      }
    }
    throw $e;
  }

  $receipt_id = (int) $pdo->lastInsertId();
  $receipt_no = receipt_generate_no($receipt_id);

  $upd = $pdo->prepare('UPDATE Receipt_Tickets SET receipt_no = ? WHERE id = ?');
  $upd->execute([$receipt_no, $receipt_id]);

  if (isset($columns['payload_hash']) || isset($columns['payload_signature'])) {
    $receipt_for_signature = $integrity_base;
    $receipt_for_signature['receipt_no'] = $receipt_no;
    $issued_stmt = $pdo->prepare('SELECT issued_at FROM Receipt_Tickets WHERE id = ? LIMIT 1');
    $issued_stmt->execute([$receipt_id]);
    $issued_row = $issued_stmt->fetch();
    $receipt_for_signature['issued_at'] = (string) ($issued_row['issued_at'] ?? date('Y-m-d H:i:s'));
    [, $payload_hash2, $payload_signature2] = receipt_generate_integrity($receipt_for_signature, $payload, $pdo);

    $set_parts = [];
    $set_bind = [];
    if (isset($columns['payload_hash'])) {
      $set_parts[] = 'payload_hash = ?';
      $set_bind[] = $payload_hash2;
    }
    if (isset($columns['payload_signature'])) {
      $set_parts[] = 'payload_signature = ?';
      $set_bind[] = $payload_signature2;
    }
    if (!empty($set_parts)) {
      $set_bind[] = $receipt_id;
      $sql = 'UPDATE Receipt_Tickets SET ' . implode(', ', $set_parts) . ' WHERE id = ?';
      $sig_upd = $pdo->prepare($sql);
      $sig_upd->execute($set_bind);
    }
  }

  $get = $pdo->prepare('SELECT ' . receipt_select_clause($pdo) . ' FROM Receipt_Tickets WHERE id = ? LIMIT 1');
  $get->execute([$receipt_id]);
  $created = $get->fetch();

  if (!$created) {
    throw new RuntimeException('Receipt creation failed.');
  }

  log_receipt_ticket_event(
    $pdo,
    (int) $created['id'],
    $actor_user_id,
    (string) ($data['actor_role'] ?? ''),
    'issue',
    [
      'channel' => $channel,
      'reference_table' => $reference_table,
      'reference_id' => $reference_id,
    ],
    [
      'reason' => (string) ($data['reason'] ?? ''),
      'channel' => $channel,
    ]
  );

  log_circulation($pdo, [
    'actor_id' => $actor_user_id,
    'actor_role' => (string) ($data['actor_role'] ?? ''),
    'action_type' => 'receipt_issue',
    'target_entity' => 'Receipt_Tickets',
    'target_id' => (int) $created['id'],
    'outcome' => 'success',
  ]);

  return $created;
}

function get_receipt_ticket_by_id(PDO $pdo, int $receipt_id): ?array
{
  if (!receipt_require_table($pdo, false)) {
    return null;
  }
  if ($receipt_id < 1) {
    return null;
  }
  $stmt = $pdo->prepare('SELECT ' . receipt_select_clause($pdo) . ' FROM Receipt_Tickets WHERE id = ? LIMIT 1');
  $stmt->execute([$receipt_id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

/**
 * Fetch a receipt using receipt number.
 */
function get_receipt_ticket_by_number(PDO $pdo, string $receipt_no): ?array
{
  if (!receipt_require_table($pdo, false)) {
    return null;
  }
  $receipt_no = trim($receipt_no);
  if ($receipt_no === '') {
    return null;
  }

  $stmt = $pdo->prepare('SELECT ' . receipt_select_clause($pdo) . ' FROM Receipt_Tickets WHERE receipt_no = ? LIMIT 1');
  $stmt->execute([$receipt_no]);
  $row = $stmt->fetch();

  return $row ?: null;
}

/**
 * Fetch recent receipt tickets for listing.
 */
function get_receipt_tickets(PDO $pdo, int $viewer_user_id, string $viewer_role, int $limit = 100): array
{
  if (!receipt_require_table($pdo, false)) {
    return [];
  }
  $limit = max(1, min(500, $limit));

  if ($viewer_role === 'admin' || $viewer_role === 'librarian') {
    $stmt = $pdo->prepare(
      'SELECT ' . receipt_select_clause($pdo) . '
         FROM Receipt_Tickets
        ORDER BY issued_at DESC, id DESC
        LIMIT ' . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
  }

  $stmt = $pdo->prepare(
    'SELECT ' . receipt_select_clause($pdo) . '
       FROM Receipt_Tickets
      WHERE patron_user_id = ?
      ORDER BY issued_at DESC, id DESC
      LIMIT ' . (int) $limit
  );
  $stmt->execute([$viewer_user_id]);
  return $stmt->fetchAll() ?: [];
}

/**
 * Access guard helper for receipt view.
 */
function can_access_receipt_ticket(array $receipt, int $viewer_user_id, string $viewer_role): bool
{
  if ($viewer_role === 'admin' || $viewer_role === 'librarian') {
    return true;
  }

  return (int) ($receipt['patron_user_id'] ?? 0) === $viewer_user_id;
}

/**
 * Decode receipt payload safely.
 */
function decode_receipt_payload(array $receipt): array
{
  $raw = (string) ($receipt['payload_json'] ?? '{}');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function receipt_filtered_payload(array $payload): array
{
  $output = [];
  foreach ($payload as $key => $value) {
    if (!is_scalar($value) || is_bool($value) || $value === null) {
      continue;
    }

    $k = (string) $key;
    if (preg_match('/(password|token|secret|signature|hash|email|phone|address|notes?)/i', $k)) {
      continue;
    }

    $output[$k] = (string) $value;
  }
  return $output;
}

/**
 * Build plain text signed QR payload string.
 */
function build_receipt_qr_payload(array $receipt): string
{
  $signature = (string) ($receipt['payload_signature'] ?? '');
  if ($signature === '') {
    $payload = decode_receipt_payload($receipt);
    [, , $signature] = receipt_generate_integrity($receipt, $payload, null);
  }

  return 'LIBRCP|no=' . rawurlencode((string) ($receipt['receipt_no'] ?? ''))
    . '&type=' . rawurlencode((string) ($receipt['type'] ?? ''))
    . '&issued=' . rawurlencode((string) ($receipt['issued_at'] ?? ''))
    . '&sig=' . rawurlencode(substr($signature, 0, 24));
}

/**
 * Mask person name for printed receipt.
 */
function mask_receipt_name(string $full_name): string
{
  $full_name = trim($full_name);
  if ($full_name === '') {
    return 'N/A';
  }

  $parts = preg_split('/\s+/', $full_name);
  if (!$parts) {
    return 'N/A';
  }

  $masked = [];
  foreach ($parts as $part) {
    $first = substr($part, 0, 1);
    $masked[] = $first . str_repeat('*', max(strlen($part) - 1, 1));
  }

  return implode(' ', $masked);
}

/**
 * Mask email for printed receipt.
 */
function mask_receipt_email(string $email): string
{
  $email = trim($email);
  if ($email === '' || strpos($email, '@') === false) {
    return 'N/A';
  }

  [$user, $domain] = explode('@', $email, 2);
  $user_prefix = substr($user, 0, 2);
  if ($user_prefix === '') {
    $user_prefix = '*';
  }

  return $user_prefix . str_repeat('*', 3) . '@' . $domain;
}

/**
 * Load basic patron profile for ticket rendering.
 */
function get_receipt_patron(PDO $pdo, int $user_id): ?array
{
  $stmt = $pdo->prepare('SELECT id, full_name, email FROM Users WHERE id = ? LIMIT 1');
  $stmt->execute([$user_id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function receipt_type_label(string $type): string
{
  if ($type === 'checkout') {
    return 'Checkout Ticket';
  }
  if ($type === 'checkin') {
    return 'Return Ticket';
  }
  if ($type === 'fine_payment') {
    return 'Fine Payment Receipt';
  }
  if ($type === 'reservation_place') {
    return 'Reservation Ticket';
  }
  if ($type === 'visitor_pass') {
    return 'Visitor Pass';
  }
  return 'Transaction Ticket';
}

function build_receipt_view_model(PDO $pdo, array $receipt, bool $mask_pii = true): array
{
  $payload = decode_receipt_payload($receipt);
  $payload_clean = receipt_filtered_payload($payload);
  $patron = get_receipt_patron($pdo, (int) ($receipt['patron_user_id'] ?? 0));

  $type = (string) ($receipt['type'] ?? 'transaction');
  $section = 'details';
  if ($type === 'checkout') {
    $section = 'checkout';
  } elseif ($type === 'checkin') {
    $section = 'checkin';
  } elseif ($type === 'fine_payment') {
    $section = 'payment';
  } elseif ($type === 'reservation_place') {
    $section = 'reservation';
  } elseif ($type === 'visitor_pass') {
    $section = 'visitor';
  }

  $issued = (string) ($receipt['issued_at'] ?? '');
  $timezone = (string) ($receipt['timezone'] ?? 'UTC');
  $issued_fmt = 'N/A';
  if ($issued !== '') {
    try {
      $dt = new DateTime($issued, new DateTimeZone('UTC'));
      if (in_array($timezone, timezone_identifiers_list(), true)) {
        $dt->setTimezone(new DateTimeZone($timezone));
      }
      $issued_fmt = $dt->format('M d, Y h:i A T');
    } catch (Throwable $e) {
      $issued_fmt = $issued;
    }
  }

  $patron_name = (string) ($patron['full_name'] ?? ($payload['patron_name'] ?? ''));
  $patron_email = (string) ($patron['email'] ?? ($payload['patron_email'] ?? ''));

  $detail_lines = [];
  if ($type === 'checkout') {
    $detail_lines[] = ['Loan ID', (string) ($payload_clean['loan_id'] ?? $receipt['reference_id'])];
    $detail_lines[] = ['Book', (string) ($payload_clean['book_title'] ?? 'N/A')];
    $detail_lines[] = ['Due Date', (string) ($payload_clean['due_date'] ?? 'N/A')];
    $detail_lines[] = ['Loan Period', (string) ($payload_clean['loan_days'] ?? 'N/A') . ' day(s)'];
  } elseif ($type === 'checkin') {
    $detail_lines[] = ['Loan ID', (string) ($payload_clean['loan_id'] ?? $receipt['reference_id'])];
    $detail_lines[] = ['Book', (string) ($payload_clean['book_title'] ?? 'N/A')];
    $detail_lines[] = ['Due Date', (string) ($payload_clean['due_date'] ?? 'N/A')];
    $detail_lines[] = ['Returned At', (string) ($payload_clean['returned_at'] ?? 'N/A')];
    $detail_lines[] = ['Days Late', (string) ($payload_clean['days_late'] ?? '0')];
    $detail_lines[] = ['Fine Assessed', number_format((float) ($payload_clean['fine_amount'] ?? 0), 2) . ' ' . (string) ($receipt['currency'] ?? 'USD')];
  } elseif ($type === 'fine_payment') {
    $detail_lines[] = ['Borrower ID', (string) ($receipt['patron_user_id'] ?? 'N/A')];
    $detail_lines[] = ['Settlement', (string) ($payload_clean['settled_scope'] ?? 'fine_payment')];
    $detail_lines[] = ['Amount Paid', number_format((float) ($receipt['amount'] ?? 0), 2) . ' ' . (string) ($receipt['currency'] ?? 'USD')];
  } elseif ($type === 'reservation_place') {
    $detail_lines[] = ['Reservation ID', (string) ($payload_clean['reservation_id'] ?? $receipt['reference_id'])];
    $detail_lines[] = ['Book', (string) ($payload_clean['book_title'] ?? 'N/A')];
    $detail_lines[] = ['Queue Position', (string) ($payload_clean['queue_position'] ?? 'N/A')];
    $detail_lines[] = ['Reservation Expires', (string) ($payload_clean['expires_at'] ?? 'N/A')];
  } elseif ($type === 'visitor_pass') {
    $detail_lines[] = ['Visitor', (string) ($payload_clean['visitor_name'] ?? 'N/A')];
    $detail_lines[] = ['Purpose', (string) ($payload_clean['purpose'] ?? 'Library Visit')];
    $detail_lines[] = ['Valid Until', (string) ($payload_clean['valid_until'] ?? 'N/A')];
    $detail_lines[] = ['Issued By', (string) ($payload_clean['issued_by'] ?? 'Staff')];
  } else {
    foreach ($payload_clean as $k => $v) {
      $detail_lines[] = [ucwords(str_replace('_', ' ', (string) $k)), (string) $v];
    }
  }

  if (empty($detail_lines)) {
    $detail_lines[] = ['Reference', (string) ($receipt['reference_table'] ?? '') . '#' . (string) ($receipt['reference_id'] ?? '')];
  }

  return [
    'id' => (int) ($receipt['id'] ?? 0),
    'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
    'type' => $type,
    'type_label' => receipt_type_label($type),
    'section' => $section,
    'library_label' => (string) ($receipt['library_label'] ?? 'Library System'),
    'issued_at' => $issued,
    'issued_at_formatted' => $issued_fmt,
    'status' => (string) ($receipt['status'] ?? 'issued'),
    'format' => (string) ($receipt['format'] ?? 'thermal'),
    'locale' => (string) ($receipt['locale'] ?? 'en_US'),
    'timezone' => (string) ($receipt['timezone'] ?? 'UTC'),
    'currency' => (string) ($receipt['currency'] ?? 'USD'),
    'amount' => ($receipt['amount'] ?? null) !== null ? (float) $receipt['amount'] : null,
    'channel' => (string) ($receipt['channel'] ?? 'web'),
    'branch_id' => isset($receipt['branch_id']) ? (int) $receipt['branch_id'] : null,
    'reference_table' => (string) ($receipt['reference_table'] ?? ''),
    'reference_id' => (int) ($receipt['reference_id'] ?? 0),
    'patron' => [
      'id' => (int) ($receipt['patron_user_id'] ?? 0),
      'name' => $mask_pii ? mask_receipt_name($patron_name) : $patron_name,
      'email' => $mask_pii ? mask_receipt_email($patron_email) : $patron_email,
    ],
    'details' => $detail_lines,
    'payload_hash' => (string) ($receipt['payload_hash'] ?? ''),
    'payload_signature' => (string) ($receipt['payload_signature'] ?? ''),
    'payload_public' => $payload_clean,
    'qr_payload' => build_receipt_qr_payload($receipt),
  ];
}

function receipt_public_api_data(PDO $pdo, array $receipt, bool $mask_pii = true): array
{
  $model = build_receipt_view_model($pdo, $receipt, $mask_pii);
  return [
    'id' => $model['id'],
    'receipt_no' => $model['receipt_no'],
    'type' => $model['type'],
    'type_label' => $model['type_label'],
    'status' => $model['status'],
    'format' => $model['format'],
    'issued_at' => $model['issued_at'],
    'issued_at_formatted' => $model['issued_at_formatted'],
    'locale' => $model['locale'],
    'timezone' => $model['timezone'],
    'currency' => $model['currency'],
    'amount' => $model['amount'],
    'channel' => $model['channel'],
    'branch_id' => $model['branch_id'],
    'reference' => [
      'table' => $model['reference_table'],
      'id' => $model['reference_id'],
    ],
    'patron' => $model['patron'],
    'details' => $model['details'],
    'qr_payload' => $model['qr_payload'],
    'integrity' => [
      'payload_hash' => $model['payload_hash'],
      'payload_signature' => $model['payload_signature'] === '' ? '' : substr($model['payload_signature'], 0, 24) . '...',
    ],
  ];
}

function create_receipt_print_job(PDO $pdo, int $receipt_id, int $actor_user_id, string $actor_role, string $target = 'browser_print', string $status = 'queued', string $channel = 'web', string $error_message = '', array $meta = []): ?array
{
  if (!receipt_table_columns($pdo, 'Receipt_Print_Jobs')) {
    return null;
  }

  $meta_json = receipt_json($meta);
  $stmt = $pdo->prepare(
    'INSERT INTO Receipt_Print_Jobs (receipt_id, actor_user_id, actor_role, target, status, error_message, channel, meta_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $receipt_id,
    $actor_user_id,
    $actor_role,
    $target,
    $status,
    $error_message,
    $channel,
    $meta_json,
  ]);

  $job_id = (int) $pdo->lastInsertId();
  $get = $pdo->prepare('SELECT * FROM Receipt_Print_Jobs WHERE id = ? LIMIT 1');
  $get->execute([$job_id]);
  $job = $get->fetch();
  return $job ?: null;
}

/**
 * Log receipt view/reprint/issue event.
 */
function log_receipt_ticket_event(PDO $pdo, int $receipt_id, int $actor_user_id, string $actor_role, string $event_type, array $meta = [], array $options = []): void
{
  $meta_json = receipt_json($meta);
  $columns = receipt_table_columns($pdo, 'Receipt_Ticket_Logs');

  $insert_data = [
    'receipt_id' => $receipt_id,
    'actor_user_id' => $actor_user_id,
    'actor_role' => $actor_role,
    'event_type' => $event_type,
    'meta_json' => $meta_json,
    'reason' => (string) ($options['reason'] ?? ''),
    'job_target' => (string) ($options['job_target'] ?? ''),
    'target_status' => (string) ($options['target_status'] ?? ''),
    'error_message' => (string) ($options['error_message'] ?? ''),
    'channel' => (string) ($options['channel'] ?? 'web'),
  ];

  $fields = [];
  $holders = [];
  $bind = [];
  foreach ($insert_data as $field => $value) {
    if (!isset($columns[$field])) {
      continue;
    }
    $fields[] = '`' . $field . '`';
    $holders[] = '?';
    $bind[] = $value;
  }

  if (!empty($fields)) {
    $stmt = $pdo->prepare('INSERT INTO Receipt_Ticket_Logs (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $holders) . ')');
    $stmt->execute($bind);
  }

  log_circulation($pdo, [
    'actor_id' => $actor_user_id,
    'actor_role' => $actor_role,
    'action_type' => 'receipt_' . $event_type,
    'target_entity' => 'Receipt_Tickets',
    'target_id' => $receipt_id,
    'outcome' => ($options['target_status'] ?? '') === 'failed' ? 'failure' : 'success',
  ]);
}

function request_receipt_reprint(PDO $pdo, array $receipt, int $actor_user_id, string $actor_role, string $reason, array $options = []): array
{
  $reason = trim($reason);
  if (strlen($reason) < 3) {
    throw new InvalidArgumentException('Reprint reason must be at least 3 characters.');
  }

  $target = trim((string) ($options['target'] ?? 'browser_print'));
  if ($target === '') {
    $target = 'browser_print';
  }

  $channel = trim((string) ($options['channel'] ?? ($receipt['channel'] ?? 'web')));
  if ($channel === '') {
    $channel = 'web';
  }

  $job = create_receipt_print_job(
    $pdo,
    (int) $receipt['id'],
    $actor_user_id,
    $actor_role,
    $target,
    'completed',
    $channel,
    '',
    ['reason' => $reason]
  );

  log_receipt_ticket_event(
    $pdo,
    (int) $receipt['id'],
    $actor_user_id,
    $actor_role,
    'reprint',
    [
      'target' => $target,
      'job_id' => (int) ($job['id'] ?? 0),
    ],
    [
      'reason' => $reason,
      'job_target' => $target,
      'target_status' => 'completed',
      'channel' => $channel,
    ]
  );

  return [
    'job' => $job,
    'reason' => $reason,
    'target' => $target,
  ];
}

function receipt_view_url(array $receipt): string
{
  $base = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';
  return $base . 'receipt/view.php?no=' . rawurlencode((string) ($receipt['receipt_no'] ?? ''));
}

function receipt_kiosk_url(array $receipt): string
{
  $base = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';
  return $base . 'receipt/kiosk.php?no=' . rawurlencode((string) ($receipt['receipt_no'] ?? ''));
}

function receipt_pdf_meta(array $receipt): array
{
  $base = (defined('BASE_URL') ? (string) constant('BASE_URL') : '/') . 'api/receipts/pdf.php?';
  $no = rawurlencode((string) ($receipt['receipt_no'] ?? ''));
  return [
    'pdf_available' => false,
    'html_url' => receipt_view_url($receipt),
    'download_html_url' => $base . 'no=' . $no . '&mode=download_html',
    'message' => 'PDF library is not installed; HTML fallback is provided.',
  ];
}
