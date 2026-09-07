<?php
/**
 * Direct-access installer shim. The main front controller also routes /install
 * here, so this file is a convenience for plain XAMPP setups.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/schema.php';
require_once __DIR__ . '/app/seed.php';
require_once __DIR__ . '/app/view.php';
require_once __DIR__ . '/app/installer.php';

DB::pdo();
install_page();
