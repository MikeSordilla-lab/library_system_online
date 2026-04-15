<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/receipts.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function receipt_api_json(int $status, bool $ok, string $message, array $data = [], array $meta = []): void
{
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode([
    'ok' => $ok,
    'message' => $message,
    'data' => $data,
    'meta' => $meta,
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

function receipt_api_actor(PDO $pdo): array
{
  $user_id = (int) ($_SESSION['user_id'] ?? 0);
  $role = (string) ($_SESSION['role'] ?? '');

  if ($user_id < 1 || $role === '') {
    receipt_api_json(401, false, 'Authentication required.');
  }

  $stmt = $pdo->prepare('SELECT id, role, is_verified, is_suspended FROM Users WHERE id = ? LIMIT 1');
  $stmt->execute([$user_id]);
  $row = $stmt->fetch();
  if (!$row || (int) $row['is_verified'] !== 1 || (int) $row['is_suspended'] === 1) {
    receipt_api_json(403, false, 'Session is not authorized.');
  }

  $_SESSION['role'] = (string) $row['role'];

  return [
    'id' => (int) $row['id'],
    'role' => (string) $row['role'],
  ];
}

function receipt_api_phase1_guard(PDO $pdo): void
{
  if (!receipt_phase1_enabled($pdo)) {
    receipt_api_json(503, false, 'Receipt Phase 1 API is disabled by feature toggle.', [], [
      'feature_toggle' => 'receipt_phase1_enabled',
    ]);
  }
}

function receipt_api_require_method(string $method): void
{
  if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
    receipt_api_json(405, false, 'Method not allowed.', [], ['allow' => strtoupper($method)]);
  }
}

function receipt_api_read_json_body(): array
{
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    return [];
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    receipt_api_json(400, false, 'Invalid JSON body.');
  }

  return $data;
}

function receipt_api_receipt_from_request(PDO $pdo): ?array
{
  $id = (int) ($_GET['id'] ?? 0);
  $no = trim((string) ($_GET['no'] ?? ''));

  if ($id > 0) {
    return get_receipt_ticket_by_id($pdo, $id);
  }

  if ($no !== '') {
    return get_receipt_ticket_by_number($pdo, $no);
  }

  return null;
}
