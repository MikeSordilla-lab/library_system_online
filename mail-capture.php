<?php

require_once __DIR__ . '/config.php';

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = explode(':', $host, 2)[0];
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

if (!DEBUG_MODE || !$isLocal || !MAIL_CAPTURE_ENABLED) {
  http_response_code(404);
  exit('Not found');
}

$email = trim((string) ($_GET['email'] ?? ''));
$dir = rtrim((string) MAIL_CAPTURE_DIR, "\\/");
$file = $dir . '/latest.json';

if ($email !== '') {
  $safeEmail = preg_replace('/[^A-Za-z0-9._-]+/', '_', strtolower($email));
  $file = $dir . '/' . $safeEmail . '.json';
}

header('Content-Type: application/json; charset=utf-8');

if (!is_file($file)) {
  http_response_code(404);
  echo json_encode(['error' => 'No captured mail found.']);
  exit;
}

readfile($file);
