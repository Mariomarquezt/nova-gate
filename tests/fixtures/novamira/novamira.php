<?php
/**
 * Plugin Name: Novamira (test double for Nova Gate)
 * Description: NOT the real Novamira. A frozen test fixture that copies only how Novamira reads its AI switch, so Nova Gate can be tested on a real WordPress without the real plugin.
 * Version: 0.0.0-fixture
 * License: GPL-2.0-or-later
 *
 * FROZEN. Do not edit. If a test needs different behaviour, propose a seam change (spec S-5).
 *
 * What it copies from real Novamira 1.8.1 (behaviour only, no code):
 *  - the switch is the options `novamira_ai_abilities_enabled` + `novamira_ai_abilities_domain`;
 *  - the switch is read ONCE while this file loads, and the MCP route exists only when it was on;
 *  - the upload / admin-access / ability-run routes always exist and re-check the switch when called;
 *  - the enable / disable functions write the options the same way.
 *
 * Test-only addition (v0.2.1, not in real Novamira): the `novamira/v1/fixture-run` route writes the
 * option `nova_gate_fixture_ran`, so the suite can prove a locked request cannot execute anything.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

function novamira_is_enabled(): bool
{
    $value = get_option('novamira_ai_abilities_enabled', false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    $locked_domain = get_option('novamira_ai_abilities_domain', '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain === $current_domain;
}

function novamira_enable_ai_abilities(): bool
{
    update_option('novamira_ai_abilities_enabled', '1');
    update_option('novamira_ai_abilities_domain', (string) wp_parse_url(home_url(), PHP_URL_HOST));
    return true;
}

function novamira_disable_ai_abilities(): bool
{
    update_option('novamira_ai_abilities_enabled', '0');
    delete_option('novamira_ai_abilities_domain');
    return true;
}

// Read ONCE at load time, exactly like the real plugin does.
$GLOBALS['novamira_fixture_enabled_at_load'] = novamira_is_enabled();

if ($GLOBALS['novamira_fixture_enabled_at_load']) {
    add_action('rest_api_init', static function (): void {
        register_rest_route('mcp', '/novamira', [
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => static fn() => new WP_REST_Response(['fixture' => 'mcp', 'enabled_at_load' => true], 200),
            // The real plugin needs a logged-in manager here. The double does not, so the
            // integration tests measure the gate alone.
            'permission_callback' => '__return_true',
        ]);
    });
}

function novamira_fixture_runtime_route(string $name): callable
{
    return static function () use ($name) {
        if (!novamira_is_enabled()) {
            return new WP_Error('novamira_disabled', 'Novamira AI Abilities are disabled.', ['status' => 403]);
        }
        return new WP_REST_Response(['fixture' => $name, 'enabled' => true], 200);
    };
}

add_action('rest_api_init', static function (): void {
    register_rest_route('novamira/v1', '/upload', [
        'methods' => ['POST', 'PUT'],
        'callback' => novamira_fixture_runtime_route('upload'),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('novamira/v1', '/admin-access', [
        'methods' => ['POST'],
        'callback' => novamira_fixture_runtime_route('admin-access'),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('novamira/v1', '/admin-access/(?P<nonce>[A-Za-z0-9_-]+)', [
        'methods' => ['GET'],
        'callback' => novamira_fixture_runtime_route('admin-access-login'),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('novamira/v1', '/abilities/(?P<namespace>[^/]+)/(?P<ability>[^/]+)/run', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => novamira_fixture_runtime_route('ability-run'),
        'permission_callback' => '__return_true',
    ]);
    // fx-21: an "executable" ability. Like the routes above it re-checks the
    // switch when called, but it also performs an observable action — writing
    // the marker option — so a test can prove nothing ran while the gate was
    // locked.
    register_rest_route('novamira/v1', '/fixture-run', [
        'methods' => ['POST'],
        'callback' => static function () {
            if (!novamira_is_enabled()) {
                return new WP_Error('novamira_disabled', 'Novamira AI Abilities are disabled.', ['status' => 403]);
            }
            update_option('nova_gate_fixture_ran', time());
            return new WP_REST_Response(['fixture' => 'fixture-run', 'ran' => true], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

// The admin-bar style toggle: admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=on|off
add_action('admin_post_novamira_toggle_ai_abilities', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden', '', ['response' => 403]);
    }
    check_admin_referer('novamira_toggle_ai_abilities');

    $target = isset($_GET['novamira_target']) ? (string) $_GET['novamira_target'] : '';
    if ($target === 'on') {
        novamira_enable_ai_abilities();
    } else {
        novamira_disable_ai_abilities();
    }

    wp_safe_redirect(admin_url());
    exit();
});
