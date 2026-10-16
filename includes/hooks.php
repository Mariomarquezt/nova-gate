<?php
/**
 * WordPress glue (seam S-3): feeds request facts into Gate and filters
 * Novamira's two options accordingly.
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

if (!defined('ABSPATH')) {
    exit;
}

const OPTION_KEY_HASH   = 'nova_gate_key_hash';
const OPTION_KEYS       = 'nova_gate_keys';
const OPTION_TRIES      = 'nova_gate_tries';
const OPTION_SALT       = 'nova_gate_salt';
const OPTION_OPEN_UNTIL = 'nova_gate_open_until';
const OPTION_MINUTES    = 'nova_gate_timer_minutes';
const OPTION_LAST_OPEN  = 'nova_gate_last_open';

const NOVAMIRA_ENABLED = 'novamira_ai_abilities_enabled';
const NOVAMIRA_DOMAIN  = 'novamira_ai_abilities_domain';

/** The address used when no valid IP is available (AC-49). */
const UNKNOWN_ADDRESS = 'unknown';

/**
 * Per-request memory. A class so that the cache can be reset from anywhere.
 */
final class Runtime
{
    /** @var string|null Cached Gate state for this request. */
    public static ?string $state = null;

    /**
     * @var string|null The key-header verdict for this request: missing|wrong|ok|blocked.
     *                  Set once by the first state() call and never recomputed, so one
     *                  request counts as at most one wrong try (AC-47).
     */
    public static ?string $key_header = null;

    /** @var bool When true the option filters step aside (real database values). */
    public static bool $bypass = false;

    /** @var bool False when Novamira's main file had already run when Nova Gate loaded. */
    public static bool $early = true;
}

/**
 * Attach everything. Called once from nova-gate.php, at plugin load.
 */
function boot(bool $early): void
{
    Runtime::$early = $early;

    add_filter('pre_option_' . NOVAMIRA_ENABLED, __NAMESPACE__ . '\\filter_enabled_read');
    add_filter('pre_option_' . NOVAMIRA_DOMAIN, __NAMESPACE__ . '\\filter_domain_read');
    add_filter('pre_update_option_' . NOVAMIRA_ENABLED, __NAMESPACE__ . '\\filter_enabled_write');
    add_action('add_option_' . NOVAMIRA_ENABLED, __NAMESPACE__ . '\\heal_added_switch');

    foreach ([OPTION_KEY_HASH, OPTION_KEYS, OPTION_OPEN_UNTIL] as $option) {
        add_action('add_option_' . $option, __NAMESPACE__ . '\\reset_state');
        add_action('update_option_' . $option, __NAMESPACE__ . '\\reset_state');
        add_action('delete_option_' . $option, __NAMESPACE__ . '\\reset_state');
    }

    add_action('init', __NAMESPACE__ . '\\self_heal');
    add_action('rest_api_init', __NAMESPACE__ . '\\register_routes');

    register_deactivation_hook(NOVA_GATE_FILE, __NAMESPACE__ . '\\on_deactivate');

    if (!$early) {
        // Novamira may already have registered routes using the unfiltered switch.
        // Heal now, before init, and guard actual dispatched routes (also batches).
        add_filter('rest_pre_dispatch', __NAMESPACE__ . '\\guard_late_routes', PHP_INT_MAX, 3);
        if (is_active() && !in_array(state(), [Gate::OPEN_KEY, Gate::OPEN_MANUAL], true)) {
            self_heal();
        }
    }

    if (is_admin()) {
        Admin::boot();
    }
}

function is_early(): bool
{
    return Runtime::$early;
}

/**
 * Every hash the gate is configured with. The wp-config constant wins over the
 * options; with the options, nova_gate_keys and the v0.1.0 nova_gate_key_hash
 * are both honoured. null = nothing configured (gate inactive). An array, also
 * an empty one or one full of junk, = gate active (AC-39, AC-40).
 *
 * @return string[]|null
 */
function configured_hashes(): ?array
{
    if (defined('NOVA_GATE_KEY_HASH')) {
        return Gate::parse_hash_list(constant('NOVA_GATE_KEY_HASH'));
    }

    $keys   = autoloaded_option(OPTION_KEYS);
    $legacy = autoloaded_option(OPTION_KEY_HASH);
    if ($keys === null && $legacy === null) {
        // Missing from alloptions can mean autoload is off, not that the row is absent.
        // Only when BOTH are missing (a configured site has at least one in alloptions):
        // one SELECT straight to the database. A failed read keeps the gate active and
        // matching nothing; it never turns the gate off.
        try {
            $rows = db_options([OPTION_KEYS, OPTION_KEY_HASH]);
        } catch (FreshReadFailed $e) {
            return [];
        }
        $keys   = $rows[OPTION_KEYS] ?? null;
        $legacy = $rows[OPTION_KEY_HASH] ?? null;
    }
    if ($keys === null && $legacy === null) {
        return null;
    }

    $hashes = [];
    if (is_array($keys)) {
        foreach ($keys as $entry) {
            if (is_array($entry) && isset($entry['hash']) && is_string($entry['hash'])) {
                $hashes[] = $entry['hash'];
            }
        }
    }
    if (is_string($legacy)) {
        $hashes[] = $legacy;
    }

    return $hashes;
}

/**
 * Read one of Nova Gate's own options from the autoloaded set WordPress has
 * already fetched for this request. get_option() on a missing option costs one
 * extra query on a cold cache; this costs none (AC-26, AC-61).
 * null = absent from this snapshot, not necessarily from the database.
 * Values come back as stored, so arrays are unserialized here.
 *
 * @return mixed
 */
function autoloaded_option(string $name)
{
    $all = wp_load_alloptions();
    if (!is_array($all) || !array_key_exists($name, $all)) {
        return null;
    }

    return maybe_unserialize($all[$name]);
}

function hash_is_from_constant(): bool
{
    return defined('NOVA_GATE_KEY_HASH');
}

function is_active(): bool
{
    return configured_hashes() !== null;
}

function key_header(): ?string
{
    return Address::key_header($_SERVER);
}

/** Stable limiter salt available before WordPress loads pluggable functions. */
function limiter_salt(): string
{
    if (defined('NONCE_SALT') && is_string(NONCE_SALT) && NONCE_SALT !== '') {
        return NONCE_SALT;
    }

    $salt = autoloaded_option(OPTION_SALT);
    if (is_string($salt) && $salt !== '') {
        return $salt;
    }

    // Autoload cleaners can leave a perfectly usable salt outside alloptions.
    $salt = get_option(OPTION_SALT);
    if (is_string($salt) && $salt !== '') {
        return $salt;
    }

    try {
        add_option(OPTION_SALT, bin2hex(random_bytes(32)), '', true);
    } catch (\Exception) {
        // Entropy or storage failure must not take down a keyed request.
    }
    // Another request may have inserted first. Discard both positive and
    // negative option caches before reading the persisted winner.
    wp_cache_delete(OPTION_SALT, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    $salt = get_option(OPTION_SALT);
    if (is_string($salt) && $salt !== '') {
        return $salt;
    }

    // Stable per-site fallback when storage is unavailable; no DB write and
    // no early pluggable.php load, even for a valid hub key during plugin load.
    return hash('sha256', ABSPATH . '|' . (defined('AUTH_KEY') ? (string) AUTH_KEY : '')
        . '|' . (defined('DB_NAME') ? (string) DB_NAME : ''));
}

/**
 * The gate state for this request, computed on first use. The key check, the
 * block check and the one-per-request wrong-try count happen inside the first
 * call only; later calls (after reset_state()) reuse that verdict.
 */
function state(): string
{
    if (Runtime::$state !== null) {
        return Runtime::$state;
    }

    $hashes = configured_hashes();
    $ctx    = ['key_hashes' => $hashes];
    if (Runtime::$key_header === null) {
        Runtime::$key_header = judge_key_header($hashes);
    }
    if ($hashes !== null) {
        // A matching hash alone is insufficient after a failed fresh block check.
        $ctx['header']     = Runtime::$key_header === 'ok' ? key_header() : null;
        $ctx['blocked']    = Runtime::$key_header === 'blocked';
        $ctx['route']      = Gate::resolve_route($_SERVER, $_GET, $_POST, home_path(), rest_get_url_prefix());
        $ctx['open_until'] = open_until();
        $ctx['now']        = time();
    }

    Runtime::$state = Gate::decide($ctx);

    return Runtime::$state;
}

function reset_state(): void
{
    Runtime::$state = null;
}

/** The key-header verdict of this request: missing|wrong|ok|blocked (AC-52). */
function key_header_verdict(): string
{
    state();

    return (string) Runtime::$key_header;
}

/**
 * Look at the key header exactly once per request. A wrong try is counted here
 * (AC-41, AC-47); a header from a blocked address is ignored (AC-42); with the
 * gate inactive nothing is counted (AC-45).
 *
 * @param string[]|null $hashes
 */
function judge_key_header(?array $hashes): string
{
    $header = key_header();
    if ($header === null) {
        return 'missing';
    }
    if ($hashes === null) {
        return 'wrong';
    }

    $max   = max_tries();
    $now   = time();
    $id    = Limiter::address_id(client_address(), limiter_salt());
    $table = $max > 0 ? tries_table() : [];

    if ($max > 0 && Limiter::is_blocked($table, $id, $now)) {
        return 'blocked';
    }
    if (Gate::verify_any($header, $hashes)) {
        if ($max > 0) {
            try {
                $fresh = db_option(OPTION_TRIES); // One SELECT, caches untouched: nothing is written here.
            } catch (FreshReadFailed $e) {
                return 'wrong'; // A failed block read must never authorise the key.
            }
            if (Limiter::is_blocked($fresh, $id, $now)) {
                return 'blocked';
            }
        }

        return 'ok';
    }
    if ($max > 0) {
        return with_lock('tries', static function () use ($id, $now, $max): string {
            $fresh = fresh_option(OPTION_TRIES);
            $table = is_array($fresh) ? $fresh : [];
            if (Limiter::is_blocked($table, $id, $now)) {
                return 'blocked';
            }
            update_option(OPTION_TRIES, Limiter::record($table, $id, $now, $max), true);

            return 'wrong';
        }, static function (): string {
            return 'wrong'; // Busy: do not count, and never open.
        });
    }

    return 'wrong';
}

/** NOVA_GATE_MAX_TRIES, clamped; 0 = limit off (AC-49). */
function max_tries(): int
{
    return Limiter::clamp_max(defined('NOVA_GATE_MAX_TRIES') ? constant('NOVA_GATE_MAX_TRIES') : null);
}

/** Resolve an address only across explicitly trusted proxy hops. */
function client_address(): string
{
    return Address::resolve(
        $_SERVER,
        defined('NOVA_GATE_IP_HEADER') ? constant('NOVA_GATE_IP_HEADER') : null,
        defined('NOVA_GATE_TRUSTED_PROXIES') ? constant('NOVA_GATE_TRUSTED_PROXIES') : null
    );
}

/** @param mixed $value */
function valid_ip($value): ?string
{
    return Address::valid_ip($value);
}

/** The stored wrong-try table; anything that is not an array counts as empty. */
function tries_table(): array
{
    $table = autoloaded_option(OPTION_TRIES);

    return is_array($table) ? $table : [];
}

function blocked_now(): int
{
    return Limiter::blocked_count(tries_table(), time());
}

function open_until(): int
{
    $value = autoloaded_option(OPTION_OPEN_UNTIL);

    return is_numeric($value) ? (int) $value : 0;
}

function timer_minutes(): int
{
    return Gate::clamp_minutes(get_option(OPTION_MINUTES, Gate::DEFAULT_MINUTES));
}

function home_path(): string
{
    $path = wp_parse_url(home_url(), PHP_URL_PATH);

    return is_string($path) ? $path : '';
}

function home_host(): string
{
    $host = wp_parse_url(home_url(), PHP_URL_HOST);

    return is_string($host) ? $host : '';
}

// ----------------------------------------------------------------------------
// Option filters
// ----------------------------------------------------------------------------

/**
 * pre_option_novamira_ai_abilities_enabled: false passes through, '1' opens, '0' locks.
 *
 * @param mixed $pre
 * @return mixed
 */
function filter_enabled_read($pre)
{
    if (Runtime::$bypass) {
        return $pre;
    }
    $state = state();
    if ($state === Gate::INACTIVE) {
        return $pre;
    }

    return Gate::is_open($state) ? '1' : '0';
}

/**
 * pre_option_novamira_ai_abilities_domain: the home host while open, pass through otherwise.
 *
 * @param mixed $pre
 * @return mixed
 */
function filter_domain_read($pre)
{
    if (Runtime::$bypass) {
        return $pre;
    }
    if (Gate::is_open(state())) {
        return home_host();
    }

    return $pre;
}

/**
 * pre_update_option_novamira_ai_abilities_enabled: while the gate is active the
 * stored value stays '0'; turning the switch on becomes a timed manual open,
 * turning it off removes the manual open.
 *
 * @param mixed $value
 * @return mixed
 */
function filter_enabled_write($value)
{
    if (Runtime::$bypass || state() === Gate::INACTIVE) {
        return $value;
    }

    if ($value === '1' || $value === 1 || $value === true) {
        open_manually();
    } else {
        lock_now();
    }

    return '0';
}

// ----------------------------------------------------------------------------
// Manual open / lock
// ----------------------------------------------------------------------------

/**
 * Who may open the gate by hand (AC-10). WP-CLI always may; otherwise a
 * logged-in manage_options user who did not authenticate with an application password.
 */
function may_open_manually(): bool
{
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }
    if (!function_exists('wp_get_current_user') || !function_exists('current_user_can')) {
        return false; // Too early in the request: pluggable functions are not loaded yet.
    }

    $user = wp_get_current_user(); // Resolves authentication first, so did_action() below is reliable.
    if (!($user instanceof \WP_User) || !$user->exists()) {
        return false;
    }
    if (did_action('application_password_did_authenticate') > 0) {
        return false;
    }

    return current_user_can('manage_options');
}

/** @param int|null $minutes Already clamped by the caller; null = the saved timer. */
function open_manually(?int $minutes = null): bool
{
    if (!may_open_manually()) {
        return false;
    }

    $now  = time();
    $user = function_exists('get_current_user_id') ? get_current_user_id() : 0;

    update_option(OPTION_OPEN_UNTIL, $now + ($minutes ?? timer_minutes()) * 60, true);
    update_option(OPTION_LAST_OPEN, ['user' => (int) $user, 'time' => $now], true);
    reset_state();

    return true;
}

function lock_now(): void
{
    delete_option(OPTION_OPEN_UNTIL);
    reset_state();
}

// ----------------------------------------------------------------------------
// Real (unfiltered) access to Novamira's two options
// ----------------------------------------------------------------------------

/**
 * Run $fn with the option filters stepping aside, so get_option/update_option
 * see the real database values. Restores the previous mode even on exceptions.
 *
 * @return mixed Whatever $fn returns.
 */
function with_real_options(callable $fn)
{
    $previous        = Runtime::$bypass;
    Runtime::$bypass = true;
    try {
        return $fn();
    } finally {
        Runtime::$bypass = $previous;
    }
}

/**
 * @return mixed
 */
function real_option(string $name)
{
    return with_real_options(static function () use ($name) {
        return get_option($name, false);
    });
}

/**
 * Set Novamira's database switch to off. The only write Nova Gate ever makes
 * to Novamira's options.
 */
function force_switch_off(): void
{
    with_real_options(static function (): void {
        update_option(NOVAMIRA_ENABLED, '0');
        delete_option(NOVAMIRA_DOMAIN);
    });
    reset_state();
}

/**
 * AC-18: while the gate is active the database switch must never be '1'.
 */
function self_heal(): void
{
    if (!is_active()) {
        return;
    }
    $real = real_option(NOVAMIRA_ENABLED);
    if ($real === '1' || $real === 1 || $real === true) {
        force_switch_off();
    }
}

function on_deactivate(): void
{
    delete_option(OPTION_OPEN_UNTIL);
}

/** An add bypasses pre_update_option; correct it in the adding request. */
function heal_added_switch(): void
{
    if (!Runtime::$bypass && is_active()) {
        force_switch_off();
    }
}

/**
 * A late-loaded gate cannot undo Novamira's route registration. Refuse those
 * routes before dispatch unless this request has a key or a manual open.
 * OPEN_LINK deliberately does not bypass this late-load guard.
 *
 * @param mixed $result
 * @param mixed $server
 * @return mixed
 */
function guard_late_routes($result, $server, \WP_REST_Request $request)
{
    if (Runtime::$early || !is_active()
        || in_array(state(), [Gate::OPEN_KEY, Gate::OPEN_MANUAL], true)) {
        return $result;
    }
    $route = $request->get_route();
    if (preg_match('#^/(?:novamira(?:/|$)|mcp/(?:novamira[^/]*|mcp-adapter-default-server)(?:/|$)|wp-abilities/v1/abilities/novamira(?:/|$))#i', $route) === 1) {
        return new \WP_Error('rest_no_route', __('No route was found matching the URL and request method.'), ['status' => 404]);
    }

    return $result;
}
