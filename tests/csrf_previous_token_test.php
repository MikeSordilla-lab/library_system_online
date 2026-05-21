<?php

require_once __DIR__ . '/../includes/csrf.php';

function assert_true(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

$_SESSION = [];
$_POST = [];

$first = csrf_token();

$_POST['csrf_token'] = $first;
csrf_verify();

$second = $_SESSION['csrf_token'] ?? '';
assert_true($second !== '' && $second !== $first, 'CSRF token should rotate after a valid POST.');
assert_true(($_SESSION['csrf_previous_token'] ?? '') === $first, 'Previous CSRF token should be retained after rotation.');

$_POST['csrf_token'] = $first;
csrf_verify();

assert_true(($_SESSION['csrf_previous_token'] ?? '') === $second, 'Accepted stale token should be retired after one follow-up POST.');
assert_true(($_SESSION['csrf_token'] ?? '') !== $second, 'Current CSRF token should rotate after accepting stale token.');

echo "csrf previous token tests passed\n";
