<?php
// Shared notification list for clients and providers. Expects $pdo and $uid.
$n = $pdo->prepare('SELECT message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$n->execute([$uid]);
$items = $n->fetchAll();
$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
?>
<h2>Notifications</h2>
<?php if (!$items): ?>
  <p class="card">You have no notifications yet.</p>
<?php else: foreach ($items as $i): ?>
  <div class="card notif <?= $i['is_read'] ? '' : 'notif-new' ?>">
    <p><?= e($i['message']) ?></p>
    <p class="hint"><?= e(date('D j M Y, H:i', strtotime($i['created_at']))) ?><?= $i['is_read'] ? '' : ' · NEW' ?></p>
  </div>
<?php endforeach; endif; ?>