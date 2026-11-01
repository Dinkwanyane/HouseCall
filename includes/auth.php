<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

function current_user(): ?array { return $_SESSION['user'] ?? null; }

function dashboard_for(string $role): string
{
    return ['client' => 'client.php', 'provider' => 'provider.php', 'admin' => 'admin.php'][$role] ?? 'index.php';
}

// Shows the "Unauthorized access!" alert; after OK the browser returns to the page the user came from.
function unauthorized_access(string $returnTo): void
{
    http_response_code(403);
    $js = json_encode($returnTo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Unauthorized access</title></head><body>'
       . '<script>alert("Unauthorized access!"); window.location.href = ' . $js . ';</script>'
       . '<noscript><p>Unauthorized access! <a href="' . e($returnTo) . '">Go back</a></p></noscript></body></html>';
    exit;
}

// Page guard: call at the very top of every protected page.
function require_role(string $role): array
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Please log in to continue.');
        redirect('login.php?role=' . urlencode($role));
    }
    // Re-check the database on every request so suspended/deleted accounts lose access immediately.
    $st = db()->prepare('SELECT role, status FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!$row || $row['status'] !== 'active') {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('error', 'Your account is not active. Please contact the administrator.');
        redirect('login.php');
    }
    if ($row['role'] !== $role) {
        $back = safe_return_url($_SERVER['HTTP_REFERER'] ?? '');
        unauthorized_access($back !== '' ? $back : dashboard_for($u['role']));
    }
    return $u;
}

function log_login(?int $userId, string $username, string $role, bool $ok, ?string $reason = null): void
{
    db()->prepare('INSERT INTO login_logs (user_id, username, role_selected, success, reason, ip) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, mb_substr($username, 0, 50), $role, (int)$ok, $reason, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function recent_failures(string $username): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM login_logs WHERE username = ? AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $st->execute([mb_substr($username, 0, 50)]);
    return (int)$st->fetchColumn();
}