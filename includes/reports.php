<?php
// Report queries for the administrator. Every query is a prepared statement.
// $from / $to are Y-m-d dates (inclusive). Booking reports filter on booking_date, activity reports on created_at.

function report_data(PDO $pdo, string $from, string $to): array
{
    $r = [];
    $range = [$from, $to];
    $created = [$from . ' 00:00:00', $to . ' 23:59:59'];

    $q = function (string $sql, array $args = []) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    };

    // 1. Services rendered vs cancelled (overall and per provider)
    $r['status'] = $q("SELECT status, COUNT(*) AS n FROM bookings WHERE booking_date BETWEEN ? AND ? GROUP BY status", $range);
    $r['per_provider'] = $q("SELECT CONCAT(u.name,' ',u.surname) AS provider,
            SUM(b.status = 'completed') AS rendered,
            SUM(b.status = 'cancelled' AND b.cancelled_by = 'provider') AS cancelled_by_provider,
            SUM(b.status = 'cancelled' AND b.cancelled_by = 'client') AS cancelled_by_client,
            COUNT(*) AS total
        FROM bookings b JOIN users u ON u.id = b.provider_id
        WHERE b.booking_date BETWEEN ? AND ? GROUP BY u.id ORDER BY rendered DESC, total DESC", $range);

    // 2. Demand hotspots: where and for what services clients book
    $r['hotspots'] = $q("SELECT area, COUNT(*) AS n FROM bookings WHERE booking_date BETWEEN ? AND ? GROUP BY area ORDER BY n DESC", $range);
    $r['demand_by_service'] = $q("SELECT category, COUNT(*) AS n FROM bookings WHERE booking_date BETWEEN ? AND ? GROUP BY category ORDER BY n DESC", $range);

    // 3. Service recurrence: clients who booked the same service more than once
    $r['recurrence'] = $q("SELECT CONCAT(c.name,' ',c.surname) AS client, b.category, COUNT(*) AS times
        FROM bookings b JOIN users c ON c.id = b.client_id
        WHERE b.booking_date BETWEEN ? AND ? AND b.status <> 'cancelled'
        GROUP BY b.client_id, b.category HAVING COUNT(*) > 1 ORDER BY times DESC LIMIT 15", $range);
    $r['recurrence_summary'] = $q("SELECT COUNT(*) AS pairs, SUM(times > 1) AS repeat_pairs FROM (
            SELECT COUNT(*) AS times FROM bookings WHERE booking_date BETWEEN ? AND ? AND status <> 'cancelled'
            GROUP BY client_id, category) t", $range)[0];

    // 4. Logins
    $r['logins_total'] = $q("SELECT SUM(success = 1) AS ok, SUM(success = 0) AS failed FROM login_logs WHERE created_at BETWEEN ? AND ?", $created)[0];
    $r['logins_by_role'] = $q("SELECT u.role, COUNT(*) AS n FROM login_logs l JOIN users u ON u.id = l.user_id
        WHERE l.success = 1 AND l.created_at BETWEEN ? AND ? GROUP BY u.role", $created);
    $r['logins_by_day'] = $q("SELECT DATE(created_at) AS day, COUNT(*) AS n FROM login_logs
        WHERE success = 1 AND created_at BETWEEN ? AND ? GROUP BY day ORDER BY day DESC LIMIT 14", $created);

    // 5. Sign-ups
    $r['signups'] = $q("SELECT role, COUNT(*) AS n FROM users WHERE created_at BETWEEN ? AND ? GROUP BY role", $created);
    $r['signups_by_month'] = $q("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
            SUM(role = 'client') AS clients, SUM(role = 'provider') AS providers
        FROM users WHERE role <> 'admin' AND created_at BETWEEN ? AND ? GROUP BY month ORDER BY month DESC", $created);
    $r['signups_total'] = $q("SELECT SUM(role='client') AS clients, SUM(role='provider') AS providers FROM users")[0] ?? [];

    return $r;
}

// Turns [['label'=>..,'n'=>..], ...] into horizontal bars (pure CSS, works on print too)
function bars(array $rows, string $labelKey, string $valueKey = 'n', string $class = '', ?callable $labeller = null): string
{
    if (!$rows) return '<p class="hint">No data for this period.</p>';
    $max = max(1, (int)max(array_column($rows, $valueKey)));
    $out = '';
    foreach ($rows as $row) {
        $label = $labeller ? $labeller($row[$labelKey]) : $row[$labelKey];
        $pct = round(((int)$row[$valueKey] / $max) * 100);
        $out .= '<div class="bar-row"><span>' . e($label) . '</span><div class="bar ' . $class . '"><span style="width:' . $pct . '%"></span></div><strong>' . (int)$row[$valueKey] . '</strong></div>';
    }
    return $out;
}