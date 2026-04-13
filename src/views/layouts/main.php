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
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Library System', ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" href="<?= BASE_URL ?>assets/images/favicon.svg" type="image/svg+xml">

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/libris.css">
