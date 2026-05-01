<?php

/**
 * includes/avatar.php — Avatar Management (Phase 2)
 *
 * Handles avatar uploads, validation, deletion, and retrieval
 * Avatars are stored in assets/avatars/ directory
 */

if (!defined('AVATAR_UPLOAD_DIR')) {
  define('AVATAR_UPLOAD_DIR', __DIR__ . '/../assets/avatars/');
}
if (!defined('AVATAR_MAX_SIZE')) {
  define('AVATAR_MAX_SIZE', 5 * 1024 * 1024); // 5MB
}
if (!defined('AVATAR_ALLOWED_TYPES')) {
  define('AVATAR_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
}
if (!defined('AVATAR_ALLOWED_EXTENSIONS')) {
  define('AVATAR_ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
}
if (!defined('AVATAR_BASE_URL')) {
  define('AVATAR_BASE_URL', BASE_URL . 'assets/avatars/');
}

/**
 * Validate uploaded avatar file using proper security checks
 * @param array $file $_FILES element for the upload
 * @return array ['success' => bool, 'error' => string|null, 'message' => string|null]
 */
function validate_avatar_upload($file)
{
  if (!isset($file['tmp_name']) || !isset($file['size']) || !isset($file['name'])) {
    return ['success' => false, 'error' => 'Invalid file upload'];
  }

  // Check file size
  if ($file['size'] > AVATAR_MAX_SIZE) {
    return ['success' => false, 'error' => 'Avatar image too large (max 5MB)'];
  }

  // Validate file extension against whitelist (prevents .php.jpg attacks)
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, AVATAR_ALLOWED_EXTENSIONS, true)) {
    return ['success' => false, 'error' => 'Invalid file extension. Allowed: JPEG, PNG, GIF, WebP'];
  }

  // Validate MIME type using finfo_file (server-side validation, not client-provided)
  // This prevents relying on $_FILES['type'] which is controlled by the client
  if (!function_exists('finfo_file')) {
    return ['success' => false, 'error' => 'Server configuration error'];
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mime, AVATAR_ALLOWED_TYPES, true)) {
    return ['success' => false, 'error' => 'Invalid image type detected. Allowed: JPEG, PNG, GIF, WebP'];
  }

  // Verify file is a valid image (getimagesize without error suppression)
  // This also prevents polyglot attacks (e.g., valid JPEG that's also executable)
  $image_info = getimagesize($file['tmp_name']);
  if ($image_info === false) {
    return ['success' => false, 'error' => 'File is not a valid image or is corrupted'];
  }

  // Check dimensions (min 100x100, max 2000x2000)
  if ($image_info[0] < 100 || $image_info[1] < 100 || $image_info[0] > 2000 || $image_info[1] > 2000) {
    return ['success' => false, 'error' => 'Image dimensions must be between 100x100 and 2000x2000 pixels'];
  }

  return ['success' => true, 'message' => 'Avatar validation passed'];
}

/**
 * Save uploaded avatar file
 * @param array $file $_FILES element
 * @param int $user_id User ID for filename uniqueness
 * @return array ['success' => bool, 'filename' => string|null, 'error' => string|null]
 */
function save_avatar_upload($file, $user_id)
{
  // Validate first
  $validation = validate_avatar_upload($file);
  if (!$validation['success']) {
    return ['success' => false, 'error' => $validation['error']];
  }

  // Create avatars directory if not exists
  if (!is_dir(AVATAR_UPLOAD_DIR)) {
    @mkdir(AVATAR_UPLOAD_DIR, 0755, true);
  }

  // Generate unique filename: avatar_<user_id>_<timestamp>.<ext>
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
  $filepath = AVATAR_UPLOAD_DIR . $filename;

  // Move uploaded file
  if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    return ['success' => false, 'error' => 'Failed to save avatar file'];
  }

  // Set file permissions
  chmod($filepath, 0644);

  return ['success' => true, 'filename' => $filename];
}

/**
 * Delete old avatar file and return new avatar URL
 * @param int $user_id User ID
 * @param string|null $old_avatar_filename Old avatar filename to delete
 * @param string $new_filename New avatar filename to use
 * @return string Avatar URL for database storage
 */
function update_user_avatar($user_id, $old_avatar_filename, $new_filename)
{
  // Delete old avatar if exists
  if ($old_avatar_filename) {
    $old_path = AVATAR_UPLOAD_DIR . $old_avatar_filename;
    if (file_exists($old_path)) {
      @unlink($old_path);
    }
  }

  // Return relative path for database storage
  return 'assets/avatars/' . $new_filename;
}

/**
 * Get avatar URL for display
 * @param string|null $avatar_url Database avatar_url value
 * @param string $full_name User's full name for fallback initials
 * @return array ['type' => 'image'|'initials', 'url' => string|null, 'initials' => string]
 */
function get_avatar_display($avatar_url, $full_name)
{
  $initials = '';
  if ($full_name) {
    $parts = explode(' ', trim($full_name));
    $parts = array_filter($parts);
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
  }

  if ($avatar_url) {
    $full_path = AVATAR_BASE_URL . basename($avatar_url);
    return ['type' => 'image', 'url' => $full_path, 'initials' => $initials];
  }

  return ['type' => 'initials', 'url' => null, 'initials' => $initials];
}

/**
 * Delete user avatar
 * @param string|null $avatar_url Avatar URL from database
 * @return bool
 */
function delete_user_avatar($avatar_url)
{
  if (!$avatar_url) {
    return true;
  }

  $filepath = AVATAR_UPLOAD_DIR . basename($avatar_url);
  if (file_exists($filepath)) {
    return @unlink($filepath);
  }

  return true;
}

/**
 * Process avatar upload from form
 * @param int $user_id User ID
 * @param string|null $current_avatar Current avatar URL in database
 * @param array|null $file $_FILES['avatar'] if upload attempted
 * @param PDO $pdo Database connection
 * @return array ['success' => bool, 'message' => string, 'avatar_url' => string|null]
 */
function process_avatar_upload($user_id, $current_avatar, $file, $pdo)
{
  // Check if file was uploaded
  if (!$file || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
    return ['success' => false, 'message' => 'No file selected'];
  }

  // Validate upload
  $validation = validate_avatar_upload($file);
  if (!$validation['success']) {
    return ['success' => false, 'message' => $validation['error']];
  }

  // Save new avatar
  $save_result = save_avatar_upload($file, $user_id);
  if (!$save_result['success']) {
    return ['success' => false, 'message' => $save_result['error']];
  }

  // Update database
  $new_avatar_url = update_user_avatar($user_id, basename($current_avatar), $save_result['filename']);

  try {
    $stmt = $pdo->prepare('UPDATE Users SET avatar_url = ? WHERE id = ?');
    $stmt->execute([$new_avatar_url, $user_id]);

    return [
      'success' => true,
      'message' => 'Avatar uploaded successfully',
      'avatar_url' => $new_avatar_url
    ];
  } catch (Exception $e) {
    // Cleanup uploaded file on DB error
    @unlink(AVATAR_UPLOAD_DIR . $save_result['filename']);
    return ['success' => false, 'message' => 'Failed to save avatar'];
  }
}
