<?php

/**
 * includes/head.php — Shared <head> partial for all Library System pages
 *
 * Usage (root pages):     require_once __DIR__ . '/includes/head.php';
 * Usage (sub-dirs):       require_once __DIR__ . '/../includes/head.php';
 *
 * Variables consumed:
 *   $pageTitle  (string, optional) — page <title>; defaults to 'Library System'
 *   BASE_URL    (constant)        — defined in config.php via db.php
 */
?>
<?php
$extraStyles = isset($extraStyles) && is_array($extraStyles) ? $extraStyles : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];
$includeSweetAlert = array_key_exists('includeSweetAlert', get_defined_vars()) ? (bool) $includeSweetAlert : true;
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Library System', ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" href="<?= BASE_URL ?>assets/images/favicon.svg" type="image/svg+xml">

<?php if ($includeSweetAlert): ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<?php endif; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/libris.css">
<?php foreach ($extraStyles as $styleHref): ?>
  <?php if (is_string($styleHref) && $styleHref !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($styleHref, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
<?php endforeach; ?>

<?php if ($includeSweetAlert): ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
  <script src="<?= BASE_URL ?>assets/js/sweetalert-utils.js" defer></script>
<?php endif; ?>
<script src="<?= BASE_URL ?>assets/js/sidebar-mobile.js" defer></script>
<?php foreach ($extraScripts as $extraScript): ?>
  <?php
  $scriptSrc = '';
  $scriptDefer = true;
  if (is_string($extraScript)) {
    $scriptSrc = $extraScript;
  } elseif (is_array($extraScript)) {
    $scriptSrc = isset($extraScript['src']) && is_string($extraScript['src']) ? $extraScript['src'] : '';
    if (array_key_exists('defer', $extraScript)) {
      $scriptDefer = (bool) $extraScript['defer'];
    }
  }
  ?>
  <?php if ($scriptSrc !== ''): ?>
    <script src="<?= htmlspecialchars($scriptSrc, ENT_QUOTES, 'UTF-8') ?>" <?= $scriptDefer ? ' defer' : '' ?>></script>
  <?php endif; ?>
<?php endforeach; ?>