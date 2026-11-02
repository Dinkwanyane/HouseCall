<?php
require_once 'includes/auth.php';
require_once 'includes/validation.php';
require_once 'includes/reports.php';
require_once 'includes/backup.php';

$user = require_role('admin');
$uid  = $user['id'];
$pdo  = db();
$errors = [];

// Backup download (must run before any page output)
if (isset($_GET['download'])) {
    $f = (string)$_GET['download'];
    if (!valid_backup_name($f)) { http_response_code(404); exit('Backup not found.'); }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize(BACKUP_DIR . $f));
    readfile(BACKUP_DIR . $f);
    exit;
}

/* ------------------------------------------------------------------ actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $target = (int)($_POST['user_id'] ?? 0);

    $load = function () use ($pdo, $target) {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$target]);
        return $st->fetch() ?: null;
    };

    if ($action === 'set_status' && ($t = $load())) {
        $new = ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
        if ($t['id'] === $uid) flash('error', 'You cannot change the access of your own account.');
        else {
            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $target]);
            flash('success', $t['username'] . ($new === 'suspended' ? ' has been suspended and can no longer log in.' : ' can log in again.'));
        }
        redirect('admin.php?view=users');
    }

    if ($action === 'reset_password' && ($t = $load())) {
        $pw = (string)($_POST['new_password'] ?? '');
        if ($err = v_password($pw)) flash('error', $err);
        else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $target]);
            flash('success', 'Password reset for ' . $t['username'] . '.');
        }
        redirect('admin.php?view=users');
    }

    if ($action === 'delete_user' && ($t = $load())) {
        $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM bookings WHERE client_id = :i OR provider_id = :i) + (SELECT COUNT(*) FROM ratings WHERE rater_id = :i OR ratee_id = :i)');
        $used->execute([':i' => $target]);
        if ($t['id'] === $uid) flash('error', 'You cannot delete your own account.');
        elseif ((int)$used->fetchColumn() > 0) flash('error', 'This user has bookings or ratings on record, so it cannot be deleted. Suspend the account instead.');
        else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$target]);
            flash('success', 'Account ' . $t['username'] . ' deleted.');
        }
        redirect('admin.php?view=users');
    }

    if ($action === 'add_admin') {
        $d = [];
        foreach (['name', 'surname', 'username', 'gender', 'age', 'phone'] as $k) $d[$k] = trim((string)($_POST[$k] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        $errors = array_filter([
            'name' => v_name($d['name'], 'Name'), 'surname' => v_name($d['surname'], 'Surname'),
            'username' => v_username($d['username']), 'password' => v_password($pw),
            'gender' => v_gender($d['gender']), 'age' => v_age($d['age']), 'phone' => v_phone($d['phone']),
        ]);
        if (!$errors) {
            $dup = $pdo->prepare('SELECT 1 FROM users WHERE username = ?'); $dup->execute([$d['username']]);
            if ($dup->fetchColumn()) $errors['username'] = 'That username is already taken.';
        }
        if (!$errors) {
            $pdo->prepare("INSERT INTO users (role, username, password_hash, name, surname, gender, age, phone) VALUES ('admin',?,?,?,?,?,?,?)")
                ->execute([$d['username'], password_hash($pw, PASSWORD_DEFAULT), $d['name'], $d['surname'], $d['gender'], (int)$d['age'], $d['phone']]);
            flash('success', 'Administrator account created.');
            redirect('admin.php?view=users');
        }
        $_GET['view'] = 'users';
    }

    if ($action === 'backup') {
        try { flash('success', 'Backup created: ' . create_backup($pdo)); }
        catch (Throwable $ex) { flash('error', 'Backup failed: ' . $ex->getMessage()); }
        redirect('admin.php?view=backup');
    }
    if (!isset($_GET['view'])) redirect('admin.php');
}

/* -------------------------------------------------------------------- views */
$views = ['overview' => 'Overview', 'users' => 'Users', 'bookings' => 'Service delivery', 'reports' => 'Reports', 'backup' => 'Backup'];
$view = $_GET['view'] ?? 'overview';
if (!isset($views[$view]) && $view !== 'user') $view = 'overview';
$pageTitle = 'Administration';
require_once 'includes/header.php';

$count = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); })();
?>
<h1>Administration</h1>
<div class="tabs" role="navigation" aria-label="Administrator sections">
  <?php foreach ($views as $k => $l): ?><a href="admin.php?view=<?= $k ?>" class="<?= $view === $k || ($view === 'user' && $k === 'users') ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
</div>

<?php if ($view === 'overview'): ?>
  <div class="grid">
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM users WHERE role='client'") ?></strong>Clients</div>
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM users WHERE role='provider'") ?></strong>Service providers</div>
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM bookings WHERE status='pending'") ?></strong>Bookings waiting for confirmation</div>
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM bookings WHERE status='confirmed'") ?></strong>Confirmed bookings</div>
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM bookings WHERE status='completed'") ?></strong>Services rendered</div>
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM bookings WHERE status='cancelled'") ?></strong>Cancelled bookings</div>
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM users WHERE status='suspended'") ?></strong>Suspended accounts</div>
    <div class="card stat"><strong><?= $count("SELECT COUNT(*) FROM login_logs WHERE success=1 AND DATE(created_at)=CURDATE()") ?></strong>Logins today</div>
  </div>
  <p class="card">Use <a href="admin.php?view=users">Users</a> to manage accounts, <a href="admin.php?view=bookings">Service delivery</a> to track bookings, <a href="admin.php?view=reports">Reports</a> for statistics and <a href="admin.php?view=backup">Backup</a> to save the database.</p>

<?php elseif ($view === 'user'):
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]); $p = $st->fetch();
    if (!$p): ?><p class="card">User not found. <a href="admin.php?view=users">Back to users</a></p>
    <?php else:
        $svc = $pdo->prepare('SELECT category FROM provider_services WHERE user_id = ?'); $svc->execute([$id]);
        $areas = $pdo->prepare('SELECT area FROM provider_areas WHERE user_id = ?'); $areas->execute([$id]);
        $prof = $pdo->prepare('SELECT * FROM provider_profiles WHERE user_id = ?'); $prof->execute([$id]); $prof = $prof->fetch();
        $avg = $pdo->prepare('SELECT ROUND(AVG(stars),1) a, COUNT(*) n FROM ratings WHERE ratee_id = ?'); $avg->execute([$id]); $avg = $avg->fetch();
        $logins = $pdo->prepare('SELECT success, role_selected, reason, created_at FROM login_logs WHERE username = ? ORDER BY id DESC LIMIT 8'); $logins->execute([$p['username']]);
        $bk = $pdo->prepare("SELECT b.*, CONCAT(o.name,' ',o.surname) AS other FROM bookings b JOIN users o ON o.id = IF(b.client_id = ?, b.provider_id, b.client_id)
            WHERE b.client_id = ? OR b.provider_id = ? ORDER BY b.booking_date DESC LIMIT 10"); $bk->execute([$id, $id, $id]);
    ?>
    <p><a href="admin.php?view=users">&larr; Back to users</a></p>
    <div class="card">
      <h2><?= e($p['name'] . ' ' . $p['surname']) ?> <span class="pill pill-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></h2>
      <p>Role: <strong><?= e(ucfirst($p['role'])) ?></strong> · Username: <?= e($p['username']) ?> · Gender: <?= e($p['gender']) ?> · Age: <?= (int)$p['age'] ?> · Phone: <?= e($p['phone']) ?></p>
      <p class="hint">Joined <?= e(date('j M Y', strtotime($p['created_at']))) ?> · Rating: <?= e(stars_text($avg['a'])) ?> (<?= (int)$avg['n'] ?>)</p>
      <?php if ($p['role'] === 'provider'): ?>
        <p>Services: <?= e(implode(', ', array_map(fn($c) => CATEGORIES[$c] ?? $c, $svc->fetchAll(PDO::FETCH_COLUMN))) ?: 'none') ?></p>
        <p>Areas: <?= e(implode(', ', $areas->fetchAll(PDO::FETCH_COLUMN)) ?: 'not set') ?> · Rate: <?= !empty($prof['rate_amount']) ? e(rands($prof['rate_amount']) . ' ' . RATE_TYPES[$prof['rate_type']]) : 'not set' ?></p>
      <?php endif; ?>
    </div>
    <h3>Recent bookings</h3>
    <div class="table-wrap"><table><tr><th>Date</th><th>Service</th><th>With</th><th>Status</th></tr>
      <?php foreach ($bk as $b): ?><tr><td><?= e($b['booking_date']) ?></td><td><?= e(CATEGORIES[$b['category']] ?? $b['category']) ?></td><td><?= e($b['other']) ?></td><td><span class="pill pill-<?= e($b['status']) ?>"><?= e(ucfirst($b['status'])) ?></span></td></tr><?php endforeach; ?></table></div>
    <h3>Recent login attempts</h3>
    <div class="table-wrap"><table><tr><th>When</th><th>Role chosen</th><th>Result</th></tr>
      <?php foreach ($logins as $l): ?><tr><td><?= e($l['created_at']) ?></td><td><?= e($l['role_selected']) ?></td><td><?= $l['success'] ? 'Success' : e('Failed: ' . $l['reason']) ?></td></tr><?php endforeach; ?></table></div>
    <?php endif; ?>

<?php elseif ($view === 'users'):
    $role = $_GET['role'] ?? ''; $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT id, role, username, name, surname, phone, status, created_at FROM users WHERE 1=1'; $args = [];
    if (in_array($role, ['client', 'provider', 'admin'], true)) { $sql .= ' AND role = ?'; $args[] = $role; }
    if ($q !== '') { $sql .= ' AND (username LIKE ? OR name LIKE ? OR surname LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    $st = $pdo->prepare($sql . ' ORDER BY role, surname, name'); $st->execute($args); $users = $st->fetchAll();
?>
  <form method="get" action="admin.php" class="card filters">
    <input type="hidden" name="view" value="users">
    <div class="field"><label for="q">Search name or username</label><input type="text" id="q" name="q" value="<?= e($q) ?>"></div>
    <div class="field"><label for="role">Role</label><select id="role" name="role"><option value="">All roles</option>
      <?php foreach (['client' => 'Clients', 'provider' => 'Service providers', 'admin' => 'Administrators'] as $k => $l): ?><option value="<?= $k ?>" <?= $role === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="field"><button class="btn" type="submit">Search</button></div>
  </form>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Username</th><th>Role</th><th>Phone</th><th>Access</th><th>Actions</th></tr>
    <?php foreach ($users as $u2): ?>
      <tr><td><a href="admin.php?view=user&amp;id=<?= (int)$u2['id'] ?>"><?= e($u2['name'] . ' ' . $u2['surname']) ?></a></td>
        <td><?= e($u2['username']) ?></td><td><?= e(ucfirst($u2['role'])) ?></td><td><?= e($u2['phone']) ?></td>
        <td><span class="pill pill-<?= e($u2['status']) ?>"><?= e(ucfirst($u2['status'])) ?></span></td>
        <td>
          <?php if ((int)$u2['id'] !== $uid): ?>
          <form method="post" action="admin.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="user_id" value="<?= (int)$u2['id'] ?>">
            <input type="hidden" name="status" value="<?= $u2['status'] === 'active' ? 'suspended' : 'active' ?>">
            <button class="btn btn-small <?= $u2['status'] === 'active' ? 'btn-danger' : '' ?>" data-confirm="<?= $u2['status'] === 'active' ? 'Suspend this account?' : 'Allow this account to log in again?' ?>"><?= $u2['status'] === 'active' ? 'Suspend' : 'Activate' ?></button></form>
          <form method="post" action="admin.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= (int)$u2['id'] ?>">
            <button class="btn btn-small btn-danger" data-confirm="Permanently delete this account?">Delete</button></form>
          <?php endif; ?>
          <form method="post" action="admin.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int)$u2['id'] ?>">
            <input type="password" name="new_password" placeholder="New password" aria-label="New password for <?= e($u2['username']) ?>" autocomplete="new-password" style="width:11em" required>
            <button class="btn btn-small btn-outline" style="color:var(--primary-dark);border-color:var(--primary)">Reset password</button></form>
        </td></tr>
    <?php endforeach; ?>
  </table></div>

  <h2>Add an administrator</h2>
  <form method="post" action="admin.php?view=users" class="card narrow" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
    <?php $old = fn($k) => e($_POST[$k] ?? ''); ?>
    <div class="field"><label for="a_name">Name *</label><input type="text" id="a_name" name="name" data-type="name" maxlength="50" value="<?= $old('name') ?>"><?= field_error($errors, 'name') ?></div>
    <div class="field"><label for="a_surname">Surname *</label><input type="text" id="a_surname" name="surname" data-type="name" maxlength="50" value="<?= $old('surname') ?>"><?= field_error($errors, 'surname') ?></div>
    <div class="field"><label for="a_username">Username *</label><input type="text" id="a_username" name="username" maxlength="20" value="<?= $old('username') ?>"><?= field_error($errors, 'username') ?></div>
    <div class="field"><label for="a_password">Password * <span class="hint">(4 letters, 3 numbers, 1 special character)</span></label><input type="password" id="a_password" name="password" autocomplete="new-password"><?= field_error($errors, 'password') ?></div>
    <div class="field"><label for="a_gender">Gender *</label><select id="a_gender" name="gender"><option value="">Please choose</option>
      <?php foreach (GENDERS as $g): ?><option <?= ($_POST['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select><?= field_error($errors, 'gender') ?></div>
    <div class="field"><label for="a_age">Age *</label><input type="text" inputmode="numeric" id="a_age" name="age" data-type="digits" maxlength="3" value="<?= $old('age') ?>"><?= field_error($errors, 'age') ?></div>
    <div class="field"><label for="a_phone">Phone number (10 digits) *</label><input type="text" inputmode="numeric" id="a_phone" name="phone" data-type="digits" maxlength="10" value="<?= $old('phone') ?>"><?= field_error($errors, 'phone') ?></div>
    <button class="btn" type="submit">Create administrator</button>
  </form>

<?php elseif ($view === 'bookings'):
    $status = $_GET['status'] ?? '';
    $sql = "SELECT b.*, CONCAT(c.name,' ',c.surname) AS client, CONCAT(p.name,' ',p.surname) AS provider
            FROM bookings b JOIN users c ON c.id = b.client_id JOIN users p ON p.id = b.provider_id";
    $args = [];
    if (in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) { $sql .= ' WHERE b.status = ?'; $args[] = $status; }
    $st = $pdo->prepare($sql . ' ORDER BY b.booking_date DESC, b.id DESC LIMIT 200'); $st->execute($args); $rows = $st->fetchAll();
?>
  <h2>Track service delivery</h2>
  <form method="get" action="admin.php" class="card filters">
    <input type="hidden" name="view" value="bookings">
    <div class="field"><label for="status">Show</label><select id="status" name="status"><option value="">All bookings</option>
      <?php foreach (['pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Rendered (done)', 'cancelled' => 'Cancelled'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="field"><button class="btn" type="submit">Filter</button></div>
  </form>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Date</th><th>Service</th><th>Area</th><th>Client</th><th>Provider</th><th>Status</th></tr>
    <?php foreach ($rows as $b): ?>
      <tr><td><?= (int)$b['id'] ?></td><td><?= e($b['booking_date']) ?></td><td><?= e(CATEGORIES[$b['category']] ?? $b['category']) ?></td><td><?= e($b['area']) ?></td>
        <td><?= e($b['client']) ?></td><td><?= e($b['provider']) ?></td>
        <td><span class="pill pill-<?= e($b['status']) ?>"><?= e($b['status'] === 'completed' ? 'Rendered' : ucfirst($b['status'])) ?></span><?= $b['cancelled_by'] ? '<br><span class="hint">by ' . e($b['cancelled_by']) . '</span>' : '' ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7">No bookings found.</td></tr><?php endif; ?>
  </table></div>

<?php elseif ($view === 'reports'):
    $from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-90 days')));
    $to   = (string)($_GET['to'] ?? date('Y-m-d', strtotime('+90 days')));
    $badRange = (DateTime::createFromFormat('Y-m-d', $from)?->format('Y-m-d') !== $from) || (DateTime::createFromFormat('Y-m-d', $to)?->format('Y-m-d') !== $to) || $from > $to;
    if ($badRange) { flash('error', 'Please choose a valid date range.'); $from = date('Y-m-d', strtotime('-90 days')); $to = date('Y-m-d', strtotime('+90 days')); echo flash_render(); }
    $R = report_data($pdo, $from, $to);
    $stat = array_column($R['status'], 'n', 'status');
    $rendered = (int)($stat['completed'] ?? 0); $cancelled = (int)($stat['cancelled'] ?? 0);
    $pairs = (int)$R['recurrence_summary']['pairs']; $rep = (int)$R['recurrence_summary']['repeat_pairs'];
?>
  <form method="get" action="admin.php" class="card filters">
    <input type="hidden" name="view" value="reports">
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><button class="btn" type="submit">Update reports</button></div>
    <div class="field"><button class="btn btn-accent" type="button" onclick="window.print()">Print / save as PDF</button></div>
  </form>
  <p class="hint">Reports for <?= e(date('j M Y', strtotime($from))) ?> to <?= e(date('j M Y', strtotime($to))) ?>. Generated <?= e(date('j M Y H:i')) ?>.</p>

  <h2>1. Services rendered vs cancelled</h2>
  <div class="grid">
    <div class="card stat"><strong><?= $rendered ?></strong>Services rendered</div>
    <div class="card stat"><strong><?= $cancelled ?></strong>Bookings cancelled</div>
    <div class="card stat"><strong><?= ($rendered + $cancelled) ? round($rendered / ($rendered + $cancelled) * 100) : 0 ?>%</strong>Rendered (of rendered + cancelled)</div>
  </div>
  <div class="table-wrap"><table><tr><th>Provider</th><th>Rendered</th><th>Cancelled by provider</th><th>Cancelled by client</th><th>Total bookings</th></tr>
    <?php foreach ($R['per_provider'] as $r): ?><tr><td><?= e($r['provider']) ?></td><td><?= (int)$r['rendered'] ?></td><td><?= (int)$r['cancelled_by_provider'] ?></td><td><?= (int)$r['cancelled_by_client'] ?></td><td><?= (int)$r['total'] ?></td></tr>
    <?php endforeach; if (!$R['per_provider']): ?><tr><td colspan="5">No bookings in this period.</td></tr><?php endif; ?></table></div>

  <h2>2. Demand hotspots</h2>
  <div class="grid">
    <div class="card"><h3>Bookings by area</h3><?= bars($R['hotspots'], 'area') ?></div>
    <div class="card"><h3>Bookings by service</h3><?= bars($R['demand_by_service'], 'category', 'n', 'alt', fn($c) => CATEGORIES[$c] ?? $c) ?></div>
  </div>

  <h2>3. Service recurrence</h2>
  <p class="card"><?= $rep ?> of <?= $pairs ?> client and service combinations were booked more than once<?= $pairs ? ' (' . round($rep / $pairs * 100) . '% repeat rate)' : '' ?>.</p>
  <div class="table-wrap"><table><tr><th>Client</th><th>Service</th><th>Times booked</th></tr>
    <?php foreach ($R['recurrence'] as $r): ?><tr><td><?= e($r['client']) ?></td><td><?= e(CATEGORIES[$r['category']] ?? $r['category']) ?></td><td><?= (int)$r['times'] ?></td></tr>
    <?php endforeach; if (!$R['recurrence']): ?><tr><td colspan="3">No repeat bookings in this period.</td></tr><?php endif; ?></table></div>

  <h2>4. Logins</h2>
  <div class="grid">
    <div class="card stat"><strong><?= (int)$R['logins_total']['ok'] ?></strong>Successful logins</div>
    <div class="card stat"><strong><?= (int)$R['logins_total']['failed'] ?></strong>Failed or blocked attempts</div>
  </div>
  <div class="grid">
    <div class="card"><h3>Successful logins by role</h3><?= bars($R['logins_by_role'], 'role', 'n', '', fn($r) => ucfirst($r)) ?></div>
    <div class="card"><h3>Last 14 active days</h3><?= bars($R['logins_by_day'], 'day', 'n', 'alt') ?></div>
  </div>

  <h2>5. Sign-ups</h2>
  <div class="grid">
    <?php $su = array_column($R['signups'], 'n', 'role'); ?>
    <div class="card stat"><strong><?= (int)($su['client'] ?? 0) ?></strong>New clients in this period<br><span class="hint">All time: <?= (int)$R['signups_total']['clients'] ?></span></div>
    <div class="card stat"><strong><?= (int)($su['provider'] ?? 0) ?></strong>New service providers in this period<br><span class="hint">All time: <?= (int)$R['signups_total']['providers'] ?></span></div>
  </div>
  <div class="table-wrap"><table><tr><th>Month</th><th>Clients</th><th>Service providers</th></tr>
    <?php foreach ($R['signups_by_month'] as $r): ?><tr><td><?= e($r['month']) ?></td><td><?= (int)$r['clients'] ?></td><td><?= (int)$r['providers'] ?></td></tr>
    <?php endforeach; if (!$R['signups_by_month']) : ?><tr><td colspan="3">No sign-ups in this period.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'backup'): $backups = list_backups(); ?>
  <h2>System backup</h2>
  <p>A backup saves every table (users, bookings, ratings, notifications, logs) into one SQL file. To restore, import the file in phpMyAdmin. Uploaded pictures live in the <code>uploads</code> folder; copy that folder too for a full backup.</p>
  <form method="post" action="admin.php" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="backup">
    <button class="btn" type="submit">Create backup now</button>
  </form>
  <h3>Saved backups</h3>
  <div class="table-wrap"><table><tr><th>File</th><th>Created</th><th>Size</th><th></th></tr>
    <?php foreach ($backups as $b): ?><tr><td><?= e($b['file']) ?></td><td><?= e(date('j M Y H:i', $b['time'])) ?></td><td><?= e(number_format($b['size'] / 1024, 1)) ?> KB</td>
      <td><a class="btn btn-small" href="admin.php?download=<?= urlencode($b['file']) ?>">Download</a></td></tr>
    <?php endforeach; if (!$backups): ?><tr><td colspan="4">No backups yet.</td></tr><?php endif; ?></table></div>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>