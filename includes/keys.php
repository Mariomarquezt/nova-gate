<?php
/**
 * Key functions (seam S-9). The settings page and the WP-CLI commands change
 * keys and blocks only through these functions, so the rules live once.
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

if (!defined('ABSPATH')) {
    exit;
}

const DEFAULT_KEY_ID = 'default';

/** The name of the lock (includes/lock.php) around every read-modify-write of the key table. */
const KEYS_LOCK = 'keys';

/**
 * Every key: [id => ['label' => string, 'created' => int]]. Never a hash.
 * A v0.1.0 site that still has only nova_gate_key_hash shows it as `default`
 * without any write (AC-33, AC-37).
 */
function list_keys(): array
{
    $list = [];
    foreach (stored_keys() as $id => $entry) {
        $list[$id] = ['label' => $entry['label'], 'created' => $entry['created']];
    }

    return $list;
}

/**
 * Add a key. Returns the new key (64 hex) or a \WP_Error with code
 * constant | bad_id | exists | full | bad_hash | busy. With $hash given, that hash is
 * stored and '' is returned.
 *
 * The read, the checks and the write run under the key-table lock on a fresh read, so
 * a concurrent remove can never be undone by this add, nor this add lost to another.
 *
 * @return string|\WP_Error
 */
function add_key(string $id, string $label, ?string $hash = null)
{
    if (hash_is_from_constant()) {
        return new \WP_Error('constant', __('The key hashes are set in wp-config.php (NOVA_GATE_KEY_HASH), so keys cannot be changed here.', 'nova-gate'));
    }
    if (!Gate::is_valid_key_id($id)) {
        return new \WP_Error('bad_id', __('The key id must be 1 to 40 characters: lower-case letters, digits and hyphens, starting with a letter or digit.', 'nova-gate'));
    }
    if ($hash !== null && !Gate::is_valid_hash($hash)) {
        return new \WP_Error('bad_hash', __('The hash must be 64 lower-case hex characters (SHA-256).', 'nova-gate'));
    }

    $label = trim($label);
    if ($label === '') {
        $label = $id;
    }
    if (mb_strlen($label) > 60) {
        $label = mb_substr($label, 0, 60);
    }

    return with_lock(KEYS_LOCK, static function () use ($id, $label, $hash) {
        $keys = stored_keys(true);
        if (isset($keys[$id])) {
            /* translators: %s: key id */
            return new \WP_Error('exists', sprintf(__('A key with the id "%s" already exists.', 'nova-gate'), $id));
        }
        if (count($keys) >= Gate::MAX_KEYS) {
            /* translators: %d: maximum number of keys */
            return new \WP_Error('full', sprintf(__('This site already has %d keys, the maximum. Remove one first.', 'nova-gate'), Gate::MAX_KEYS));
        }

        // An empty v0.1.0 hash is listed but opens nothing, so it does not count as a key here.
        $first = array_filter(array_column($keys, 'hash'), static fn ($h): bool => $h !== '') === [];
        $key   = $hash === null ? Gate::generate_key() : '';
        $keys[$id] = [
            'label'   => $label,
            'hash'    => $hash ?? Gate::hash_key($key),
            'created' => time(),
        ];
        save_keys($keys);
        if ($first) {
            force_switch_off(); // The first key locks Novamira's database switch (AC-13, AC-35).
        }
        reset_state();

        return $key;
    }, __NAMESPACE__ . '\\keys_busy');
}

/**
 * Remove one key. true, or a \WP_Error with code constant | unknown | busy. When it was
 * the last key, nova_gate_keys, nova_gate_key_hash and nova_gate_open_until are
 * deleted and the gate becomes inactive (AC-36). Runs under the key-table lock on a
 * fresh read, like add_key().
 *
 * @return true|\WP_Error
 */
function remove_key(string $id)
{
    if (hash_is_from_constant()) {
        return new \WP_Error('constant', __('The key hashes are set in wp-config.php (NOVA_GATE_KEY_HASH), so keys cannot be changed here.', 'nova-gate'));
    }

    return with_lock(KEYS_LOCK, static function () use ($id) {
        $keys = stored_keys(true);
        if (!isset($keys[$id])) {
            /* translators: %s: key id */
            return new \WP_Error('unknown', sprintf(__('There is no key with the id "%s".', 'nova-gate'), $id));
        }
        unset($keys[$id]);

        if ($keys === []) {
            delete_option(OPTION_KEYS);
            delete_option(OPTION_KEY_HASH);
            delete_option(OPTION_OPEN_UNTIL);
        } else {
            save_keys($keys);
        }
        reset_state();

        return true;
    }, __NAMESPACE__ . '\\keys_busy');
}

/** Shared failure for key changes and clearing blocks when the lock or fresh read fails. */
function keys_busy(): \WP_Error
{
    return new \WP_Error('busy', __('Another change is in progress. Nothing was changed; try again in a moment.', 'nova-gate'));
}

/**
 * Forget every wrong try and block under the same lock as the tries writer.
 *
 * @return int|\WP_Error Fresh blocked count, or busy without changes.
 */
function clear_blocks()
{
    return with_lock('tries', static function (): int {
        $blocked = Limiter::blocked_count(fresh_option(OPTION_TRIES), time());
        delete_option(OPTION_TRIES);

        return $blocked;
    }, __NAMESPACE__ . '\\keys_busy');
}

// ----------------------------------------------------------------------------
// Storage
// ----------------------------------------------------------------------------

/**
 * The keys as stored, well-formed entries only: [id => ['label', 'hash', 'created']].
 * The v0.1.0 single hash is honoured by the gate whenever it exists, so it is always
 * listed too: as `default`, or as `legacy` when that id is taken by another hash.
 * No key that opens the gate is ever hidden from the list. An empty v0.1.0 hash keeps
 * the gate active and matches nothing; it is listed too, with a label that says so.
 * Reads only; the fold-in write happens in save_keys().
 *
 * @param bool $fresh Read past every cache (for use under the key-table lock).
 */
function stored_keys(bool $fresh = false): array
{
    $keys = [];
    $raw  = $fresh ? fresh_option(OPTION_KEYS) : get_option(OPTION_KEYS, null);
    if (is_array($raw)) {
        foreach ($raw as $id => $entry) {
            if (!is_array($entry) || !isset($entry['hash']) || !is_string($entry['hash'])) {
                continue;
            }
            $id        = (string) $id; // PHP stores an all-digit id such as "2026" as an integer key.
            $keys[$id] = [
                'label'   => isset($entry['label']) && is_string($entry['label']) ? $entry['label'] : $id,
                'hash'    => $entry['hash'],
                'created' => isset($entry['created']) && is_numeric($entry['created']) ? (int) $entry['created'] : 0,
            ];
        }
    }

    $legacy = $fresh ? fresh_option(OPTION_KEY_HASH) : get_option(OPTION_KEY_HASH, null);
    if (is_string($legacy) && !in_array($legacy, array_column($keys, 'hash'), true)) {
        $label = $legacy === '' ? __('Empty legacy key (cannot match; add a key)', 'nova-gate') : __('Default key', 'nova-gate');
        $keys[legacy_key_id($keys)] = ['label' => $label, 'hash' => $legacy, 'created' => 0];
    }

    return $keys;
}

/** The id for the v0.1.0 hash: `default`, or the first free one of legacy, legacy-2, … */
function legacy_key_id(array $keys): string
{
    if (!isset($keys[DEFAULT_KEY_ID])) {
        return DEFAULT_KEY_ID;
    }
    $id = 'legacy';
    for ($n = 2; isset($keys[$id]); $n++) {
        $id = 'legacy-' . $n;
    }

    return $id;
}

/**
 * Write the key table (autoloaded). This is also the fold-in of AC-37: once the
 * table exists the v0.1.0 option is deleted, because its hash now lives in `default`.
 */
function save_keys(array $keys): void
{
    update_option(OPTION_KEYS, $keys, true);
    if (get_option(OPTION_KEY_HASH, null) !== null) {
        delete_option(OPTION_KEY_HASH);
    }
}
