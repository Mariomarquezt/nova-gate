<?php
/**
 * Status route (seams S-4, S-8): GET /wp-json/nova-gate/v1/status
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

if (!defined('ABSPATH')) {
    exit;
}

function register_routes(): void
{
    register_rest_route('nova-gate/v1', '/status', [
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => __NAMESPACE__ . '\\status_response',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Public, login-free. Says whether the gate is active and how the key header
 * was judged (missing, wrong, ok or blocked). It reports the verdict stored by
 * the early decision instead of checking the key again, so the request is
 * counted once (AC-47). Never leaks a hash, a key, how many keys exist, the
 * timer or any user data.
 */
function status_response(): \WP_REST_Response
{
    $response = new \WP_REST_Response([
        'nova_gate'  => NOVA_GATE_VERSION,
        'active'     => is_active(),
        'early'      => is_early(),
        'key_header' => key_header_verdict(),
    ], 200);
    $response->header('Cache-Control', 'no-store');

    return $response;
}
