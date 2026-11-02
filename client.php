<?php
require_once 'includes/auth.php';
require_once 'includes/validation.php';

$user = require_role('client');
$uid  = $user['id'];
$pdo  = db();
$errors = [];

/* ------------------------------------------------------------------ actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'book') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $category   = (string)($_POST['category'] ?? '');
        $area       = (string)($_POST['area'] ?? '');
        $date       = (string)($_POST['date'] ?? '');
        $desc       = trim((string)($_POST['description'] ?? ''));

        $errors['date'] = v_date($date);
        $errors['description'] = $desc === '' ? null : v_text($desc, 'Description', 1, 1000);
        if (!isset(CATEGORIES[$category])) $errors['category'] = 'Please choose a service.';
        if (!in_array($area, AREAS, true)) $errors['area'] = 'Please choose your area.';

        $files = array_slice(normalise_files($_FILES['images'] ?? []), 0, 3);
        $saved = [];

        if (!array_filter($errors)) {
            $chk = $pdo->prepare("SELECT u.id FROM users u
                JOIN provider_services ps ON ps.user_id = u.id AND ps.category = ?
                JOIN provider_areas pa ON pa.user_id = u.id AND pa.area = ?
                WHERE u.id = ? AND u.role = 'provider' AND u.status = 'active'");
            $chk->execute([$category, $area, $providerId]);
            if (!$chk->fetchColumn()) $errors['provider'] = 'That service provider does not offer this service in your area.';
        }
        if (!array_filter($errors)) {
            [$free, $why] = provider_availability($pdo, $providerId, $date);
            if (!$free) $errors['provider'] = 'Sorry, this service provider is not available on that day (' . $why . '). Please choose another day or provider.';
        }
        if (!array_filter($errors)) {
            $dup = $pdo->prepare("SELECT 1 FROM bookings WHERE client_id = ? AND provider_id = ? AND booking_date = ? AND status IN ('pending','confirmed')");
            $dup->execute([$uid, $providerId, $date]);
            if ($dup->fetchColumn()) $errors['provider'] = 'You already have a booking with this provider on that day.';
        }
        if (!array_filter($errors)) {
            foreach ($files as $f) {
                $name = upload_image($f, $err);
                if ($err) { $errors['images'] = $err; break; }
                if ($name) $saved[] = $name;
            }
        }
        if (!array_filter($errors)) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO bookings (client_id, provider_id, category, area, booking_date, description) VALUES (?,?,?,?,?,?)')
                ->execute([$uid, $providerId, $category, $area, $date, $desc]);
            $bid = (int)$pdo->lastInsertId();
            $ii = $pdo->prepare('INSERT INTO booking_images (booking_id, image_path) VALUES (?, ?)');
            foreach ($saved as $s) $ii->execute([$bid, $s]);
            notify($pdo, $providerId, "New booking request from {$user['name']} for " . date('D j M Y', strtotime($date)) . ' (' . CATEGORIES[$category] . ', ' . $area . '). Please confirm or decline.');
            $pdo->commit();
            flash('success', 'Your booking request has been sent. You will be notified when the service provider confirms.');
            redirect('client.php?view=bookings');
        }
        foreach ($saved as $s) @unlink(UPLOAD_DIR . $s);
        $_GET['view'] = 'book';
        $_GET['provider'] = $providerId;
        $_GET['category'] = $category;
        $_GET['area'] = $area;
        $_GET['date'] = $date;
    }

    if ($action === 'cancel') {
        $bid = (int)($_POST['booking_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND client_id = ? AND status IN ('pending','confirmed')");
        $st->execute([$bid, $uid]);
        if ($b = $st->fetch()) {
            $pdo->prepare("UPDATE bookings SET status='cancelled', cancelled_by='client' WHERE id = ?")->execute([$bid]);
            notify($pdo, (int)$b['provider_id'], "{$user['name']} cancelled the booking for " . date('D j M Y', strtotime($b['booking_date'])) . '.');
            flash('success', 'Your booking has been cancelled.');
        }
        redirect('client.php?view=bookings');
    }

    if ($action === 'rate') {
        $bid   = (int)($_POST['booking_id'] ?? 0);
        $stars = (string)($_POST['stars'] ?? '');
        $rev   = trim((string)($_POST['review'] ?? ''));
        $st = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND client_id = ? AND status = 'completed'");
        $st->execute([$bid, $uid]);
        $b = $st->fetch();
        $e1 = !preg_match('/^[1-5]$/', $stars) ? 'Please choose 1 to 5 stars.' : null;
        $e2 = v_text($rev, 'Review', 3, 500);
        if (!$b) flash('error', 'That booking cannot be rated.');
        elseif ($e1 || $e2) flash('error', $e1 ?: $e2);
        else {
            try {
                $pdo->prepare('INSERT INTO ratings (booking_id, rater_id, ratee_id, stars, review) VALUES (?,?,?,?,?)')
                    ->execute([$bid, $uid, $b['provider_id'], (int)$stars, $rev]);
                notify($pdo, (int)$b['provider_id'], "{$user['name']} rated you {$stars} star(s).");
                flash('success', 'Thank you! Your rating has been saved.');
            } catch (PDOException $ex) {
                flash('error', 'You have already rated this booking.');
            }
        }
        redirect('client.php?view=bookings');
    }
}

/* -------------------------------------------------------------------- views */
$view = $_GET['view'] ?? 'find';
if (!in_array($view, ['find', 'book', 'bookings', 'notifications'], true)) $view = 'find';
$pageTitle = 'Client dashboard';
require_once 'includes/header.php';
?>
<h1>Hello, <?= e($user['name']) ?></h1>
<div class="tabs" role="navigation" aria-label="Client sections">
  <a href="client.php?view=find" class="<?= $view === 'find' || $view === 'book' ? 'active' : '' ?>">Find a service provider</a>
  <a href="client.php?view=bookings" class="<?= $view === 'bookings' ? 'active' : '' ?>">My bookings</a>
  <a href="client.php?view=notifications" class="<?= $view === 'notifications' ? 'active' : '' ?>">Notifications<?= $unread ? " ($unread)" : '' ?></a>
</div>

<?php if ($view === 'notifications'): include 'includes/notifications_view.php'; ?>

<?php elseif ($view === 'bookings'):
    $st = $pdo->prepare("SELECT b.*, CONCAT(p.name,' ',p.surname) AS provider_name, p.phone AS provider_phone,
        (SELECT COUNT(*) FROM ratings r WHERE r.booking_id = b.id AND r.rater_id = ?) AS rated
        FROM bookings b JOIN users p ON p.id = b.provider_id WHERE b.client_id = ? ORDER BY b.booking_date DESC, b.id DESC");
    $st->execute([$uid, $uid]);
    $rows = $st->fetchAll();
?>
  <h2>My bookings</h2>
  <?php if (!$rows): ?><p class="card">You have no bookings yet. <a href="client.php">Find a service provider</a>.</p><?php endif; ?>
  <?php foreach ($rows as $b):
      $imgs = $pdo->prepare('SELECT image_path FROM booking_images WHERE booking_id = ?');
      $imgs->execute([$b['id']]);
  ?>
    <div class="card">
      <h3><?= e(CATEGORIES[$b['category']]) ?>: <?= e($b['provider_name']) ?>
        <span class="pill pill-<?= e($b['status']) ?>"><?= e(ucfirst($b['status'])) ?></span></h3>
      <p><strong><?= e(date('l j F Y', strtotime($b['booking_date']))) ?></strong> · <?= e($b['area']) ?></p>
      <?php if ($b['status'] === 'confirmed'): ?><p>Provider phone: <?= e($b['provider_phone']) ?></p><?php endif; ?>
      <?php if ($b['description']): ?><p><?= nl2br(e($b['description'])) ?></p><?php endif; ?>
      <div class="thumbs"><?php foreach ($imgs as $im): ?><a href="uploads/<?= e($im['image_path']) ?>" target="_blank" rel="noopener"><img src="uploads/<?= e($im['image_path']) ?>" alt="Picture you attached"></a><?php endforeach; ?></div>
      <?php if ($b['status'] === 'cancelled'): ?><p class="hint">Cancelled by <?= e($b['cancelled_by']) ?>.</p><?php endif; ?>
      <div class="btn-row">
      <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?>
        <form method="post" action="client.php"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-danger btn-small" data-confirm="Cancel this booking?">Cancel booking</button></form>
      <?php endif; ?>
      </div>
      <?php if ($b['status'] === 'completed' && !$b['rated']): ?>
        <form method="post" action="client.php" class="card">
          <?= csrf_field() ?><input type="hidden" name="action" value="rate"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
          <h3>Rate <?= e($b['provider_name']) ?></h3>
          <div class="star-pick" role="radiogroup" aria-label="Stars">
            <?php for ($i = 1; $i <= 5; $i++): ?><label><input type="radio" name="stars" value="<?= $i ?>" required><span><?= $i ?> ★</span></label><?php endfor; ?>
          </div>
          <div class="field"><label for="rv<?= (int)$b['id'] ?>">Your review</label>
            <textarea id="rv<?= (int)$b['id'] ?>" name="review" maxlength="500" required></textarea></div>
          <button class="btn btn-accent" type="submit">Submit rating</button>
        </form>
      <?php elseif ($b['status'] === 'completed'): ?><p class="hint">You have rated this service. ✔</p><?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($view === 'book'):
    $pid = (int)($_GET['provider'] ?? 0);
    $category = (string)($_GET['category'] ?? '');
    $area = (string)($_GET['area'] ?? '');
    $date = (string)($_GET['date'] ?? date('Y-m-d', strtotime('+1 day')));
    $st = $pdo->prepare("SELECT u.id, u.name, u.surname, pp.rate_amount, pp.rate_type, pp.bio FROM users u
        LEFT JOIN provider_profiles pp ON pp.user_id = u.id WHERE u.id = ? AND u.role='provider' AND u.status='active'");
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p || !isset(CATEGORIES[$category]) || !in_array($area, AREAS, true)): ?>
      <p class="card">Please <a href="client.php">choose a service provider</a> first.</p>
    <?php else:
        [$free, $why] = v_date($date) ? [false, 'Choose a valid date'] : provider_availability($pdo, $pid, $date);
    ?>
    <div class="narrow">
      <h2>Book <?= e($p['name'] . ' ' . $p['surname']) ?></h2>
      <div class="card">
        <p><?= e(CATEGORIES[$category]) ?> · <?= e($area) ?><?= $p['rate_amount'] ? ' · ' . e(rands($p['rate_amount'])) . ' ' . e(RATE_TYPES[$p['rate_type']]) : '' ?></p>
        <?= field_error($errors, 'provider') ?>
      </div>
      <form method="post" action="client.php" enctype="multipart/form-data" class="card" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="book">
        <input type="hidden" name="provider_id" value="<?= $pid ?>">
        <input type="hidden" name="category" value="<?= e($category) ?>">
        <input type="hidden" name="area" value="<?= e($area) ?>">
        <div class="field"><label for="date">Day you need the service *</label>
          <input type="date" id="date" name="date" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+90 days')) ?>" value="<?= e($date) ?>" required>
          <span class="pill pill-<?= $free ? 'available' : 'unavailable' ?>"><?= $free ? 'Available' : e($why) ?></span>
          <span class="hint">Change the date and press "Check day" to see availability.</span>
          <?= field_error($errors, 'date') ?></div>
        <div class="field"><label for="description">Describe what you need (optional)</label>
          <textarea id="description" name="description" maxlength="1000"><?= e($_POST['description'] ?? '') ?></textarea>
          <?= field_error($errors, 'description') ?></div>
        <div class="field"><label for="images">Add up to 3 pictures (optional, JPG/PNG, max 2 MB each)</label>
          <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
          <?= field_error($errors, 'images') ?></div>
        <div class="btn-row">
          <button class="btn" type="submit">Send booking request</button>
          <button class="btn btn-outline" type="submit" formaction="client.php" formmethod="get" name="view" value="book">Check day</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

<?php else: /* find */
    $category = (string)($_GET['category'] ?? '');
    $area = (string)($_GET['area'] ?? '');
    $date = (string)($_GET['date'] ?? date('Y-m-d', strtotime('+1 day')));
    if (v_date($date)) $date = date('Y-m-d', strtotime('+1 day'));
    $searched = isset($_GET['category']) || isset($_GET['area']);
?>
  <form method="get" action="client.php" class="card">
    <input type="hidden" name="view" value="find">
    <div class="filters">
      <div class="field"><label for="category">Service</label>
        <select id="category" name="category"><option value="">All services</option>
          <?php foreach (CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="area">Your area</label>
        <select id="area" name="area"><option value="">Choose area</option>
          <?php foreach (AREAS as $a): ?><option <?= $area === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="date">Day needed</label>
        <input type="date" id="date" name="date" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+90 days')) ?>" value="<?= e($date) ?>"></div>
      <div class="field"><button class="btn" type="submit">Search</button></div>
    </div>
  </form>

  <?php if ($searched):
      $sql = "SELECT u.id, u.name, u.surname, pp.rate_amount, pp.rate_type, pp.bio,
          (SELECT ROUND(AVG(r.stars),1) FROM ratings r WHERE r.ratee_id = u.id) AS avg_stars,
          (SELECT COUNT(*) FROM ratings r WHERE r.ratee_id = u.id) AS n_ratings,
          (SELECT GROUP_CONCAT(ps2.category) FROM provider_services ps2 WHERE ps2.user_id = u.id) AS cats,
          (SELECT GROUP_CONCAT(pa2.area SEPARATOR ', ') FROM provider_areas pa2 WHERE pa2.user_id = u.id) AS areas
          FROM users u LEFT JOIN provider_profiles pp ON pp.user_id = u.id
          WHERE u.role = 'provider' AND u.status = 'active'";
      $args = [];
      if (isset(CATEGORIES[$category])) { $sql .= ' AND EXISTS (SELECT 1 FROM provider_services x WHERE x.user_id = u.id AND x.category = ?)'; $args[] = $category; }
      if (in_array($area, AREAS, true)) { $sql .= ' AND EXISTS (SELECT 1 FROM provider_areas y WHERE y.user_id = u.id AND y.area = ?)'; $args[] = $area; }
      $sql .= ' ORDER BY avg_stars DESC, u.name';
      $st = $pdo->prepare($sql); $st->execute($args);
      $providers = $st->fetchAll();
  ?>
    <h2><?= count($providers) ?> service provider<?= count($providers) === 1 ? '' : 's' ?> found</h2>
    <?php if (!in_array($area, AREAS, true)): ?><p class="alert alert-info">Choose your area to be able to book.</p><?php endif; ?>
    <div class="grid">
    <?php foreach ($providers as $p):
        [$free, $why] = provider_availability($pdo, (int)$p['id'], $date);
        $bookCat = isset(CATEGORIES[$category]) ? $category : (explode(',', (string)$p['cats'])[0] ?? '');
        $hrs = $pdo->prepare('SELECT day_of_week, TIME_FORMAT(start_time,"%H:%i") s, TIME_FORMAT(end_time,"%H:%i") e FROM provider_hours WHERE user_id = ? ORDER BY day_of_week');
        $hrs->execute([$p['id']]);
    ?>
      <div class="card">
        <h3><?= e($p['name'] . ' ' . $p['surname']) ?></h3>
        <p class="stars"><?= e(stars_text($p['avg_stars'])) ?> <span class="hint">(<?= (int)$p['n_ratings'] ?>)</span></p>
        <p><?= e(implode(', ', array_map(fn($c) => CATEGORIES[$c] ?? $c, explode(',', (string)$p['cats'])))) ?></p>
        <p><strong><?= $p['rate_amount'] ? e(rands($p['rate_amount'])) . ' ' . e(RATE_TYPES[$p['rate_type']]) : 'Rate not yet set' ?></strong></p>
        <p class="hint">Areas: <?= e($p['areas'] ?: 'not yet set') ?></p>
        <p class="hint">Working: <?php $parts = []; foreach ($hrs as $h) $parts[] = substr(DAYS[$h['day_of_week']], 0, 3) . ' ' . $h['s'] . '-' . $h['e']; echo e($parts ? implode(', ', $parts) : 'not yet set'); ?></p>
        <?php if ($p['bio']): ?><p><?= e($p['bio']) ?></p><?php endif; ?>
        <p>On <?= e(date('D j M', strtotime($date))) ?>: <span class="pill pill-<?= $free ? 'available' : 'unavailable' ?>"><?= e($free ? 'Available' : $why) ?></span></p>
        <?php if ($free && in_array($area, AREAS, true) && $bookCat): ?>
          <a class="btn" href="client.php?view=book&amp;provider=<?= (int)$p['id'] ?>&amp;category=<?= e($bookCat) ?>&amp;area=<?= urlencode($area) ?>&amp;date=<?= e($date) ?>">Book</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php /* Advertisements uploaded by providers */
    $ads = $pdo->query("SELECT a.title, a.description, a.image_path, CONCAT(u.name,' ',u.surname) AS who
        FROM provider_ads a JOIN users u ON u.id = a.provider_id WHERE u.status = 'active' ORDER BY a.created_at DESC LIMIT 12")->fetchAll();
    if ($ads): ?>
    <h2>Recent work by our service providers</h2>
    <div class="grid">
      <?php foreach ($ads as $a): ?>
        <div class="card ad">
          <?php if ($a['image_path']): ?><img src="uploads/<?= e($a['image_path']) ?>" alt="<?= e($a['title']) ?>"><?php else: ?><div class="ad-placeholder">🛠️</div><?php endif; ?>
          <h3><?= e($a['title']) ?></h3><p><?= e($a['description']) ?></p><p class="hint">by <?= e($a['who']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>