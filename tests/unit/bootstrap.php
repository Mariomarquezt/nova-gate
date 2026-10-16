<?php
/**
 * PHPUnit bootstrap for the pure unit tests. No WordPress is loaded here.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

foreach (['class-gate.php', 'class-limiter.php', 'class-address.php'] as $file) {
    $path = dirname(__DIR__, 2) . '/includes/' . $file;
    if (is_file($path)) {
        require_once $path;
    }
}
