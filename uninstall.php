<?php
/**
 * Runs when Nova Gate is deleted from the Plugins screen (AC-25, AC-60).
 * Removes Nova Gate's own options. Touches no Novamira option.
 *
 * @package NovaGate
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('nova_gate_key_hash');
delete_option('nova_gate_keys');
delete_option('nova_gate_tries');
delete_option('nova_gate_salt');
delete_option('nova_gate_open_until');
delete_option('nova_gate_timer_minutes');
delete_option('nova_gate_last_open');
