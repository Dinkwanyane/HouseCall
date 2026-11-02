<?php
require_once 'includes/auth.php';
require_once 'includes/validation.php';

if (current_user()) redirect(dashboard_for(current_user()['role']));

$role = $_POST['role'] ?? $_GET['role'] ?? 'client';
if (!in_array($role, ['client', 'provider'], true)) $role = 'client';

$errors = [];
$old = ['name' => '', 'surname' => '', 'username' => '', 'gender' => '', 'age' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    $errors['name']     = v_name($old['name'], 'Name');
    $errors['surname']  = v_name($old['surname'], 'Surname');
    $errors['username'] = v_username($old['username']);
    $errors['password'] = v_password($password);
    if (!$errors['password'] && $password !== $confirm) $errors['confirm'] = 'The two passwords do not match.';
    $errors['gender']   = v_gender($old['gender']);
    $errors['age']      = v_age($old['age']);
    $errors['phone']    = v_phone($old['phone']);

    $cats = [];
    if ($role === 'provider') {
        [$cats, $errors['services']] = services_from_post($_POST);
    }
    $errors = array_filter($errors);

    $pdo = db();
    if (!$errors) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $st->execute([$old['username']]);
        if ($st->fetchColumn()) $errors['username'] = 'That username is already taken. Please choose another.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users (role, username, password_hash, name, surname, gender, age, phone) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$role, $old['username'], password_hash($password, PASSWORD_DEFAULT), $old['name'], $old['surname'], $old['gender'], (int)$old['age'], $old['phone']]);
            $newId = (int)$pdo->lastInsertId();
            if ($role === 'provider') {
                $pdo->prepare('INSERT INTO provider_profiles (user_id) VALUES (?)')->execute([$newId]);
                $ins = $pdo->prepare('INSERT INTO provider_services (user_id, category) VALUES (?, ?)');
                foreach ($cats as $c) $ins->execute([$newId, $c]);
            }
            $pdo->commit();
            flash('success', 'Your account has been created. Please log in.' . ($role === 'provider' ? ' After logging in, complete your profile (rates, areas and working days) so clients can find you.' : ''));
            redirect('login.php?role=' . $role);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['username'] = 'Your account could not be created. Please try a different username.';
        }
    }
}

$selectedCats = $role === 'provider' ? (services_from_post($_POST)[0] ?: array_intersect((array)($_POST['services'] ?? []), ['gardener', 'plumber', 'cleaner'])) : [];
$pageTitle = $role === 'provider' ? 'Service provider sign up' : 'Client sign up';
require_once 'includes/header.php';
?>
<div class="narrow">
  <h1><?= $role === 'provider' ? 'Service provider sign up' : 'Client sign up' ?></h1>
  <p class="hint">Fields marked * are required.
    <?= $role === 'provider' ? '<a href="register.php?role=client">Need a service instead? Sign up as a client.</a>' : '<a href="register.php?role=provider">Offer a service? Sign up as a service provider.</a>' ?></p>
  <form method="post" action="register.php" novalidate class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="role" value="<?= e($role) ?>">
    <div class="field"><label for="name">Name *</label>
      <input type="text" id="name" name="name" data-type="name" maxlength="50" value="<?= e($old['name']) ?>" autocomplete="given-name" required>
      <?= field_error($errors, 'name') ?></div>
    <div class="field"><label for="surname">Surname *</label>
      <input type="text" id="surname" name="surname" data-type="name" maxlength="50" value="<?= e($old['surname']) ?>" autocomplete="family-name" required>
      <?= field_error($errors, 'surname') ?></div>
    <div class="field"><label for="username">Username *</label>
      <input type="text" id="username" name="username" maxlength="20" value="<?= e($old['username']) ?>" autocomplete="username" required>
      <span class="hint">use 4 to 20 letters, numbers or underscores. You will use this to log in.</span>
      <?= field_error($errors, 'username') ?></div>
    <div class="field"><label for="password">Password *</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required>
      <ul class="pw-rules" id="pw-rules">
        <li data-rule="len">At least 8 characters</li>
        <li data-rule="letters">At least 4 letters</li>
        <li data-rule="digits">At least 3 numbers</li>
        <li data-rule="special">At least 1 special character (! # $ % @ ...)</li>
      </ul>
      <?= field_error($errors, 'password') ?></div>
    <div class="field"><label for="confirm">Confirm password *</label>
      <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>
      <?= field_error($errors, 'confirm') ?></div>
    <div class="field"><label for="gender">Gender *</label>
      <select id="gender" name="gender" required>
        <option value="">Please choose</option>
        <?php foreach (GENDERS as $g): ?><option <?= $old['gender'] === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
      </select><?= field_error($errors, 'gender') ?></div>
    <div class="field"><label for="age">Age *</label>
      <input type="text" inputmode="numeric" id="age" name="age" data-type="digits" maxlength="3" value="<?= e($old['age']) ?>" required>
      <?= field_error($errors, 'age') ?></div>
    <div class="field"><label for="phone">Phone number (10 digits) *</label>
      <input type="text" inputmode="numeric" id="phone" name="phone" data-type="digits" maxlength="10" value="<?= e($old['phone']) ?>" autocomplete="tel" required>
      <?= field_error($errors, 'phone') ?></div>
    <?php if ($role === 'provider') include 'includes/services_fields.php'; ?>
    <button class="btn" type="submit">Create my account</button>
    <a class="btn btn-outline" href="index.php">Cancel</a>
  </form>
  <p>Already have an account? <a href="login.php?role=<?= e($role) ?>">Log in</a></p>
</div>
<?php require_once 'includes/footer.php'; ?>