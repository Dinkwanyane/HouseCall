<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

const CATEGORIES = [
    'gardener'          => 'Gardener',
    'plumber'           => 'Plumber',
    'cleaner'           => 'Cleaner',
    'tutor_high_school' => 'Tutor (High School)',
    'tutor_tertiary'    => 'Tutor (Tertiary)',
];
const AREAS      = ['Pretoria East', 'Pretoria North', 'Centurion', 'Mamelodi', 'Arcadia'];
const DAYS       = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const RATE_TYPES = ['per_hour' => 'per hour', 'per_day' => 'per day', 'per_job' => 'per job'];
const GENDERS    = ['Male', 'Female', 'Prefer not to say'];

/* ---------- output helpers ---------- */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function rands($n): string { return 'R' . number_format((float)$n, 2, '.', ' '); }
function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="error" role="alert">' . e($errors[$key]) . '</span>' : '';
}
function stars_text($avg): string
{
    if ($avg === null) return 'No ratings yet';
    $full = (int)round($avg);
    return str_repeat('★', $full) . str_repeat('☆', 5 - $full) . ' ' . number_format((float)$avg, 1);
}

/* ---------- CSRF protection ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $real = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || $real === '' || !hash_equals($real, $sent)) {
        http_response_code(400);
        exit('Security check failed. Please go back, refresh the page and try again.');
    }
}

/* ---------- flash messages ---------- */
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="alert alert-' . e($type) . '" role="status">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- notifications ---------- */
function notify(PDO $pdo, int $userId, string $message): void
{
    $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)')->execute([$userId, $message]);
}

/* ---------- password change record: the admin is told, and it appears in the reports ---------- */
// $method: 'self_reset' (forgot-password page) or 'admin_reset'. $adminId is the administrator who did an admin_reset.
function log_password_change(PDO $pdo, int $userId, string $method, ?int $adminId = null): void
{
    $pdo->prepare('INSERT INTO password_changes (user_id, method, changed_by, ip) VALUES (?,?,?,?)')
        ->execute([$userId, $method, $adminId, $_SERVER['REMOTE_ADDR'] ?? null]);

    $st = $pdo->prepare('SELECT name, surname, username, role FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) return;
    $who = ucfirst($u['role']) . ' ' . $u['name'] . ' ' . $u['surname'] . ' (' . $u['username'] . ')';

    if ($method === 'self_reset') {
        // Tell every active administrator
        foreach ($pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            notify($pdo, (int)$aid, mb_substr("Password changed: $who reset their own password using Forgot password.", 0, 300));
        }
    } else {
        // Tell the person whose password an administrator changed
        notify($pdo, $userId, 'An administrator has reset your password. Please log in with the new password you were given.');
    }
}

/* ---------- safe redirect target (same site only) ---------- */
function safe_return_url(?string $url): string
{
    if (!$url) return '';
    $p = parse_url($url);
    if ($p === false) return '';
    if (isset($p['host']) && strcasecmp($p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $_SERVER['HTTP_HOST'] ?? '') !== 0) return '';
    if (isset($p['scheme']) && !isset($p['host'])) return '';
    $path = $p['path'] ?? '';
    if ($path === '' || str_starts_with($path, '//')) return '';
    return $path . (isset($p['query']) ? '?' . $p['query'] : '');
}

/* ---------- availability: can this provider be booked on this date? ---------- */
function provider_availability(PDO $pdo, int $providerId, string $date): array
{
    $dow = (int)date('N', strtotime($date));
    $st = $pdo->prepare('SELECT 1 FROM provider_hours WHERE user_id = ? AND day_of_week = ?');
    $st->execute([$providerId, $dow]);
    if (!$st->fetchColumn()) return [false, 'Not working on ' . DAYS[$dow] . 's'];

    $st = $pdo->prepare("SELECT 1 FROM bookings WHERE provider_id = ? AND booking_date = ? AND status IN ('confirmed','completed') LIMIT 1");
    $st->execute([$providerId, $date]);
    if ($st->fetchColumn()) return [false, 'Already booked'];
    return [true, 'Available'];
}

/* ---------- image upload (validated by real content, not by file name) ---------- */
function upload_image(array $file, ?string &$error): ?string
{
    $error = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'The picture could not be uploaded. Please try again.'; return null; }
    if ($file['size'] > MAX_UPLOAD_BYTES) { $error = 'Each picture must be smaller than 2 MB.'; return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null || @getimagesize($file['tmp_name']) === false) { $error = 'Pictures must be JPG, PNG or WEBP files.'; return null; }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $name)) { $error = 'The picture could not be saved.'; return null; }
    return $name;
}

// Turns $_FILES['images'] (multiple) into a list of single-file arrays
function normalise_files(array $f): array
{
    $out = [];
    if (!isset($f['name']) || !is_array($f['name'])) return $out;
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/* ---------- ratings with optional pictures (used by clients and providers) ---------- */

// Saves one rating plus up to 3 optional pictures. Returns true on success; otherwise false and $error is set.
function save_rating(PDO $pdo, int $bookingId, int $raterId, int $rateeId, int $stars, string $review, array $aspects, array $files, ?string &$error = null): bool
{
    $error = null;
    $saved = [];
    foreach (array_slice($files, 0, 3) as $f) {
        $name = upload_image($f, $err);
        if ($err) {
            foreach ($saved as $s) @unlink(UPLOAD_DIR . $s);
            $error = $err;
            return false;
        }
        if ($name) $saved[] = $name;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO ratings (booking_id, rater_id, ratee_id, stars, punctuality, aspect2, aspect3, review) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$bookingId, $raterId, $rateeId, $stars, $aspects['punctuality'] ?? null, $aspects['aspect2'] ?? null, $aspects['aspect3'] ?? null, $review]);
        $ratingId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO rating_images (rating_id, image_path) VALUES (?, ?)');
        foreach ($saved as $s) $ins->execute([$ratingId, $s]);
        $pdo->commit();
        return true;
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($saved as $s) @unlink(UPLOAD_DIR . $s);
        $error = 'You have already rated this booking.';
        return false;
    }
}

// The rating one person gave for a booking (stars, review, pictures), or null if none yet
function rating_by(PDO $pdo, int $bookingId, int $raterId): ?array
{
    $st = $pdo->prepare('SELECT id, stars, punctuality, aspect2, aspect3, review, reply FROM ratings WHERE booking_id = ? AND rater_id = ?');
    $st->execute([$bookingId, $raterId]);
    $r = $st->fetch();
    if (!$r) return null;
    $im = $pdo->prepare('SELECT image_path FROM rating_images WHERE rating_id = ? ORDER BY id');
    $im->execute([$r['id']]);
    $r['images'] = $im->fetchAll(PDO::FETCH_COLUMN);
    return $r;
}

// Small clickable picture thumbnails, each with a Download link
function thumbs_html(array $paths, string $alt = 'Picture attached to the rating'): string
{
    if (!$paths) return '';
    $out = '<div class="thumbs">';
    foreach (array_values($paths) as $i => $p) {
        $file = 'uploads/' . e($p);
        $ext  = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $out .= '<figure class="thumb-item"><a href="' . $file . '" target="_blank" rel="noopener" title="Open full size"><img src="' . $file . '" alt="' . e($alt) . '"></a>'
              . '<a class="thumb-download" href="' . $file . '" download="housecall-picture-' . ($i + 1) . '.' . e($ext) . '">&#11015; Download</a></figure>';
    }
    return $out . '</div>';
}

// One rating shown as a small box (used on both dashboards)
function rating_box_html(?array $r, string $title): string
{
    if (!$r) return '';
    return '<div class="card"><h4>' . e($title) . '</h4><p class="stars">' . e(stars_text($r['stars'])) . '</p><p>'
         . nl2br(e((string)$r['review'])) . '</p>' . thumbs_html($r['images'])
         . (!empty($r['reply']) ? '<blockquote class="reply"><strong>Reply:</strong> ' . nl2br(e($r['reply'])) . '</blockquote>' : '') . '</div>';
}

/* ---------- two-way rating: feedback questions, reputation summaries, public reviews ---------- */
// What each person is scored on. Key = the role of the person BEING rated.
const ASPECTS = [
    'provider' => ['punctuality' => 'Punctuality', 'aspect2' => 'Professionalism', 'aspect3' => 'Quality of work'],
    'client'   => ['punctuality' => 'Punctuality', 'aspect2' => 'Communication',   'aspect3' => 'Respectful conduct'],
];

// Reads and checks the three feedback scores from the form. Returns [] and sets $error when they are missing/invalid.
function parse_aspects(string $rateeRole, ?string &$error = null): array
{
    $error = null;
    $out = [];
    foreach (ASPECTS[$rateeRole] as $key => $label) {
        $v = (string)($_POST[$key] ?? '');
        if (!preg_match('/^[1-5]$/', $v)) { $error = 'Please score every question from 1 to 5 (' . $label . ').'; return []; }
        $out[$key] = (int)$v;
    }
    return $out;
}

// The three "score 1 to 5" drop-downs shown on a rating form
function aspect_fields_html(string $rateeRole, int $bookingId): string
{
    $html = '';
    foreach (ASPECTS[$rateeRole] as $key => $label) {
        $id = $key . $bookingId;
        $html .= '<div class="field"><label for="' . $id . '">' . e($label) . ' *</label><select id="' . $id . '" name="' . $key . '" required>'
               . '<option value="">Score 1 to 5</option>';
        foreach ([5 => '5 - Excellent', 4 => '4 - Good', 3 => '3 - Fair', 2 => '2 - Poor', 1 => '1 - Very poor'] as $n => $t) $html .= '<option value="' . $n . '">' . e($t) . '</option>';
        $html .= '</select></div>';
    }
    return $html;
}

// Average stars, number of reviews, average per feedback question, and completed bookings for one person
function reputation(PDO $pdo, int $userId, string $role): array
{
    $st = $pdo->prepare('SELECT COUNT(*) n, ROUND(AVG(stars),1) avg_stars, ROUND(AVG(punctuality),1) punctuality, ROUND(AVG(aspect2),1) aspect2, ROUND(AVG(aspect3),1) aspect3 FROM ratings WHERE ratee_id = ?');
    $st->execute([$userId]);
    $r = $st->fetch();
    $col = $role === 'provider' ? 'provider_id' : 'client_id';
    $c = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE $col = ? AND status = 'completed'");
    $c->execute([$userId]);
    $aspects = [];
    foreach (ASPECTS[$role] as $key => $label) $aspects[$key] = $r[$key] !== null ? (float)$r[$key] : null;
    return ['n' => (int)$r['n'], 'avg' => $r['avg_stars'] !== null ? (float)$r['avg_stars'] : null, 'aspects' => $aspects, 'completed' => (int)$c->fetchColumn()];
}

// "Punctuality 4.8 / 5" list
function aspect_summary_html(array $rep, string $role): string
{
    if ($rep['n'] === 0) return '';
    $out = '<ul class="aspects">';
    foreach (ASPECTS[$role] as $key => $label) {
        $out .= '<li><span>' . e($label) . '</span> <strong>' . ($rep['aspects'][$key] !== null ? number_format($rep['aspects'][$key], 1) . ' / 5' : '-') . '</strong></li>';
    }
    return $out . '</ul>';
}

// Written reviews about a person, newest first (each with its pictures)
function reviews_about(PDO $pdo, int $userId, int $limit = 20): array
{
    $st = $pdo->prepare('SELECT id, stars, punctuality, aspect2, aspect3, review, reply, replied_at, created_at FROM ratings WHERE ratee_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit);
    $st->execute([$userId]);
    $rows = $st->fetchAll();
    $im = $pdo->prepare('SELECT image_path FROM rating_images WHERE rating_id = ? ORDER BY id');
    foreach ($rows as &$r) { $im->execute([$r['id']]); $r['images'] = $im->fetchAll(PDO::FETCH_COLUMN); }
    return $rows;
}

// One review card. $rateeRole = role of the person reviewed; $byLabel e.g. "Verified client"; $replyBy e.g. "Response from the service provider"
function review_card_html(array $r, string $rateeRole, string $byLabel, string $replyBy = 'Response', string $extra = ''): string
{
    $scores = [];
    foreach (ASPECTS[$rateeRole] as $key => $label) if ($r[$key] !== null) $scores[] = e($label) . ' ' . (int)$r[$key] . '/5';
    $html = '<div class="card review"><p class="stars">' . e(stars_text($r['stars'])) . '</p><p>' . nl2br(e((string)$r['review'])) . '</p>';
    if ($scores) $html .= '<p class="hint">' . implode(' · ', $scores) . '</p>';
    $html .= thumbs_html($r['images'] ?? []);
    $html .= '<p class="hint">' . e($byLabel) . ' · ' . e(date('F Y', strtotime($r['created_at']))) . '</p>';
    if (!empty($r['reply'])) $html .= '<blockquote class="reply"><strong>' . e($replyBy) . ':</strong> ' . nl2br(e($r['reply'])) . '</blockquote>';
    return $html . $extra . '</div>';
}

// Short, limited view of a CLIENT for a provider deciding on a booking
function client_reputation_html(PDO $pdo, int $clientId): string
{
    $rep = reputation($pdo, $clientId, 'client');
    $h = '<div class="rep-box"><h4>About this client</h4><p class="stars">' . e(stars_text($rep['avg'])) . ' <span class="hint">(' . $rep['n'] . ' rating' . ($rep['n'] === 1 ? '' : 's') . ')</span></p>';
    $h .= '<p>' . ($rep['completed'] > 0 ? '✔ ' . $rep['completed'] . ' completed booking' . ($rep['completed'] === 1 ? '' : 's') : 'New client: no completed bookings yet') . '</p>';
    $h .= aspect_summary_html($rep, 'client');
    foreach (array_slice(reviews_about($pdo, $clientId, 3), 0, 3) as $r) {
        $h .= '<p class="hint">“' . e(mb_strimwidth((string)$r['review'], 0, 120, '…')) . '” (service provider, ' . e(date('M Y', strtotime($r['created_at']))) . ')</p>';
    }
    return $h . '</div>';
}