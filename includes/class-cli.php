<?php
/**
 * WP-CLI commands (seam S-10): wp nova-gate … (AC-53 to AC-59).
 *
 * Loaded only when WP_CLI is defined and true (see nova-gate.php). Every change
 * to keys and blocks goes through includes/keys.php, the same functions the
 * settings page uses.
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    exit;
}

/**
 * Nova Gate: lock Novamira's AI abilities behind X-Nova-Gate-Key.
 */
final class CLI
{
    /**
     * Shows the state of the gate.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp nova-gate status
     *     wp nova-gate status --format=json
     *
     * @param string[] $args
     * @param array<string, string> $assoc_args
     */
    public function status(array $args, array $assoc_args): void
    {
        $until = open_until();
        if (!is_active()) {
            $state = 'inactive';
        } elseif ($until > time()) {
            $state = 'open until ' . Admin::local_time($until);
        } else {
            $state = 'locked';
        }

        if (hash_is_from_constant()) {
            $source = 'wp-config';
            $count  = count(array_filter(configured_hashes() ?? [], [Gate::class, 'is_valid_hash']));
        } else {
            $source = is_active() ? 'options' : 'none';
            $count  = count(list_keys());
        }

        $data = [
            'state'         => $state,
            'key_source'    => $source,
            'keys'          => $count,
            'early'         => is_early() ? 'yes' : 'no',
            'timer_minutes' => timer_minutes(),
            'blocked'       => blocked_now(),
        ];

        $format = (string) ($assoc_args['format'] ?? 'table');
        if ($format === 'json') {
            \WP_CLI::print_value($data, ['format' => 'json']);

            return;
        }
        $rows = [];
        foreach ($data as $field => $value) {
            $rows[] = ['field' => $field, 'value' => $value];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
    }

    /**
     * Opens the gate by hand for a while, like an admin turning Novamira's switch on.
     *
     * ## OPTIONS
     *
     * [--minutes=<minutes>]
     * : How long the gate stays open, 5 to 240. Default: the saved timer.
     *
     * ## EXAMPLES
     *
     *     wp nova-gate open
     *     wp nova-gate open --minutes=10
     *
     * @param string[] $args
     * @param array<string, string> $assoc_args
     */
    public function open(array $args, array $assoc_args): void
    {
        if (!is_active()) {
            \WP_CLI::error('The gate is inactive (no key configured), so there is nothing to open.');
        }
        $minutes = isset($assoc_args['minutes']) ? Gate::clamp_minutes($assoc_args['minutes']) : timer_minutes();
        if (!open_manually($minutes)) {
            \WP_CLI::error('The gate could not be opened.');
        }
        \WP_CLI::success(sprintf('Gate open for %d minutes, until %s.', $minutes, Admin::local_time(open_until())));
    }

    /**
     * Removes a manual open. Requests without a key are locked again at once.
     *
     * ## EXAMPLES
     *
     *     wp nova-gate lock
     */
    public function lock(): void
    {
        lock_now();
        \WP_CLI::success('Gate locked.');
    }

    /**
     * Forgets every wrong try and every block.
     *
     * ## EXAMPLES
     *
     *     wp nova-gate unblock
     */
    public function unblock(): void
    {
        $count = clear_blocks();
        if ($count instanceof \WP_Error) {
            \WP_CLI::error($count->get_error_message());
        }
        \WP_CLI::success(sprintf('Blocks cleared. %d address(es) were blocked.', $count));
    }
}

/**
 * Manages Nova Gate keys. Only hashes are stored; a key is printed once, when it is made.
 */
final class CLI_Keys
{
    /**
     * Lists every key: id, label and creation time. Never a hash, never a key.
     *
     * While NOVA_GATE_KEY_HASH is set in wp-config.php only its hashes open the gate, so
     * they are listed (as const-1, const-2, …) and the keys stored in the database are not.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp nova-gate key list
     *
     * @param string[] $args
     * @param array<string, string> $assoc_args
     */
    public function list(array $args, array $assoc_args): void
    {
        $format = (string) ($assoc_args['format'] ?? 'table');
        if (hash_is_from_constant()) {
            \WP_CLI::warning('NOVA_GATE_KEY_HASH is set in wp-config.php: only its hashes open the gate, and the keys stored in the database are ignored until it is removed.');
            $rows = [];
            foreach (array_values(configured_hashes() ?? []) as $n => $hash) {
                $rows[] = [
                    'id'      => 'const-' . ($n + 1),
                    'label'   => Gate::is_valid_hash($hash) ? 'from wp-config.php' : 'from wp-config.php (not a valid SHA-256 hash; matches no key)',
                    'created' => '-',
                ];
            }
            \WP_CLI\Utils\format_items($format, $rows, ['id', 'label', 'created']);

            return;
        }

        $rows = [];
        foreach (list_keys() as $id => $key) {
            $rows[] = [
                'id'      => (string) $id,
                'label'   => $key['label'],
                'created' => $key['created'] > 0 ? Admin::local_time((int) $key['created']) : 'before v0.2.0',
            ];
        }
        \WP_CLI\Utils\format_items($format, $rows, ['id', 'label', 'created']);
    }

    /**
     * Adds a key. Without --hash a key is generated and printed once.
     *
     * ## OPTIONS
     *
     * <id>
     * : The key id: 1 to 40 characters, [a-z0-9][a-z0-9-]*.
     *
     * [--label=<label>]
     * : A label, 1 to 60 characters. Default: the id.
     *
     * [--hash=<sha256>]
     * : Store this SHA-256 hex digest instead of generating a key. No key is printed,
     * only a Success line (nothing at all with --porcelain).
     *
     * [--porcelain]
     * : Print only the new key (with --hash: print nothing).
     *
     * ## EXAMPLES
     *
     *     wp nova-gate key add laptop --label="My laptop"
     *     KEY=$(wp nova-gate key add ci --porcelain)
     *
     * @param string[] $args
     * @param array<string, string|bool> $assoc_args
     */
    public function add(array $args, array $assoc_args): void
    {
        $id    = (string) ($args[0] ?? '');
        $label = isset($assoc_args['label']) ? (string) $assoc_args['label'] : $id;
        $hash  = isset($assoc_args['hash']) ? strtolower(trim((string) $assoc_args['hash'])) : null;

        $result = add_key($id, $label, $hash);
        if ($result instanceof \WP_Error) {
            \WP_CLI::error($result->get_error_message());
        }

        $porcelain = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'porcelain', false);
        if ($hash !== null) {
            if (!$porcelain) {
                \WP_CLI::success(sprintf('Key "%s" stored from its hash.', $id));
            }

            return;
        }
        if ($porcelain) {
            \WP_CLI::line($result);

            return;
        }
        \WP_CLI::success(sprintf('Key "%s" added. Copy it now; it is shown only this once and is not stored anywhere:', $id));
        \WP_CLI::line($result);
    }

    /**
     * Removes a key. Removing the last key makes the gate inactive.
     *
     * ## OPTIONS
     *
     * <id>
     * : The key id.
     *
     * [--yes]
     * : Do not ask for confirmation when this is the last key.
     *
     * ## EXAMPLES
     *
     *     wp nova-gate key remove laptop
     *     wp nova-gate key remove ci --yes
     *
     * @param string[] $args
     * @param array<string, string|bool> $assoc_args
     */
    public function remove(array $args, array $assoc_args): void
    {
        $id   = (string) ($args[0] ?? '');
        $keys = list_keys();
        if (!hash_is_from_constant() && isset($keys[$id]) && count($keys) === 1) {
            \WP_CLI::confirm('This is the last key. Removing it makes the gate inactive, so Nova Gate no longer locks Novamira. Continue?', $assoc_args);
        }

        $result = remove_key($id);
        if ($result instanceof \WP_Error) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success(is_active()
            ? sprintf('Key "%s" removed. The other keys keep working.', $id)
            : sprintf('Key "%s" removed. The gate is inactive.', $id));
    }
}

\WP_CLI::add_command('nova-gate', CLI::class);
\WP_CLI::add_command('nova-gate key', CLI_Keys::class);
