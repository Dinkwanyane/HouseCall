<?php
require_once 'includes/auth.php';

if (current_user()) redirect(dashboard_for(current_user()['role']));

$roles = ['client' => 'Client', 'provider' => 'Service provider', 'admin' => 'Administrator'];
$role  = $_POST['role'] ?? $_GET['role'] ?? '';
$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!isset($roles[$role])) {
        $error = 'Please choose which role you are logging in as.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } elseif (recent_failures($username) >= 5) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $st = db()->prepare('SELECT id, role, name, password_hash, status FROM users WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            log_login($row['id'] ?? null, $username, $role, false, 'bad credentials');
            $error = 'Incorrect username or password.';
        } elseif ($row['status'] !== 'active') {
            log_login((int)$row['id'], $username, $role, false, 'suspended');
            $error = 'Your account has been suspended. Please contact the administrator.';
        } elseif ($row['role'] !== $role) {
            // Valid credentials, but the user picked a role that is not theirs.
            log_login((int)$row['id'], $username, $role, false, 'unauthorized role');
            unauthorized_access('login.php');
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$row['id'], 'role' => $row['role'], 'name' => $row['name']];
            log_login((int)$row['id'], $username, $role, true);
            redirect(dashboard_for($row['role']));
        }
    }
}

$pageTitle = 'Log in';
require_once 'includes/header.php';
?>
<div class="narrow">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="login.php" class="card">
    <?= csrf_field() ?>
    <fieldset>
      <legend>Step 1: I am a...</legend>
      <div class="role-pick">
        <?php foreach ($roles as $k => $label): ?>
          <label><input type="radio" name="role" value="<?= $k ?>" <?= $role === $k ? 'checked' : '' ?> required><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <p><strong>Step 2: Enter your details</strong></p>
    <div class="field"><label for="username">Username</label>
      <input type="text" id="username" name="username" maxlength="50" value="<?= e($username) ?>" autocomplete="username" required></div>
    <div class="field"><label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required></div>
    <button class="btn" type="submit">Log in</button>
  </form>
  <p><a href="forgot_password.php">Forgot your password?</a></p>
  <p>New to HouseCall? <a href="register.php?role=client">Sign up as a client</a> or <a href="register.php?role=provider">as a service provider</a>.</p>
</div>
<?php require_once 'includes/footer.php'; ?>