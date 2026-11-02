<?php
// Server-side (PHP) validation. Every validator returns an error message, or null when the value is valid.
// Browser-side checks in assets/js/app.js are only a convenience; these are the checks that count.

function v_name(string $v, string $label): ?string
{
    $v = trim($v);
    if ($v === '') return "$label is required.";
    if (!preg_match("/^\p{L}[\p{L} '\-]{0,49}$/u", $v)) return "$label may only contain letters, spaces, hyphens and apostrophes. Numbers are not allowed.";
    return null;
}

function v_username(string $v): ?string
{
    if ($v === '') return 'Username is required.';
    if (!preg_match('/^[A-Za-z0-9_]{4,20}$/', $v)) return 'Username must be 4 to 20 letters, numbers or underscores.';
    return null;
}

// Rule from the brief: at least 4 letters, 3 numbers and 1 special character (so at least 8 characters).
function v_password(string $v): ?string
{
    if ($v === '') return 'Password is required.';
    $letters = preg_match_all('/[A-Za-z]/', $v);
    $digits  = preg_match_all('/[0-9]/', $v);
    $special = preg_match_all('/[^A-Za-z0-9]/', $v);
    if (strlen($v) < 8 || $letters < 4 || $digits < 3 || $special < 1) {
        return 'Password must be at least 8 characters with at least 4 letters, 3 numbers and 1 special character (for example Garden#2026).';
    }
    if (strlen($v) > 72) return 'Password must be 72 characters or fewer.';
    return null;
}

function v_gender(string $v): ?string
{
    return in_array($v, GENDERS, true) ? null : 'Please choose a gender option.';
}

function v_age(string $v): ?string
{
    if ($v === '') return 'Age is required.';
    if (!ctype_digit($v)) return 'Age must be a whole number (digits only).';
    if ((int)$v < 18 || (int)$v > 100) return 'Age must be between 18 and 100.';
    return null;
}

function v_phone(string $v): ?string
{
    if ($v === '') return 'Phone number is required.';
    if (!preg_match('/^[0-9]{10}$/', $v)) return 'Phone number must be exactly 10 digits (numbers only, no spaces or letters).';
    return null;
}

function v_money(string $v): ?string
{
    if ($v === '') return 'Please enter your rate in rands.';
    if (!preg_match('/^[0-9]{1,6}(\.[0-9]{1,2})?$/', $v)) return 'Rate must be a number in rands, for example 250 or 249.50 (no letters).';
    if ((float)$v <= 0) return 'Rate must be more than R0.';
    return null;
}

function v_date(string $v, int $maxDaysAhead = 90): ?string
{
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) return 'Please choose a valid date.';
    $today = new DateTime('today');
    if ($d < $today) return 'The date cannot be in the past.';
    if ($d > (clone $today)->modify("+$maxDaysAhead days")) return "Bookings can be made up to $maxDaysAhead days ahead.";
    return null;
}

function v_time(string $v): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v); }

function v_text(string $v, string $label, int $min, int $max): ?string
{
    $len = mb_strlen(trim($v));
    if ($len < $min) return $min === 1 ? "$label is required." : "$label must be at least $min characters.";
    if ($len > $max) return "$label must be $max characters or fewer.";
    return null;
}

/* Services chosen at registration: gardener / plumber / cleaner / tutor (+ tutor level).
   Returns [list of category keys, error|null]. */
function services_from_post(array $post): array
{
    $picked = array_intersect((array)($post['services'] ?? []), ['gardener', 'plumber', 'cleaner', 'tutor']);
    $cats = array_values(array_intersect($picked, ['gardener', 'plumber', 'cleaner']));
    if (in_array('tutor', $picked, true)) {
        $levels = array_values(array_intersect((array)($post['tutor_levels'] ?? []), ['tutor_high_school', 'tutor_tertiary']));
        if (!$levels) return [[], 'Please choose whether you tutor High School, Tertiary, or both.'];
        $cats = array_merge($cats, $levels);
    }
    if (!$cats) return [[], 'Please choose at least one service you offer.'];
    return [$cats, null];
}