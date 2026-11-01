<?php
require_once __DIR__ . '/auth.php';
$u = current_user();
$unread = 0;
if ($u) {
    $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([$u['id']]);
    $unread = (int)$s->fetchColumn();
}
$pageTitle = $pageTitle ?? 'HouseCall';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> | HouseCall</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<a class="skip" href="#content">Skip to main content</a>
<header class="site-header">
  <div class="wrap header-row">
    <a class="brand" href="index.php">🏠 HouseCall</a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="main-nav">Menu</button>
    <nav id="main-nav" aria-label="Main">
      <a href="index.php">Home</a>
      <a href="about.php">About</a>
      <?php if (!$u || $u['role'] === 'client'): ?>
        <a href="<?= $u ? 'client.php' : 'login.php?role=client' ?>">Make a booking</a>
      <?php endif; ?>
      <?php if ($u): ?>
        <a href="<?= e(dashboard_for($u['role'])) ?>">My dashboard</a>
        <?php if ($u['role'] !== 'admin'): ?>
          <a href="<?= e(dashboard_for($u['role'])) ?>?view=notifications">Notifications<?= $unread ? ' <span class="badge">' . $unread . '</span>' : '' ?></a>
        <?php endif; ?>
        <a class="btn btn-small btn-outline" href="logout.php">Log out (<?= e($u['name']) ?>)</a>
      <?php else: ?>
        <a href="login.php">Log in</a>
        <a class="btn btn-small" href="index.php#join">Sign up</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main id="content" class="wrap">
<?= flash_render() ?>