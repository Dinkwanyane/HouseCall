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