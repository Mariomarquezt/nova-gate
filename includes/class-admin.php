<?php
/**
 * Settings page, notices and admin actions (AC-13 to AC-17, AC-22 to AC-24,
 * AC-35 to AC-38, AC-50, AC-51).
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    public const PAGE_SLUG  = 'nova-gate';
    public const SCREEN_ID  = 'settings_page_nova-gate';
    public const NONCE_NAME = 'nova_gate_nonce';
    public const MSG_PARAM  = 'nova_gate_msg';

    /** @var string|null A freshly created key. Lives only in this request, shown once. */
    private ?string $new_key = null;

    public static function boot(): void
    {
        $admin = new self();
        add_action('admin_menu', [$admin, 'add_menu']);
        add_action('load-' . self::SCREEN_ID, [$admin, 'handle_page_post']);
        add_action('admin_post_nova_gate_lock', [$admin, 'handle_lock_link']);
        add_action('admin_notices', [$admin, 'notices']);
        add_action('admin_enqueue_scripts', [$admin, 'assets']);
    }

    public function add_menu(): void
    {
        add_options_page(
            __('Nova Gate', 'nova-gate'),
            __('Nova Gate', 'nova-gate'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public static function page_url(): string
    {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    // ------------------------------------------------------------------ actions

    /**
     * Forms on the settings page post back to the page itself. Runs before any
     * output, so "create" and "replace" can show the new key in this response.
     */
    public function handle_page_post(): void
    {
        if (!isset($_POST['nova_gate_action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'nova-gate'), '', ['response' => 403]);
        }

        $action = sanitize_key((string) wp_unslash($_POST['nova_gate_action']));
        check_admin_referer('nova_gate_' . $action, self::NONCE_NAME);

        switch ($action) {
            case 'add':
                $label = isset($_POST['nova_gate_label']) ? sanitize_text_field((string) wp_unslash($_POST['nova_gate_label'])) : '';
                $label = trim($label);
                if ($label === '' || mb_strlen($label) > 60) {
                    $this->redirect('bad_label');
                }
                $result = add_key(self::id_from_label($label), $label);
                if ($result instanceof \WP_Error) {
                    $this->redirect((string) $result->get_error_code());
                }
                $this->new_key = (string) $result;
                return; // No redirect: the key is rendered in this very response.

            case 'remove':
                $id     = isset($_POST['nova_gate_key_id']) ? (string) wp_unslash($_POST['nova_gate_key_id']) : '';
                $result = remove_key($id);
                if ($result instanceof \WP_Error) {
                    $this->redirect((string) $result->get_error_code());
                }
                $this->redirect(is_active() ? 'removed' : 'removed_last');
                return;

            case 'clear_blocks':
                $result = clear_blocks();
                if ($result instanceof \WP_Error) {
                    $this->redirect((string) $result->get_error_code());
                }
                $this->redirect('unblocked');
                return;

            case 'lock':
                lock_now();
                $this->redirect('locked');
                return;

            case 'timer':
                $raw = isset($_POST['nova_gate_minutes']) ? wp_unslash($_POST['nova_gate_minutes']) : null;
                update_option(OPTION_MINUTES, Gate::clamp_minutes($raw));
                $this->redirect('timer');
                return;
        }

        $this->redirect('unknown');
    }

    /**
     * AC-35: the id is sanitize_title(label) cut to 40 characters, nothing more. A label
     * that gives an id outside the key-id rule (an underscore, a non-Latin letter) is
     * refused by add_key(); it is never silently turned into another id.
     */
    public static function id_from_label(string $label): string
    {
        return substr(sanitize_title($label), 0, 40);
    }

    /** "Lock now" link from the admin notice: admin-post.php?action=nova_gate_lock&_wpnonce=… */
    public function handle_lock_link(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'nova-gate'), '', ['response' => 403]);
        }
        check_admin_referer('nova_gate_lock');
        lock_now();

        $back = wp_get_referer();
        if (!is_string($back) || $back === '') {
            $back = self::page_url();
        }
        wp_safe_redirect(add_query_arg(self::MSG_PARAM, 'locked', remove_query_arg(self::MSG_PARAM, $back)));
        exit;
    }

    private function redirect(string $message): void
    {
        wp_safe_redirect(add_query_arg(self::MSG_PARAM, $message, self::page_url()));
        exit;
    }

    public static function lock_url(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=nova_gate_lock'), 'nova_gate_lock');
    }

    // ------------------------------------------------------------------ notices

    public function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen    = get_current_screen();
        $screen_id = $screen instanceof \WP_Screen ? $screen->id : '';

        if (!is_early()) {
            $this->notice('warning', sprintf(
                /* translators: 1: folder name, 2: file name */
                __('Nova Gate loaded after Novamira, so Novamira read its switch before Nova Gate could lock it. Fix: keep the plugin folder named %1$s (it must sort before "novamira"), or load Nova Gate from a must-use plugin loader as shown in %2$s, section "Load before Novamira".', 'nova-gate'),
                '<code>nova-gate</code>',
                '<code>README.md</code>'
            ), true);
        }

        if (!is_active() && in_array($screen_id, ['plugins', 'plugins-network', self::SCREEN_ID], true)) {
            $this->notice('warning', sprintf(
                /* translators: %s: link to the settings page */
                __('Nova Gate has no key yet. Novamira works as if Nova Gate were not installed. %s', 'nova-gate'),
                '<a href="' . esc_url(self::page_url()) . '">' . esc_html__('Add a key', 'nova-gate') . '</a>'
            ), true);
        }

        if ($screen_id === self::SCREEN_ID && defined('NOVA_GATE_IP_HEADER')
            && (!defined('NOVA_GATE_TRUSTED_PROXIES') || !Address::has_valid_ranges(constant('NOVA_GATE_TRUSTED_PROXIES')))) {
            $this->notice('warning', esc_html__(
                'NOVA_GATE_IP_HEADER is ignored because NOVA_GATE_TRUSTED_PROXIES is missing or has no valid IP address or CIDR. The wrong-try limit uses REMOTE_ADDR. Configure only the IP addresses or CIDRs of proxies you control.',
                'nova-gate'
            ));
        }

        if ($screen_id === self::SCREEN_ID && !hash_is_from_constant()
            && get_option(OPTION_KEY_HASH, null) === '') { // Also when the row is not autoloaded.
            $this->notice('warning', esc_html__(
                'The legacy key is empty and cannot unlock the gate. The gate stays active; add a usable key and remove the empty legacy entry.',
                'nova-gate'
            ));
        }

        $until = open_until();
        if (is_active() && $until > time()) {
            $this->notice('info', sprintf(
                /* translators: 1: time, 2: lock link */
                __('Gate open. Locks again at %1$s. %2$s', 'nova-gate'),
                esc_html(self::local_time($until)),
                '<a href="' . esc_url(self::lock_url()) . '">' . esc_html__('Lock now', 'nova-gate') . '</a>'
            ), true);
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if (is_active() && $page !== '' && strncmp($page, 'novamira', 8) === 0) {
            $this->notice('info', esc_html(sprintf(
                /* translators: %d: minutes */
                _n(
                    'Nova Gate controls this switch. Turning it on opens the gate for %d minute.',
                    'Nova Gate controls this switch. Turning it on opens the gate for %d minutes.',
                    timer_minutes(),
                    'nova-gate'
                ),
                timer_minutes()
            )));
        }

        if ($screen_id === self::SCREEN_ID && is_active() && blocked_now() > 0) {
            $this->notice('warning', esc_html(sprintf(
                /* translators: %d: number of blocked addresses */
                _n(
                    '%d address is blocked right now because it sent too many wrong keys. Requests from it are refused, also with a right key, until the block ends.',
                    '%d addresses are blocked right now because they sent too many wrong keys. Requests from them are refused, also with a right key, until the block ends.',
                    blocked_now(),
                    'nova-gate'
                ),
                blocked_now()
            )));
        }

        $msg = isset($_GET[self::MSG_PARAM]) ? sanitize_key((string) wp_unslash($_GET[self::MSG_PARAM])) : '';
        $success = [
            'removed'      => __('Key removed. The other keys keep working.', 'nova-gate'),
            'removed_last' => __('Last key removed. The gate is inactive. Novamira\'s switch stays off until someone turns it on.', 'nova-gate'),
            'locked'       => __('Gate locked.', 'nova-gate'),
            'timer'        => __('Timer saved.', 'nova-gate'),
            'unblocked'    => __('Blocks cleared. Every address may try again.', 'nova-gate'),
        ];
        $errors = [
            'bad_label' => __('Give the key a label of 1 to 60 characters.', 'nova-gate'),
            'bad_id'    => __('That label gives no usable id. Use at least one letter or digit.', 'nova-gate'),
            'exists'    => __('A key with that id already exists. Choose another label.', 'nova-gate'),
            'busy'      => __('Another change is in progress. Nothing was changed; try again in a moment.', 'nova-gate'),
            'full'      => sprintf(
                /* translators: %d: maximum number of keys */
                __('This site already has %d keys, the maximum. Remove one first.', 'nova-gate'),
                Gate::MAX_KEYS
            ),
            'unknown'   => __('Unknown key or action.', 'nova-gate'),
            'constant'  => __('The key hashes are set in wp-config.php, so they cannot be changed here.', 'nova-gate'),
        ];
        if (isset($success[$msg])) {
            $this->notice('success', esc_html($success[$msg]));
        } elseif (isset($errors[$msg])) {
            $this->notice('error', esc_html($errors[$msg]));
        }
    }

    /** $html is already escaped by the caller (plain text goes through esc_html first). */
    private function notice(string $type, string $html, bool $has_links = false): void
    {
        printf(
            '<div class="notice notice-%1$s"><p>%2$s</p></div>',
            esc_attr($type),
            $has_links ? wp_kses($html, ['a' => ['href' => [], 'target' => [], 'rel' => []], 'code' => []]) : $html
        );
    }

    public static function local_time(int $timestamp): string
    {
        return (string) wp_date(
            get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'),
            $timestamp
        );
    }

    // ------------------------------------------------------------------ assets

    public function assets(string $hook_suffix): void
    {
        if ($hook_suffix !== self::SCREEN_ID) {
            return;
        }
        wp_register_style('nova-gate-admin', false, [], NOVA_GATE_VERSION);
        wp_enqueue_style('nova-gate-admin');
        wp_add_inline_style('nova-gate-admin', '
            .nova-gate-key { font-size: 1.1em; padding: 8px 10px; display: inline-block; user-select: all; }
            .nova-gate-new-key { border-left-color: #d63638; }
            .nova-gate-example { position: relative; }
            .nova-gate-example pre { padding: 10px 90px 10px 10px; background: #f6f7f7; border: 1px solid #c3c4c7; overflow: auto; white-space: pre-wrap; word-break: break-all; }
            .nova-gate-example .nova-gate-copy { position: absolute; top: 8px; right: 8px; }
            .nova-gate-footer { margin-top: 2em; color: #646970; }
        ');

        wp_register_script('nova-gate-admin', false, [], NOVA_GATE_VERSION, true);
        wp_enqueue_script('nova-gate-admin');
        wp_add_inline_script('nova-gate-admin', '
            document.querySelectorAll(".nova-gate-copy").forEach(function (button) {
                button.addEventListener("click", function () {
                    var target = document.getElementById(button.getAttribute("data-target"));
                    if (!target || !navigator.clipboard) { return; }
                    navigator.clipboard.writeText(target.textContent).then(function () {
                        var label = button.textContent;
                        button.textContent = button.getAttribute("data-copied");
                        setTimeout(function () { button.textContent = label; }, 1500);
                    });
                });
            });
            document.querySelectorAll("form[data-confirm]").forEach(function (form) {
                form.addEventListener("submit", function (event) {
                    if (!window.confirm(form.getAttribute("data-confirm"))) { event.preventDefault(); }
                });
            });
        ');
    }

    // ------------------------------------------------------------------ page

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to see this page.', 'nova-gate'), '', ['response' => 403]);
        }

        $active   = is_active();
        $until    = open_until();
        $is_open  = $active && $until > time();
        $mcp_url  = rest_url('mcp/novamira');
        $status   = rest_url('nova-gate/v1/status');
        $minutes  = timer_minutes();
        ?>
        <div class="wrap nova-gate">
            <h1><?php esc_html_e('Nova Gate – Access Lock for Novamira', 'nova-gate'); ?></h1>

            <?php if ($this->new_key !== null) : ?>
                <div class="notice notice-warning nova-gate-new-key inline">
                    <p><strong><?php esc_html_e('Your new key. Copy it now. It is shown only this once and is not stored anywhere.', 'nova-gate'); ?></strong></p>
                    <p>
                        <code class="nova-gate-key" id="nova-gate-new-key"><?php echo esc_html($this->new_key); ?></code>
                        <button type="button" class="button nova-gate-copy" data-target="nova-gate-new-key" data-copied="<?php esc_attr_e('Copied', 'nova-gate'); ?>"><?php esc_html_e('Copy', 'nova-gate'); ?></button>
                    </p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Status', 'nova-gate'); ?></h2>
            <?php
            if (!$active) {
                $status_text = __('No key yet', 'nova-gate');
            } elseif ($is_open) {
                /* translators: %s: site-local date and time */
                $status_text = sprintf(__('Open until %s', 'nova-gate'), self::local_time($until));
            } else {
                $status_text = __('Locked', 'nova-gate');
            }
            ?>
            <p><strong><?php echo esc_html($status_text); ?></strong></p>
            <?php if (!$active) : ?>
                <p><?php esc_html_e('Without a key, Nova Gate does nothing and Novamira works as usual. Add a key to lock Novamira\'s AI abilities for everyone who does not send one.', 'nova-gate'); ?></p>
            <?php elseif ($is_open) : ?>
                <?php $this->action_form('lock', __('Lock now', 'nova-gate'), 'button'); ?>
            <?php else : ?>
                <p><?php esc_html_e('Novamira\'s AI abilities are off. Requests that send the key, and Novamira\'s own one-time links, still work.', 'nova-gate'); ?></p>
            <?php endif; ?>

            <h2><?php esc_html_e('Keys', 'nova-gate'); ?></h2>
            <?php if (hash_is_from_constant()) : ?>
                <p><?php
                    /* translators: %s: constant name */
                    echo esc_html(sprintf(__('The key hashes are set in wp-config.php as %s. Change or remove them there.', 'nova-gate'), 'NOVA_GATE_KEY_HASH'));
                ?></p>
            <?php else : ?>
                <?php $this->keys_table(); ?>
                <p><?php esc_html_e('Give every tool its own key, so you can remove one without touching the others. Only the SHA-256 hash of a key is stored; if you lost a key, remove it and add a new one.', 'nova-gate'); ?></p>
                <form method="post" action="<?php echo esc_url(self::page_url()); ?>">
                    <input type="hidden" name="nova_gate_action" value="add"><input type="hidden" name="<?php echo esc_attr(self::NONCE_NAME); ?>" value="<?php echo esc_attr(wp_create_nonce('nova_gate_add')); ?>">
                    <label for="nova_gate_label"><?php esc_html_e('Label for the new key (1 to 60 characters, for example the name of the tool):', 'nova-gate'); ?></label>
                    <input type="text" id="nova_gate_label" name="nova_gate_label" maxlength="60" required class="regular-text">
                    <?php submit_button(__('Add key', 'nova-gate'), 'primary', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <h2><?php esc_html_e('Wrong tries', 'nova-gate'); ?></h2>
            <?php $this->blocks_section(); ?>

            <h2><?php esc_html_e('Timer', 'nova-gate'); ?></h2>
            <form method="post" action="<?php echo esc_url(self::page_url()); ?>">
                <input type="hidden" name="nova_gate_action" value="timer"><input type="hidden" name="<?php echo esc_attr(self::NONCE_NAME); ?>" value="<?php echo esc_attr(wp_create_nonce('nova_gate_timer')); ?>">
                <label for="nova_gate_minutes"><?php esc_html_e('When an admin turns Novamira\'s switch on, keep the gate open for this many minutes (5 to 240):', 'nova-gate'); ?></label>
                <input type="number" id="nova_gate_minutes" name="nova_gate_minutes" min="<?php echo (int) Gate::MIN_MINUTES; ?>" max="<?php echo (int) Gate::MAX_MINUTES; ?>" step="1" value="<?php echo (int) $minutes; ?>" class="small-text">
                <?php submit_button(__('Save timer', 'nova-gate'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('Connect', 'nova-gate'); ?></h2>
            <p><?php
                /* translators: %s: header name */
                echo esc_html(sprintf(__('Send the key in the %s header on every request to Novamira\'s MCP endpoint. Replace YOUR_KEY with your key.', 'nova-gate'), 'X-Nova-Gate-Key'));
            ?></p>

            <?php
            $this->example(
                'claude',
                __('Claude Code CLI', 'nova-gate'),
                'claude mcp add --transport http novamira ' . $mcp_url . ' --header "X-Nova-Gate-Key: YOUR_KEY"'
            );
            $this->example(
                'json',
                __('Generic MCP client (JSON)', 'nova-gate'),
                "{\n  \"mcpServers\": {\n    \"novamira\": {\n      \"type\": \"http\",\n      \"url\": \"" . $mcp_url . "\",\n      \"headers\": {\n        \"X-Nova-Gate-Key\": \"YOUR_KEY\"\n      }\n    }\n  }\n}"
            );
            $this->example(
                'remote',
                '@automattic/mcp-wordpress-remote',
                "{\n  \"mcpServers\": {\n    \"novamira\": {\n      \"command\": \"npx\",\n      \"args\": [\"-y\", \"@automattic/mcp-wordpress-remote@latest\"],\n      \"env\": {\n        \"WP_API_URL\": \"" . $mcp_url . "\",\n        \"CUSTOM_HEADERS\": \"{\\\"X-Nova-Gate-Key\\\":\\\"YOUR_KEY\\\"}\"\n      }\n    }\n  }\n}"
            );
            $this->example(
                'curl',
                'curl',
                'curl -X POST ' . $mcp_url . " \\\n  -H \"X-Nova-Gate-Key: YOUR_KEY\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'"
            );
            ?>

            <h2><?php esc_html_e('Status URL', 'nova-gate'); ?></h2>
            <p><?php esc_html_e('Public. Shows whether the gate is active, whether Nova Gate loaded before Novamira, and whether the key header you sent is accepted. It never shows the key or its hash.', 'nova-gate'); ?></p>
            <p><code id="nova-gate-status-url"><?php echo esc_html($status); ?></code>
                <button type="button" class="button button-small nova-gate-copy" data-target="nova-gate-status-url" data-copied="<?php esc_attr_e('Copied', 'nova-gate'); ?>"><?php esc_html_e('Copy', 'nova-gate'); ?></button></p>

            <h2><?php esc_html_e('Last manual open', 'nova-gate'); ?></h2>
            <p><?php echo esc_html($this->last_open_text()); ?></p>

            <p><em><?php esc_html_e('Independent add-on. Not made, approved or supported by Novamira.', 'nova-gate'); ?></em></p>
            <p class="nova-gate-footer">
                <a href="https://monkeywebs.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Made by Monkey Webs – see our other tools', 'nova-gate'); ?></a>
            </p>
        </div>
        <?php
    }

    private function keys_table(): void
    {
        $keys = list_keys();
        if ($keys === []) {
            echo '<p>' . esc_html__('No key yet.', 'nova-gate') . '</p>';

            return;
        }
        $last = count($keys) === 1;
        ?>
        <table class="widefat striped" style="max-width:720px">
            <thead><tr>
                <th><?php esc_html_e('Id', 'nova-gate'); ?></th>
                <th><?php esc_html_e('Label', 'nova-gate'); ?></th>
                <th><?php esc_html_e('Created', 'nova-gate'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($keys as $id => $key) : ?>
                <tr>
                    <td><code><?php echo esc_html((string) $id); ?></code></td>
                    <td><?php echo esc_html($key['label']); ?></td>
                    <td><?php echo esc_html($key['created'] > 0 ? self::local_time((int) $key['created']) : __('before v0.2.0', 'nova-gate')); ?></td>
                    <td><?php $this->action_form(
                        'remove',
                        __('Remove', 'nova-gate'),
                        'button button-small button-link-delete',
                        $last
                            ? __('Remove the last key? Nova Gate becomes inactive and no longer locks Novamira.', 'nova-gate')
                            /* translators: %s: key id */
                            : sprintf(__('Remove the key "%s"? Tools that use it stop working at once.', 'nova-gate'), (string) $id),
                        ['nova_gate_key_id' => (string) $id]
                    ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function blocks_section(): void
    {
        $max = max_tries();
        if ($max === 0) {
            echo '<p>' . esc_html__('The wrong-try limit is turned off (NOVA_GATE_MAX_TRIES is 0).', 'nova-gate') . '</p>';

            return;
        }
        $blocked  = blocked_now();
        $last_try = Limiter::last_try(tries_table());
        ?>
        <p><?php
            echo esc_html(sprintf(
                /* translators: 1: number of wrong tries, 2: minutes of the window, 3: minutes of the block */
                __('An address that sends %1$d wrong keys within %2$d minutes is blocked for %3$d minutes. While blocked, it is refused even with a right key. Novamira\'s one-time links and a manual open still work for it.', 'nova-gate'),
                $max,
                (int) (Limiter::WINDOW / 60),
                (int) (Limiter::BLOCK / 60)
            ));
        ?></p>
        <p>
            <?php
            /* translators: %d: number of blocked addresses */
            echo esc_html(sprintf(__('Addresses blocked now: %d', 'nova-gate'), $blocked));
            echo '<br>';
            echo esc_html($last_try > 0
                /* translators: %s: site-local date and time */
                ? sprintf(__('Last wrong try: %s', 'nova-gate'), self::local_time($last_try))
                : __('Last wrong try: none', 'nova-gate'));
            ?>
        </p>
        <?php
        $this->action_form('clear_blocks', __('Clear blocks', 'nova-gate'), 'button');
    }

    /** @param array<string, string> $hidden Extra hidden fields (name => value). */
    private function action_form(string $action, string $label, string $class, string $confirm = '', array $hidden = []): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(self::page_url()); ?>" style="display:inline-block;margin-right:8px"<?php
            if ($confirm !== '') {
                echo ' data-confirm="' . esc_attr($confirm) . '"';
            }
        ?>>
            <input type="hidden" name="nova_gate_action" value="<?php echo esc_attr($action); ?>"><input type="hidden" name="<?php echo esc_attr(self::NONCE_NAME); ?>" value="<?php echo esc_attr(wp_create_nonce('nova_gate_' . $action)); ?>">
            <?php foreach ($hidden as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function example(string $id, string $title, string $code): void
    {
        ?>
        <h3><?php echo esc_html($title); ?></h3>
        <div class="nova-gate-example">
            <pre><code id="nova-gate-example-<?php echo esc_attr($id); ?>"><?php echo esc_html($code); ?></code></pre>
            <button type="button" class="button button-small nova-gate-copy" data-target="nova-gate-example-<?php echo esc_attr($id); ?>" data-copied="<?php esc_attr_e('Copied', 'nova-gate'); ?>"><?php esc_html_e('Copy', 'nova-gate'); ?></button>
        </div>
        <?php
    }

    private function last_open_text(): string
    {
        $last = get_option(OPTION_LAST_OPEN, null);
        if (!is_array($last) || !isset($last['time']) || !is_numeric($last['time'])) {
            return __('Never.', 'nova-gate');
        }
        $user_id = isset($last['user']) && is_numeric($last['user']) ? (int) $last['user'] : 0;
        $user    = $user_id > 0 ? get_userdata($user_id) : false;
        if ($user instanceof \WP_User) {
            $name = $user->display_name;
        } elseif ($user_id === 0) {
            $name = __('WP-CLI', 'nova-gate');
        } else {
            $name = __('unknown user', 'nova-gate');
        }

        /* translators: 1: user display name, 2: site-local date and time */
        return sprintf(__('%1$s on %2$s', 'nova-gate'), $name, self::local_time((int) $last['time']));
    }
}
