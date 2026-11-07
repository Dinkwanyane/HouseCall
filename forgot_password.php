<?php
// Step 1 of resetting a forgotten password: prove who you are with your username and the phone number
// you registered with. HouseCall stores no e-mail address, so the phone number is the second proof.
require_once 'includes/auth.php';
require_once 'includes/validation.php';

if (current_user()) redirect(dashboard_for(current_user()['role']));

$error = '';
$username = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $phone    = trim((string)($_POST['phone'] ?? ''));
    $key      = 'reset:' . $username;   // separate counter, so strangers cannot lock someone out of logging in

    if ($username === '' || $phone === '') {
        $error = 'Please enter your username and your phone number.';
    } elseif (recent_failures($key) >= 5) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $st = db()->prepare("SELECT id, role, phone, status FROM users WHERE username = ? AND role IN ('client','provider')");
        $st->execute([$username]);
        $row = $st->fetch();

        if (!$row || !hash_equals((string)$row['phone'], $phone)) {
            log_login($row['id'] ?? null, $key, $row['role'] ?? 'client', false, 'reset: details did not match');
            $error = 'Those details do not match any account. Check your username and phone number and try again.';
        } elseif ($row['status'] !== 'active') {
            $error = 'Your account has been suspended. Please contact the administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['reset'] = ['id' => (int)$row['id'], 'until' => time() + 600];   // valid for 10 minutes
            log_login((int)$row['id'], $key, $row['role'], true, 'reset: identity confirmed');
            redirect('reset_password.php');
        }
    }
}

$pageTitle = 'Forgot password';
require_once 'includes/header.php';
?>
<div class="narrow">
  <h1>Forgot your password?</h1>
  <p>Enter your username and the phone number you signed up with. If they match, you can choose a new password.</p>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="forgot_password.php" class="card">
    <?= csrf_field() ?>
    <div class="field"><label for="username">Username</label>
      <input type="text" id="username" name="username" maxlength="50" value="<?= e($username) ?>" autocomplete="username" required></div>
    <div class="field"><label for="phone">Phone number</label>
      <input type="text" inputmode="numeric" id="phone" name="phone" data-type="digits" maxlength="10" value="<?= e($phone) ?>" autocomplete="tel" required>
      <span class="hint">10 digits, for example 0821234567.</span></div>
    <button class="btn" type="submit">Continue</button>
  </form>
  <p>Administrators cannot use this page. Another administrator can reset an administrator password.</p>
  <p><a href="login.php">Back to log in</a></p>
</div>
<?php require_once 'includes/footer.php'; ?>