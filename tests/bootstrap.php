<?php

declare(strict_types=1);

use function Mantle\Testing\manager;

require_once __DIR__ . '/../vendor/autoload.php';
$rootDir = realpath(__DIR__ . '/..');

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
putenv("WP_CORE_DIR=$rootDir/tmp/wordpress");
putenv('WP_TESTS_USE_HTTPS=1');

// Query Monitor doesn't load on the CLI otherwise
define('QM_TESTS', true);

manager()
    ->with_sqlite()
    ->loaded(static function (): void {
        require dirname(__DIR__) . '/wp-content/plugins/query-monitor/query-monitor.php';
    })
    ->install();
