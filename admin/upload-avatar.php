<?php

/**
 * admin/upload-avatar.php — Handle Avatar Upload (Phase 2)
 *
 * AJAX endpoint for avatar uploads from the admin dashboard.
 * Protected: Admin role only
 */

header('Content-Type: application/json');

// RBAC guard
$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/avatar.php';

$response = ['success' => false, 'message' => 'Unknown error'];

try {
  // Check request method
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    http_response_code(405);
    echo json_encode($response);
    exit;
  }

  // Check if avatar file was uploaded
  if (!isset($_FILES['avatar'])) {
    $response['message'] = 'No file uploaded';
    http_response_code(400);
    echo json_encode($response);
    exit;
  }

  // Get current admin's avatar
  $pdo = get_db();
  $stmt = $pdo->prepare('SELECT avatar_url FROM Users WHERE id = ?');
  $stmt->execute([(int) $_SESSION['user_id']]);
  $current_avatar = $stmt->fetchColumn();

  // Process upload
  $upload_result = process_avatar_upload(
    (int) $_SESSION['user_id'],
    $current_avatar,
    $_FILES['avatar'],
    $pdo
  );

  if ($upload_result['success']) {
    $response['success'] = true;
    $response['message'] = $upload_result['message'];
    $response['avatar_url'] = $upload_result['avatar_url'];
    http_response_code(200);
  } else {
    $response['message'] = $upload_result['message'];
    http_response_code(400);
  }
} catch (Exception $e) {
  $response['message'] = 'Server error: ' . $e->getMessage();
  http_response_code(500);
}

echo json_encode($response);
exit;
