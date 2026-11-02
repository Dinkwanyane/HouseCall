<?php
require_once 'includes/auth.php';
require_once 'includes/validation.php';

$user = require_role('provider');
$uid  = $user['id'];
$pdo  = db();
$errors = [];

/* ------------------------------------------------------------------ actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $bid    = (int)($_POST['booking_id'] ?? 0);

    // Load the booking only if it belongs to this provider
    $booking = null;
    if ($bid) {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND provider_id = ?');
        $st->execute([$bid, $uid]);
        $booking = $st->fetch() ?: null;
    }

    if ($action === 'confirm' && $booking && $booking['status'] === 'pending') {
        $pdo->beginTransaction();
        // Lock this provider's bookings for the day so two confirmations cannot overlap
        $lock = $pdo->prepare("SELECT id FROM bookings WHERE provider_id = ? AND booking_date = ? AND status IN ('confirmed','completed') FOR UPDATE");
        $lock->execute([$uid, $booking['booking_date']]);
        if ($lock->fetchColumn()) {
            $pdo->rollBack();
            flash('error', 'You already have a confirmed booking on that day.');
        } else {
            $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?")->execute([$bid]);
            $day = date('D j M Y', strtotime($booking['booking_date']));
            notify($pdo, (int)$booking['client_id'], "Good news! {$user['name']} confirmed your booking for $day.");
            // The day is now taken: other waiting requests for it are closed automatically
            $others = $pdo->prepare("SELECT id, client_id FROM bookings WHERE provider_id = ? AND booking_date = ? AND status = 'pending' AND id <> ?");
            $others->execute([$uid, $booking['booking_date'], $bid]);
            foreach ($others->fetchAll() as $o) {
                $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancelled_by = 'provider' WHERE id = ?")->execute([$o['id']]);
                notify($pdo, (int)$o['client_id'], "Sorry, {$user['name']} is no longer available on $day. Please choose another day or provider.");
            }
            $pdo->commit();
            flash('success', 'Booking confirmed. The client has been notified and the day is now marked as booked.');
        }
    } elseif (($action === 'decline' && $booking && $booking['status'] === 'pending')
           || ($action === 'cancel' && $booking && $booking['status'] === 'confirmed')) {
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancelled_by = 'provider' WHERE id = ?")->execute([$bid]);
        notify($pdo, (int)$booking['client_id'], "{$user['name']} cannot do the booking for " . date('D j M Y', strtotime($booking['booking_date'])) . '. Please choose another day or provider.');
        flash('success', 'The booking has been cancelled and the client notified.');
    } elseif ($action === 'complete' && $booking && $booking['status'] === 'confirmed') {
        if ($booking['booking_date'] > date('Y-m-d')) {
            flash('error', 'You can only mark a service as done on or after the booking day.');
        } else {
            $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?")->execute([$bid]);
            notify($pdo, (int)$booking['client_id'], "{$user['name']} marked your service as done. Please rate the service.");
            flash('success', 'Service marked as done. Please rate the client.');
        }
    } elseif ($action === 'rate' && $booking && $booking['status'] === 'completed') {
        $stars = (string)($_POST['stars'] ?? '');
        $rev   = trim((string)($_POST['review'] ?? ''));
        $e1 = !preg_match('/^[1-5]$/', $stars) ? 'Please choose 1 to 5 stars.' : null;
        $e2 = v_text($rev, 'Review', 3, 500);
        if ($e1 || $e2) flash('error', $e1 ?: $e2);
        else {
            try {
                $pdo->prepare('INSERT INTO ratings (booking_id, rater_id, ratee_id, stars, review) VALUES (?,?,?,?,?)')
                    ->execute([$bid, $uid, $booking['client_id'], (int)$stars, $rev]);
                notify($pdo, (int)$booking['client_id'], "{$user['name']} rated you $stars star(s).");
                flash('success', 'Thank you! Your rating has been saved.');
            } catch (PDOException $ex) {
                flash('error', 'You have already rated this booking.');
            }
        }
    } elseif ($action === 'save_profile') {
        $rate = trim((string)($_POST['rate_amount'] ?? ''));
        $type = (string)($_POST['rate_type'] ?? '');
        $bio  = trim((string)($_POST['bio'] ?? ''));
        $areas = array_values(array_intersect((array)($_POST['areas'] ?? []), AREAS));
        [$cats, $errors['services']] = services_from_post($_POST);

        $errors['rate_amount'] = v_money($rate);
        if (!isset(RATE_TYPES[$type])) $errors['rate_type'] = 'Please choose how you charge.';
        $errors['bio'] = $bio === '' ? null : v_text($bio, 'About you', 1, 500);
        if (!$areas) $errors['areas'] = 'Please choose at least one area you work in.';

        $hours = [];
        foreach (DAYS as $n => $dayName) {
            if (empty($_POST["work_$n"])) continue;
            $s = (string)($_POST["start_$n"] ?? ''); $f = (string)($_POST["end_$n"] ?? '');
            if (!v_time($s) || !v_time($f) || $s >= $f) { $errors['hours'] = "Please enter valid start and end times for $dayName (start must be before end)."; break; }
            $hours[$n] = [$s, $f];
        }
        if (!isset($errors['hours']) && !$hours) $errors['hours'] = 'Please choose at least one day you work.';

        if (!array_filter($errors)) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO provider_profiles (user_id, rate_amount, rate_type, bio) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE rate_amount = VALUES(rate_amount), rate_type = VALUES(rate_type), bio = VALUES(bio)')
                ->execute([$uid, $rate, $type, $bio ?: null]);
            foreach (['provider_areas', 'provider_hours', 'provider_services'] as $t) {
                $pdo->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$uid]);
            }
            $ia = $pdo->prepare('INSERT INTO provider_areas (user_id, area) VALUES (?,?)');
            foreach ($areas as $a) $ia->execute([$uid, $a]);
            $ih = $pdo->prepare('INSERT INTO provider_hours (user_id, day_of_week, start_time, end_time) VALUES (?,?,?,?)');
            foreach ($hours as $n => [$s, $f]) $ih->execute([$uid, $n, $s, $f]);
            $is = $pdo->prepare('INSERT INTO provider_services (user_id, category) VALUES (?,?)');
            foreach ($cats as $c) $is->execute([$uid, $c]);
            $pdo->commit();
            flash('success', 'Your profile has been saved. Clients can now see your rates, areas and working days.');
            redirect('provider.php?view=profile');
        }
        $_GET['view'] = 'profile';
    } elseif ($action === 'add_ad') {
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $errors['title'] = v_text($title, 'Title', 3, 80);
        $errors['description'] = $desc === '' ? null : v_text($desc, 'Description', 1, 300);
        $img = null;
        if (!array_filter($errors)) {
            if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $errors['image'] = 'Please choose a picture of your work.';
            else { $img = upload_image($_FILES['image'], $err); if ($err) $errors['image'] = $err; }
        }
        if (!array_filter($errors)) {
            $pdo->prepare('INSERT INTO provider_ads (provider_id, title, description, image_path) VALUES (?,?,?,?)')->execute([$uid, $title, $desc ?: null, $img]);
            flash('success', 'Your advertisement is now visible to clients.');
            redirect('provider.php?view=ads');
        }
        $_GET['view'] = 'ads';
    } elseif ($action === 'delete_ad') {
        $st = $pdo->prepare('SELECT image_path FROM provider_ads WHERE id = ? AND provider_id = ?');
        $st->execute([(int)($_POST['ad_id'] ?? 0), $uid]);
        if ($row = $st->fetch()) {
            $pdo->prepare('DELETE FROM provider_ads WHERE id = ? AND provider_id = ?')->execute([(int)$_POST['ad_id'], $uid]);
            if ($row['image_path']) @unlink(UPLOAD_DIR . basename($row['image_path']));
            flash('success', 'Advertisement removed.');
        }
        redirect('provider.php?view=ads');
    }
    if (!isset($_GET['view'])) redirect('provider.php');
}

/* -------------------------------------------------------------------- views */
$view = $_GET['view'] ?? 'bookings';
if (!in_array($view, ['bookings', 'profile', 'ads', 'reviews', 'notifications'], true)) $view = 'bookings';
$pageTitle = 'Service provider dashboard';
require_once 'includes/header.php';
?>
<h1>Hello, <?= e($user['name']) ?></h1>
<div class="tabs" role="navigation" aria-label="Service provider sections">
  <a href="provider.php?view=bookings" class="<?= $view === 'bookings' ? 'active' : '' ?>">My bookings</a>
  <a href="provider.php?view=profile" class="<?= $view === 'profile' ? 'active' : '' ?>">Rates, areas &amp; times</a>
  <a href="provider.php?view=ads" class="<?= $view === 'ads' ? 'active' : '' ?>">Advertise my work</a>
  <a href="provider.php?view=reviews" class="<?= $view === 'reviews' ? 'active' : '' ?>">My reviews</a>
  <a href="provider.php?view=notifications" class="<?= $view === 'notifications' ? 'active' : '' ?>">Notifications<?= $unread ? " ($unread)" : '' ?></a>
</div>

<?php if ($view === 'notifications'): include 'includes/notifications_view.php'; ?>

<?php elseif ($view === 'reviews'):
    $st = $pdo->prepare("SELECT r.stars, r.review, r.created_at, CONCAT(c.name,' ',c.surname) AS who
        FROM ratings r JOIN users c ON c.id = r.rater_id WHERE r.ratee_id = ? ORDER BY r.created_at DESC");
    $st->execute([$uid]);
    $reviews = $st->fetchAll();
    $avg = $reviews ? array_sum(array_column($reviews, 'stars')) / count($reviews) : null;
?>
  <h2>What clients say about you</h2>
  <p class="card stars"><?= e(stars_text($avg)) ?> <span class="hint">(<?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?>)</span></p>
  <?php foreach ($reviews as $r): ?>
    <div class="card"><p class="stars"><?= e(stars_text($r['stars'])) ?></p><p><?= nl2br(e($r['review'])) ?></p>
      <p class="hint"><?= e($r['who']) ?> · <?= e(date('j M Y', strtotime($r['created_at']))) ?></p></div>
  <?php endforeach; ?>

<?php elseif ($view === 'ads'):
    $ads = $pdo->prepare('SELECT * FROM provider_ads WHERE provider_id = ? ORDER BY created_at DESC');
    $ads->execute([$uid]);
    $adRows = $ads->fetchAll();
?>
  <h2>Advertise your work</h2>
  <p>Pictures of your work are shown to clients on their page.</p>
  <form method="post" action="provider.php" enctype="multipart/form-data" class="card narrow" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="add_ad">
    <div class="field"><label for="title">Title *</label>
      <input type="text" id="title" name="title" maxlength="80" value="<?= e($_POST['title'] ?? '') ?>" required><?= field_error($errors, 'title') ?></div>
    <div class="field"><label for="description">Short description</label>
      <textarea id="description" name="description" maxlength="300"><?= e($_POST['description'] ?? '') ?></textarea><?= field_error($errors, 'description') ?></div>
    <div class="field"><label for="image">Picture of your work * (JPG/PNG, max 2 MB)</label>
      <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" required><?= field_error($errors, 'image') ?></div>
    <button class="btn" type="submit">Publish advertisement</button>
  </form>
  <h3>My advertisements</h3>
  <div class="grid">
  <?php foreach ($adRows as $a): ?>
    <div class="card ad">
      <?php if ($a['image_path']): ?><img src="uploads/<?= e($a['image_path']) ?>" alt="<?= e($a['title']) ?>"><?php endif; ?>
      <h3><?= e($a['title']) ?></h3><p><?= e($a['description']) ?></p>
      <form method="post" action="provider.php"><?= csrf_field() ?><input type="hidden" name="action" value="delete_ad"><input type="hidden" name="ad_id" value="<?= (int)$a['id'] ?>">
        <button class="btn btn-danger btn-small" data-confirm="Remove this advertisement?">Remove</button></form>
    </div>
  <?php endforeach; if (!$adRows): ?><p class="card">You have not published any advertisements yet.</p><?php endif; ?>
  </div>

<?php elseif ($view === 'profile'):
    $pp = $pdo->prepare('SELECT * FROM provider_profiles WHERE user_id = ?'); $pp->execute([$uid]); $prof = $pp->fetch() ?: [];
    $isPost = ($_POST['action'] ?? '') === 'save_profile';
    $curAreas = $isPost ? (array)($_POST['areas'] ?? []) : $pdo->query("SELECT area FROM provider_areas WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN);
    $curHours = [];
    foreach ($pdo->query("SELECT day_of_week, TIME_FORMAT(start_time,'%H:%i') s, TIME_FORMAT(end_time,'%H:%i') f FROM provider_hours WHERE user_id = $uid") as $h) $curHours[$h['day_of_week']] = $h;
    $selectedCats = $isPost ? (services_from_post($_POST)[0]) : $pdo->query("SELECT category FROM provider_services WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN);
?>
  <h2>Rates, areas and operating times</h2>
  <p>Clients use this information to choose a service provider and to see when you are available.</p>
  <form method="post" action="provider.php" class="card" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
    <div class="filters">
      <div class="field"><label for="rate_amount">My rate (Rands) *</label>
        <input type="text" inputmode="decimal" id="rate_amount" name="rate_amount" maxlength="9" data-type="money" value="<?= e($isPost ? ($_POST['rate_amount'] ?? '') : ($prof['rate_amount'] ?? '')) ?>" placeholder="e.g. 250">
        <?= field_error($errors, 'rate_amount') ?></div>
      <div class="field"><label for="rate_type">I charge *</label>
        <select id="rate_type" name="rate_type"><option value="">Please choose</option>
          <?php $curType = $isPost ? ($_POST['rate_type'] ?? '') : ($prof['rate_type'] ?? '');
          foreach (RATE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $curType === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?= field_error($errors, 'rate_type') ?></div>
    </div>
    <div class="field"><label for="bio">About me (optional)</label>
      <textarea id="bio" name="bio" maxlength="500"><?= e($isPost ? ($_POST['bio'] ?? '') : ($prof['bio'] ?? '')) ?></textarea><?= field_error($errors, 'bio') ?></div>

    <fieldset><legend>Areas I work in *</legend>
      <?php foreach (AREAS as $a): ?><label class="check"><input type="checkbox" name="areas[]" value="<?= e($a) ?>" <?= in_array($a, $curAreas, true) ? 'checked' : '' ?>> <?= e($a) ?></label><?php endforeach; ?>
      <?= field_error($errors, 'areas') ?></fieldset>

    <fieldset><legend>Days and times I work *</legend>
      <table class="hours-table">
      <?php foreach (DAYS as $n => $dayName):
          $on = $isPost ? !empty($_POST["work_$n"]) : isset($curHours[$n]);
          $s = $isPost ? ($_POST["start_$n"] ?? '08:00') : ($curHours[$n]['s'] ?? '08:00');
          $f = $isPost ? ($_POST["end_$n"] ?? '17:00') : ($curHours[$n]['f'] ?? '17:00'); ?>
        <tr><td><label class="check"><input type="checkbox" name="work_<?= $n ?>" value="1" <?= $on ? 'checked' : '' ?>> <?= e($dayName) ?></label></td>
            <td><label class="hint" for="s<?= $n ?>">From</label> <input type="time" id="s<?= $n ?>" name="start_<?= $n ?>" value="<?= e($s) ?>"></td>
            <td><label class="hint" for="f<?= $n ?>">To</label> <input type="time" id="f<?= $n ?>" name="end_<?= $n ?>" value="<?= e($f) ?>"></td></tr>
      <?php endforeach; ?>
      </table><?= field_error($errors, 'hours') ?></fieldset>

    <?php include 'includes/services_fields.php'; ?>
    <button class="btn" type="submit">Save my profile</button>
  </form>

<?php else: /* bookings */
    $st = $pdo->prepare("SELECT b.*, CONCAT(c.name,' ',c.surname) AS client_name, c.phone AS client_phone,
        (SELECT COUNT(*) FROM ratings r WHERE r.booking_id = b.id AND r.rater_id = ?) AS rated
        FROM bookings b JOIN users c ON c.id = b.client_id WHERE b.provider_id = ?
        ORDER BY FIELD(b.status,'pending','confirmed','completed','cancelled'), b.booking_date, b.id");
    $st->execute([$uid, $uid]);
    $rows = $st->fetchAll();
    $profileDone = (bool)$pdo->query("SELECT 1 FROM provider_hours WHERE user_id = $uid LIMIT 1")->fetchColumn();
?>
  <?php if (!$profileDone): ?><p class="alert alert-info">Clients cannot book you yet. Please <a href="provider.php?view=profile">set your rates, areas and working days</a>.</p><?php endif; ?>
  <h2>My bookings</h2>
  <?php if (!$rows): ?><p class="card">No bookings yet. They will appear here, and you will get a notification.</p><?php endif; ?>
  <?php foreach ($rows as $b):
      $imgs = $pdo->prepare('SELECT image_path FROM booking_images WHERE booking_id = ?'); $imgs->execute([$b['id']]); ?>
    <div class="card">
      <h3><?= e(CATEGORIES[$b['category']] ?? $b['category']) ?> for <?= e($b['client_name']) ?>
        <span class="pill pill-<?= e($b['status']) ?>"><?= e($b['status'] === 'completed' ? 'Done' : ucfirst($b['status'])) ?></span></h3>
      <p><strong><?= e(date('l j F Y', strtotime($b['booking_date']))) ?></strong> · <?= e($b['area']) ?></p>
      <?php if ($b['status'] === 'confirmed' || $b['status'] === 'completed'): ?><p>Client phone: <?= e($b['client_phone']) ?></p><?php endif; ?>
      <?php if ($b['description']): ?><p><?= nl2br(e($b['description'])) ?></p><?php endif; ?>
      <div class="thumbs"><?php foreach ($imgs as $im): ?><a href="uploads/<?= e($im['image_path']) ?>" target="_blank" rel="noopener"><img src="uploads/<?= e($im['image_path']) ?>" alt="Picture from the client"></a><?php endforeach; ?></div>
      <?php if ($b['status'] === 'cancelled'): ?><p class="hint">Cancelled by <?= e($b['cancelled_by']) ?>.</p><?php endif; ?>
      <div class="btn-row">
        <?php
        $btn = function (string $action, string $label, string $class, ?string $confirm = null) use ($b) {
            echo '<form method="post" action="provider.php">' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="booking_id" value="' . (int)$b['id'] . '">'
               . '<button class="btn ' . $class . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . e($label) . '</button></form>';
        };
        if ($b['status'] === 'pending') { $btn('confirm', 'Confirm booking', ''); $btn('decline', 'Decline', 'btn-danger', 'Decline this booking?'); }
        if ($b['status'] === 'confirmed') {
            if ($b['booking_date'] <= date('Y-m-d')) $btn('complete', 'Mark service as done', 'btn-accent');
            $btn('cancel', 'Cancel booking', 'btn-danger', 'Cancel this confirmed booking?');
        } ?>
      </div>
      <?php if ($b['status'] === 'completed' && !$b['rated']): ?>
        <form method="post" action="provider.php" class="card">
          <?= csrf_field() ?><input type="hidden" name="action" value="rate"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
          <h3>Rate <?= e($b['client_name']) ?></h3>
          <div class="star-pick" role="radiogroup" aria-label="Stars">
            <?php for ($i = 1; $i <= 5; $i++): ?><label><input type="radio" name="stars" value="<?= $i ?>" required><span><?= $i ?> ★</span></label><?php endfor; ?>
          </div>
          <div class="field"><label for="rv<?= (int)$b['id'] ?>">Your review</label><textarea id="rv<?= (int)$b['id'] ?>" name="review" maxlength="500" required></textarea></div>
          <button class="btn btn-accent" type="submit">Submit rating</button>
        </form>
      <?php elseif ($b['status'] === 'completed'): ?><p class="hint">You have rated this client. ✔</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>