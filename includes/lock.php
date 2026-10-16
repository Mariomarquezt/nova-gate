<?php
/**
 * A short named lock for the few read-modify-write paths of Nova Gate (the key
 * table and the wrong-try table). Uses MySQL / MariaDB GET_LOCK through $wpdb.
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

if (!defined('ABSPATH')) {
    exit;
}

/** Seconds a writer waits for the lock before it gives up. */
const LOCK_WAIT = 5;

/** fresh_option() could not read the database. Never treated as "the option is absent". */
final class FreshReadFailed extends \RuntimeException
{
}

/**
 * Run $fn while holding the named lock, and return what it returns.
 *
 * $on_busy() is returned instead, and $fn does not run, when another writer holds
 * the lock longer than LOCK_WAIT, when GET_LOCK answers NULL, or when the lock
 * query fails. Only a database that has no GET_LOCK at all (for example SQLite)
 * runs $fn without a lock: the old behaviour, best effort. A FreshReadFailed
 * thrown inside $fn also ends in $on_busy(), so a failed read is never saved
 * back as an empty table.
 *
 * @return mixed
 */
function with_lock(string $name, callable $fn, callable $on_busy)
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
        return run_guarded($fn, $on_busy);
    }

    $lock     = 'nova_gate_' . $name . '_' . (string) get_current_blog_id();
    $suppress = $wpdb->suppress_errors(true);
    $got      = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, LOCK_WAIT));
    $error    = (string) $wpdb->last_error;
    $wpdb->suppress_errors($suppress);

    if ($got === null) {
        if ($error !== '' && stripos($error, 'get_lock') !== false) {
            return run_guarded($fn, $on_busy); // The database has no GET_LOCK: best effort, as before.
        }

        return $on_busy(); // GET_LOCK returned NULL, or the query failed.
    }
    if ((string) $got !== '1') {
        return $on_busy();
    }

    try {
        return run_guarded($fn, $on_busy);
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

/** @return mixed $fn(), or $on_busy() when $fn hit a failed fresh read. */
function run_guarded(callable $fn, callable $on_busy)
{
    try {
        return $fn();
    } catch (FreshReadFailed $e) {
        return $on_busy();
    }
}

/**
 * Read an option straight from the database, past every cache, for use inside
 * with_lock(). A cached copy (also a persistent object cache that another
 * request may refill with an old snapshot) can be older than the row another
 * writer just saved. The option caches are cleared too, so the update_option()
 * that follows compares against the database row, not a stale copy.
 *
 * @return mixed The stored value (unserialized), or null when the option does not exist.
 * @throws FreshReadFailed When the query fails; a failed read is not an absent option.
 */
function fresh_option(string $name)
{
    forget_cached_option($name);

    return db_option($name);
}

/**
 * The option's row as stored in the database, read with one SELECT and without
 * touching any cache. For read-only checks that must not trust a cached copy.
 *
 * @return mixed The stored value (unserialized), or null when the option does not exist.
 * @throws FreshReadFailed When the query fails.
 */
function db_option(string $name)
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
        return get_option($name, null);
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
    if ((string) $wpdb->last_error !== '') {
        throw new FreshReadFailed('Nova Gate could not read the option ' . $name . '.');
    }
    if (!is_object($row) || !isset($row->option_value)) {
        return null;
    }

    return maybe_unserialize($row->option_value);
}

/**
 * Several options' rows as stored, read with ONE SELECT and without touching any cache.
 *
 * @param string[] $names
 * @return array<string, mixed> name => stored value (unserialized), only for rows that exist.
 * @throws FreshReadFailed When the query fails.
 */
function db_options(array $names): array
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        $found = [];
        foreach ($names as $name) {
            $value = get_option($name, null);
            if ($value !== null) {
                $found[$name] = $value;
            }
        }

        return $found;
    }
    if ($names === []) {
        return [];
    }

    $marks = implode(', ', array_fill(0, count($names), '%s'));
    $rows  = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($marks)", ...$names));
    if ((string) $wpdb->last_error !== '' || !is_array($rows)) {
        throw new FreshReadFailed('Nova Gate could not read its options.');
    }
    $found = [];
    foreach ($rows as $row) {
        if (is_object($row) && isset($row->option_name, $row->option_value)) {
            $found[(string) $row->option_name] = maybe_unserialize($row->option_value);
        }
    }

    return $found;
}

/** Drop every cached copy of one option (its own entry, alloptions, notoptions). */
function forget_cached_option(string $name): void
{
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}
