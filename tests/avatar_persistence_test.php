<?php

define('BASE_URL', '/library_system_online/');

require_once __DIR__ . '/../includes/avatar.php';

function assert_true($condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

if (!is_dir(AVATAR_UPLOAD_DIR)) {
  mkdir(AVATAR_UPLOAD_DIR, 0755, true);
}

$fixture = AVATAR_UPLOAD_DIR . 'avatar_test_persistent.png';
file_put_contents($fixture, 'not-rendered-by-test');

$existing = get_avatar_display('assets/avatars/avatar_test_persistent.png', 'Borrower User');
assert_true($existing['type'] === 'image', 'Existing avatar should render as image.');
assert_true(str_ends_with((string) $existing['url'], '/assets/avatars/avatar_test_persistent.png'), 'Existing avatar URL should point to avatars directory.');

@unlink($fixture);

$missing = get_avatar_display('assets/avatars/avatar_test_missing.png', 'Borrower User');
assert_true($missing['type'] === 'initials', 'Missing avatar file should fall back to initials.');
assert_true($missing['initials'] === 'BU', 'Fallback initials should still be available.');

echo "avatar persistence tests passed\n";
