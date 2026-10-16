<?php
/**
 * Plugin Name: Nova Gate – Access Lock for Novamira
 * Plugin URI: https://monkeywebs.com/
 * Description: Keeps Novamira's AI abilities off for everyone and opens them for one request at a time when it carries the right X-Nova-Gate-Key header. Independent add-on, not made by Novamira.
 * Version: 0.2.1
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Requires Plugins: novamira
 * Author: Monkey Webs
 * Author URI: https://monkeywebs.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nova-gate
 *
 * @package NovaGate
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('NOVA_GATE_VERSION', '0.2.1');
define('NOVA_GATE_FILE', __FILE__);
define('NOVA_GATE_DIR', __DIR__);

require_once NOVA_GATE_DIR . '/includes/class-gate.php';
require_once NOVA_GATE_DIR . '/includes/class-limiter.php';
require_once NOVA_GATE_DIR . '/includes/class-address.php';
require_once NOVA_GATE_DIR . '/includes/hooks.php';
require_once NOVA_GATE_DIR . '/includes/lock.php';
require_once NOVA_GATE_DIR . '/includes/keys.php';
require_once NOVA_GATE_DIR . '/includes/rest.php';
require_once NOVA_GATE_DIR . '/includes/class-admin.php';

// The CLI file exists only for WP-CLI; no web request can reach its code (AC-59).
if (defined('WP_CLI') && WP_CLI) {
    require_once NOVA_GATE_DIR . '/includes/class-cli.php';
}

// Decide at load time, not on a hook: Novamira reads its switch while its own
// file loads, which happens right after this file when plugin order is normal.
// If Novamira already ran, we are late; the filters still attach (AC-19).
NovaGate\boot(!function_exists('novamira_is_enabled'));
NovaGate\state();
