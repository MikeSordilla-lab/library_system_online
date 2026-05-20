<?php

$allowed_roles = ["admin"];
require_once __DIR__ . "/../includes/auth_guard.php";
require_once __DIR__ . "/../includes/avatar.php";

$current_page = "admin.about";
$pageTitle = "Admin Profile | LibraLink";

$escape = fn($v) => htmlspecialchars($v, ENT_QUOTES, "UTF-8");

$actor_id = (int) ($_SESSION["user_id"] ?? 0);

$admin = [
  "name" => (string) ($_SESSION["full_name"] ?? "Administrator"),
  "role" => "Administrator",
  "status" => "Active",
  "email" => "",
  "location" => "Tangub City, Philippines",
  "joined" => "",
  "bio" =>
    "Responsible for managing system users, monitoring activity, and maintaining smooth library operations.",
  "permissions" => "Administrative Access",
  "avatar_url" => null,
];

try {
  $pdo = get_db();
  $stmt = $pdo->prepare(
    "SELECT full_name, email, role, is_suspended, is_superadmin, created_at, avatar_url FROM Users WHERE id = ? LIMIT 1",
  );
  $stmt->execute([$actor_id]);
  $row = $stmt->fetch();

  if ($row) {
    $admin["name"] = (string) ($row["full_name"] ?: $admin["name"]);
    $admin["email"] = (string) ($row["email"] ?? "");
    $admin["role"] = ucfirst((string) ($row["role"] ?? "admin"));
    $admin["status"] =
      (int) ($row["is_suspended"] ?? 0) === 1 ? "Suspended" : "Active";
    $admin["joined"] = !empty($row["created_at"])
      ? date("F Y", strtotime((string) $row["created_at"]))
      : "";
    $admin["permissions"] =
      (int) ($row["is_superadmin"] ?? 0) === 1
        ? "Superadmin Access"
        : "Administrative Access";
    $admin["avatar_url"] = !empty($row["avatar_url"])
      ? (string) $row["avatar_url"]
      : null;
  }
} catch (PDOException $e) {
  error_log("[admin/about.php] Profile load DB error: " . $e->getMessage());
}

$avatar = get_avatar_display($admin["avatar_url"], $admin["name"]);
$initials =
  $avatar["initials"] !== ""
    ? $avatar["initials"]
    : strtoupper(substr($admin["name"], 0, 1));
$is_active = $admin["status"] === "Active";

$skills = ["PHP", "MySQL", "CSS"];

$stats = [
  [
    "label" => "Total Users",
    "value" => "—",
    "stripe" => "gold",
    "icon" =>
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  ],
  [
    "label" => "Active Loans",
    "value" => "—",
    "stripe" => "blue",
    "icon" =>
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
  ],
  [
    "label" => "Pending Reservations",
    "value" => "—",
    "stripe" => "sage",
    "icon" =>
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>',
  ],
  [
    "label" => "Overdue Books",
    "value" => "—",
    "stripe" => "red",
    "icon" =>
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
  ],
  [
    "label" => "System Status",
    "value" => "Active",
    "stripe" => "sage",
    "icon" =>
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
  ],
  [
    "label" => "Library Hours",
    "value" => "8AM–5PM",
    "stripe" => "blue",
    "icon" =>
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  ],
];

try {
  $pdo = get_db();
  $stmt = $pdo->query(
    "SELECT
      (SELECT COUNT(*) FROM Users) AS total_users,
      (SELECT COUNT(*) FROM Circulation WHERE status = 'active') AS active_loans,
      (SELECT COUNT(*) FROM Reservations WHERE status = 'pending') AS pending_reservations,
      (SELECT COUNT(*) FROM Circulation WHERE status = 'overdue') AS overdue_books"
  );
  $summary = $stmt->fetch() ?: [];

  $stats[0]["value"] = number_format((int) ($summary["total_users"] ?? 0));
  $stats[1]["value"] = number_format((int) ($summary["active_loans"] ?? 0));
  $stats[2]["value"] = number_format((int) ($summary["pending_reservations"] ?? 0));
  $stats[3]["value"] = number_format((int) ($summary["overdue_books"] ?? 0));
} catch (PDOException $e) {
  error_log("[admin/about.php] Stats load DB error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <?php
  $extraStyles = [
    "https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap",
    BASE_URL . "assets/css/borrower-redesign.css",
    BASE_URL . "assets/css/admin-redesign.css",
  ];
  require_once __DIR__ . "/../includes/head.php";
  ?>
  <style>
    .profile-grid {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 32px;
      align-items: start;
      padding: 24px;
    }
    .profile-avatar-col {
      text-align: center;
      flex-shrink: 0;
    }
    .profile-info-col h2 {
      margin: 0 0 8px;
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--rd-text-bold);
    }
    .profile-actions {
      margin-top: 20px;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
    @media (max-width: 600px) {
      .profile-grid {
        grid-template-columns: 1fr;
        gap: 20px;
        padding: 20px 16px;
        text-align: center;
      }
      .profile-avatar-col {
        display: flex;
        flex-direction: column;
        align-items: center;
      }
      .profile-info-col h2 {
        font-size: 1.35rem;
      }
      .profile-actions {
        justify-content: center;
      }
    }
  </style>
</head>

<body class="admin-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . "/../includes/sidebar-admin.php"; ?>

    <main class="main-content admin-about-page">
      <div class="page-header">
        <h1>Admin Profile</h1>
        <p class="page-header__lede">Manage your account identity, security actions, and access details.</p>
      </div>

      <section class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Profile Details</span>
        </div>
        <div class="profile-grid">
          <div class="profile-avatar-col">
            <?php if ($avatar["type"] === "image" && !empty($avatar["url"])): ?>
              <img src="<?= $escape($avatar["url"]) ?>" alt="<?= $escape(
  $admin["name"],
) ?> profile photo" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--rd-primary); box-shadow: 0 8px 24px rgba(201, 168, 76, 0.2);">
            <?php else: ?>
              <div style="width: 120px; height: 120px; border-radius: 50%; background: linear-gradient(135deg, var(--rd-primary), #b8942d); display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: 700; color: #fff; box-shadow: 0 8px 24px rgba(201, 168, 76, 0.2);"><?= $escape(
                $initials,
              ) ?></div>
            <?php endif; ?>
            <p style="margin-top: 12px; font-size: 14px; color: var(--rd-text-muted); font-weight: 600;"><?= $escape(
              $admin["role"],
            ) ?></p>
            <?php if (!empty($admin["joined"])): ?>
            <p style="margin-top: 4px; font-size: 12px; color: var(--rd-text-muted);">Since <?= $escape(
              $admin["joined"],
            ) ?></p>
            <?php endif; ?>
          </div>
          <div class="profile-info-col">
            <h2><?= $escape($admin["name"]) ?></h2>
            <p style="margin: 0 0 12px; color: var(--rd-text); font-size: 0.95rem;"><?= $escape(
              $admin["email"],
            ) ?></p>
            <span style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; <?= $is_active
              ? "background: rgba(34, 197, 94, 0.12); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.25);"
              : "background: rgba(239, 68, 68, 0.12); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.25);" ?>">
              <span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor;"></span>
              <?= $escape($admin["status"]) ?>
            </span>
            <p style="margin-top: 16px; color: var(--rd-text); line-height: 1.6; font-size: 0.95rem;"><?= $escape(
              $admin["bio"],
            ) ?></p>
            <div class="profile-actions">
              <a href="<?= BASE_URL ?>admin/change-password.php" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; background: linear-gradient(135deg, var(--rd-primary), #b8942d); color: #fff; text-decoration: none; font-weight: 600; box-shadow: 0 4px 12px rgba(201, 168, 76, 0.25);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2z"/><circle cx="12" cy="15" r="1.5"/></svg>
                Change Password
              </a>
              <a href="<?= BASE_URL ?>admin/users.php" class="btn-ghost" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--rd-border); color: var(--rd-text); text-decoration: none; font-weight: 600;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Manage Users
              </a>
            </div>
          </div>
        </div>
      </section>

      <section class="stat-grid" style="margin-top: 24px;">
        <?php foreach ($stats as $stat): ?>
          <div class="stat-card">
            <div class="stat-stripe stat-stripe--<?= $escape(
              $stat["stripe"],
            ) ?>"></div>
            <div class="stat-icon stat-icon--<?= $escape($stat["stripe"]) ?>">
              <?= $stat["icon"] ?>
            </div>
            <div class="stat-value"><?= $escape($stat["value"]) ?></div>
            <div class="stat-label"><?= $escape($stat["label"]) ?></div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="section-card" style="margin-top: 24px;">
        <div class="section-card__header">
          <span class="section-card__title">About Developer</span>
        </div>
        <div style="padding: 20px;">
          <p style="margin: 0 0 16px; color: var(--rd-text); line-height: 1.7; font-size: 0.95rem;">
            The admin profile experience is maintained to keep operations reliable, secure, and easy to review for everyday library workflows.
          </p>
          <div style="display: flex; gap: 8px; flex-wrap: wrap; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.06);">
            <?php foreach ($skills as $skill): ?>
              <span style="background: rgba(201, 168, 76, 0.1); border: 1px solid var(--rd-border); color: var(--rd-primary); border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 600;"><?= $escape(
                $skill,
              ) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script>
    (() => {
      const baseUrl = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
    })();
  </script>

</body>

</html>
