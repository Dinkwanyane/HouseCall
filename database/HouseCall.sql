<?php
// Copy this file to config.php and adjust the values for your machine.
define('DB_HOST', 'localhost');
define('DB_NAME', 'HouseCall');
define('DB_USER', 'root');
define('DB_PASS', '');

define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_DIR', BASE_PATH . '/uploads/');
define('BACKUP_DIR', BASE_PATH . '/backups/');
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);

date_default_timezone_set('Africa/Johannesburg');