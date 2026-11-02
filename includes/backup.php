<?php
// System backup: writes a complete, restorable SQL dump of the HouseCall database to /backups.
// Pure PHP (no mysqldump needed), so it works on any XAMPP install.

function create_backup(PDO $pdo): string
{
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
    $file = 'housecall_' . date('Y-m-d_His') . '.sql';
    $fh = fopen(BACKUP_DIR . $file, 'wb');
    if (!$fh) throw new RuntimeException('Cannot write to the backups folder.');

    fwrite($fh, "-- HouseCall backup created " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
        $rows = $pdo->query("SELECT * FROM `$t`");
        $batch = [];
        foreach ($rows->fetchAll(PDO::FETCH_NUM) as $row) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) === 100) { fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return $file;
}

function list_backups(): array
{
    $out = [];
    foreach (glob(BACKUP_DIR . 'housecall_*.sql') ?: [] as $f) {
        $out[] = ['file' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

// Only file names we created ourselves are allowed (blocks path tricks like ../../config.php)
function valid_backup_name(string $name): bool
{
    return (bool)preg_match('/^housecall_\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $name) && is_file(BACKUP_DIR . $name);
}