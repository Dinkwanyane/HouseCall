<?php
// Step 2: choose a new password (only reachable after forgot_password.php confirmed your identity).
require_once 'includes/auth.php';
require_once 'includes/validation.php';

if (current_user()) redirect(dashboard_for(current_user()['role']));

$r = $_SESSION['reset'] ?? null;
if (!$r || $r['until'] < time()) {
    unset($_SESSION['reset']);
    flash('error', 'Your reset session has expired. Please start again.');
    redirect('forgot_password.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');
    $errors['password'] = v_password($password);
    if (!$errors['password'] && $password !== $confirm) $errors['confirm'] = 'The two passwords do not match.';

    if (!array_filter($errors)) {
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role IN ('client','provider')")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $r['id']]);
        notify(db(), (int)$r['id'], 'Your password was changed. If this was not you, please contact the administrator.');
        log_password_change(db(), (int)$r['id'], 'self_reset');
        unset($_SESSION['reset']);
        session_regenerate_id(true);
        flash('success', 'Your password has been changed. You can now log in.');
        redirect('login.php');
    }
}

$pageTitle = 'Choose a new password';
require_once 'includes/header.php';
?>
<div class="narrow">
  <h1>Choose a new password</h1>
  <form method="post" action="reset_password.php" class="card" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="password">New password *</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required>
      <ul class="pw-rules" id="pw-rules">
        <li data-rule="len">At least 8 characters</li>
        <li data-rule="letters">At least 4 letters</li>
        <li data-rule="digits">At least 3 numbers</li>
        <li data-rule="special">At least 1 special character (! # $ % @ ...)</li>
      </ul>
      <?= field_error($errors, 'password') ?></div>
    <div class="field"><label for="confirm">Confirm new password *</label>
      <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>
      <?= field_error($errors, 'confirm') ?></div>
    <button class="btn" type="submit">Save new password</button>
  </form>
</div>
<?php require_once 'includes/footer.php'; ?>