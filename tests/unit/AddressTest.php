<?php

declare(strict_types=1);

use NovaGate\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    #[DataProvider('addresses')]
    public function testProxyResolution(array $server, mixed $header, mixed $trusted, string $expected): void
    {
        self::assertSame($expected, Address::resolve($server, $header, $trusted));
    }

    public static function addresses(): iterable
    {
        $base = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        yield 'no header configured preserves peer' => [$base, null, '10.0.0.0/8', '10.0.0.2'];
        yield 'no proxy configuration ignores header' => [$base, 'HTTP_X_FORWARDED_FOR', null, '10.0.0.2'];
        yield 'empty proxies ignores header' => [$base, 'HTTP_X_FORWARDED_FOR', '', '10.0.0.2'];
        yield 'wrong config type' => [$base, 'HTTP_X_FORWARDED_FOR', ['10.0.0.2'], '10.0.0.2'];
        yield 'wrong header type' => [$base, [], '10.0.0.2', '10.0.0.2'];
        yield 'untrusted peer cannot spoof' => [$base, 'HTTP_X_FORWARDED_FOR', '192.0.2.0/24', '10.0.0.2'];
        yield 'exact IPv4 proxy' => [$base, 'HTTP_X_FORWARDED_FOR', '10.0.0.2', '198.51.100.7'];
        yield 'IPv4 CIDR proxy' => [$base, 'HTTP_X_FORWARDED_FOR', '10.0.0.0/8', '198.51.100.7'];
        yield 'missing peer' => [['HTTP_X_FORWARDED_FOR' => '198.51.100.7'], 'HTTP_X_FORWARDED_FOR', '0.0.0.0/0', 'unknown'];
        yield 'junk peer' => [['REMOTE_ADDR' => 'bad', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], 'HTTP_X_FORWARDED_FOR', '0.0.0.0/0', 'unknown'];
        foreach ([null, [], 42, '', 'bad', '198.51.100.7, bad', '198.51.100.7,', 'unknown', 'for=198.51.100.7'] as $i => $value) {
            yield "junk header $i" => [array_replace($base, ['HTTP_X_FORWARDED_FOR' => $value]), 'HTTP_X_FORWARDED_FOR', '10.0.0.0/8', '10.0.0.2'];
        }
        yield 'spoofed left token loses to real right client' => [array_replace($base, ['HTTP_X_FORWARDED_FOR' => '192.0.2.99, 198.51.100.7, 10.0.0.3']), 'HTTP_X_FORWARDED_FOR', '10.0.0.0/8', '198.51.100.7'];
        yield 'all trusted IPv4 hops use leftmost client' => [array_replace($base, ['HTTP_X_FORWARDED_FOR' => '10.0.0.1, 10.0.0.3']), 'HTTP_X_FORWARDED_FOR', '10.0.0.0/8', '10.0.0.1'];
        yield 'single trusted client retains its address' => [array_replace($base, ['HTTP_X_FORWARDED_FOR' => '10.0.0.1']), 'HTTP_X_FORWARDED_FOR', '10.0.0.0/8', '10.0.0.1'];
        yield 'all trusted IPv6 hops use leftmost client' => [['REMOTE_ADDR' => 'fd00::2', 'HTTP_X_FORWARDED_FOR' => ' fd00::9, fd00::3 '], 'HTTP_X_FORWARDED_FOR', 'fc00::/7', 'fd00::9'];
        yield 'IPv6 chain' => [['REMOTE_ADDR' => '2001:db8:1::2', 'HTTP_X_FORWARDED_FOR' => '192.0.2.99, 2001:db8:2::7, 2001:db8:1::3'], 'HTTP_X_FORWARDED_FOR', '2001:db8:1::/48', '2001:db8:2::7'];
        yield 'mixed chain' => [['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.2'], 'HTTP_X_FORWARDED_FOR', '::1, 10.0.0.0/8', '198.51.100.7'];
        yield 'remote whitespace unchanged from legacy' => [['REMOTE_ADDR' => ' 2001:DB8::7 '], null, null, '2001:DB8::7'];
    }

    #[DataProvider('c1Chains')]
    public function testC1ResolvesOnlyThroughTrustedSuffix(string $peer, string $chain, string $trusted, string $expected): void
    {
        self::assertSame($expected, Address::resolve(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $chain], 'HTTP_X_FORWARDED_FOR', $trusted));
    }

    public static function c1Chains(): iterable
    {
        foreach (['x', '', 'unknown', 'fe80::1%eth0'] as $junk) {
            yield "ignores left junk: $junk" => ['127.0.0.1', "$junk, 203.0.113.5", '127.0.0.1', '203.0.113.5'];
        }
        yield 'junk at right fails closed' => ['127.0.0.1', '203.0.113.5, x', '127.0.0.1', '127.0.0.1'];
        yield 'junk after trusted hop returns peer' => ['127.0.0.1', '203.0.113.5, x, 10.0.0.3', '127.0.0.1,10.0.0.0/8', '127.0.0.1'];
        yield 'all trusted cannot skip left junk' => ['127.0.0.1', '10.0.0.1, x, 10.0.0.3', '127.0.0.1,10.0.0.0/8', '127.0.0.1'];
        yield 'all trusted returns valid leftmost' => ['127.0.0.1', '10.0.0.1, 10.0.0.3', '127.0.0.1,10.0.0.0/8', '10.0.0.1'];
        yield 'IPv4 entry port' => ['127.0.0.1', '1.2.3.4:5678', '127.0.0.1', '1.2.3.4'];
        yield 'IPv6 entry port' => ['127.0.0.1', '[2001:db8::1]:443', '127.0.0.1', '2001:db8::1'];
        yield 'trusted entry port' => ['127.0.0.1', '203.0.113.5, 10.0.0.3:443', '127.0.0.1,10.0.0.0/8', '203.0.113.5'];
        yield 'unbracketed IPv6 is never stripped' => ['127.0.0.1', '2001:db8::1:443', '127.0.0.1', '2001:db8::1:443'];
        yield 'mapped peer matches IPv4 exact' => ['::ffff:127.0.0.1', '203.0.113.5', '127.0.0.1', '203.0.113.5'];
        yield 'mapped peer matches IPv4 CIDR' => ['::ffff:127.0.0.1', '203.0.113.5', '127.0.0.0/8', '203.0.113.5'];
        yield 'mapped entry matches IPv4 CIDR' => ['127.0.0.1', '203.0.113.5, ::ffff:10.0.0.3', '127.0.0.1,10.0.0.0/8', '203.0.113.5'];
        yield 'mapped peer still matches IPv6 range' => ['::ffff:127.0.0.1', '203.0.113.5', '::ffff:127.0.0.0/104', '203.0.113.5'];
        yield 'mapped peer outside IPv4 range ignores header' => ['::ffff:127.0.0.1', '203.0.113.5', '192.0.2.0/24', '::ffff:127.0.0.1'];
        foreach (['1.2.3.4:65536', '[2001:db8::1]:65536', '1.2.3.4:bad', '[1.2.3.4]:443'] as $entry) {
            yield "bad port entry: $entry" => ['127.0.0.1', $entry, '127.0.0.1', '127.0.0.1'];
        }
    }

    #[DataProvider('c1ProxyConfigurations')]
    public function testC1InvalidTrustNotice(mixed $trusted, bool $warns): void
    {
        $scenario = 'define("NOVA_GATE_TRUSTED_PROXIES", ' . var_export($trusted, true) . ');' . <<<'CODE'
define('NOVA_GATE_IP_HEADER', 'HTTP_X_TEST_IP');
ob_start();
(new NovaGate\Admin())->notices();
echo json_encode(str_contains(ob_get_clean(), 'NOVA_GATE_IP_HEADER is ignored'));
CODE;
        self::assertSame($warns, $this->request($scenario));
    }

    public static function c1ProxyConfigurations(): iterable
    {
        yield 'empty' => ['', true];
        yield 'invalid' => ['junk,192.0.2.0/33,::/129', true];
        yield 'wrong type' => [[], true];
        yield 'mixed valid IPv4' => ['junk,192.0.2.0/24', false];
        yield 'valid IPv6' => ['2001:db8::/32', false];
    }

    #[DataProvider('cidrs')]
    public function testTrustedRanges(string $ip, mixed $ranges, bool $expected): void
    {
        self::assertSame($expected, Address::is_trusted($ip, $ranges));
    }

    public static function cidrs(): iterable
    {
        yield ['192.0.2.0', '192.0.2.0/24', true];
        yield ['192.0.2.255', '192.0.2.0/24', true];
        yield ['192.0.3.0', '192.0.2.0/24', false];
        yield ['192.0.1.255', '192.0.2.0/24', false];
        yield ['192.0.2.128', '192.0.2.129/25', true];
        yield ['192.0.2.127', '192.0.2.129/25', false];
        yield ['192.0.2.7', '192.0.2.7/32', true];
        yield ['192.0.2.8', '192.0.2.7/32', false];
        yield ['255.255.255.255', '0.0.0.0/0', true];
        yield ['::1', '0.0.0.0/0', false];
        yield ['::', '::/0', true];
        yield ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '::/0', true];
        yield ['2001:db8:0:7fff::1', '2001:db8::/49', true];
        yield ['2001:db8:0:8000::', '2001:db8::/49', false];
        yield ['2001:db8::7', '2001:0DB8:0000::7/128', true];
        yield ['2001:db8::8', '2001:db8::7/128', false];
        yield ['::ffff:192.0.2.7', '192.0.2.0/24', true];
        yield ['192.0.2.7', ' ::1, bad, 192.0.2.0/24 ', true];
        foreach (['', '*', 'bad', '192.0.2.0/33', '192.0.2.0/-1', '192.0.2.0/24junk', '192.0.2.0/1.5', '192.0.2.0/99999999999999', '192.0.2.0/24/1', '::/129', '::/-1', '::/x', '192.0.2.*', '192.0.2.7:80'] as $range) {
            yield "invalid $range" => ['192.0.2.7', $range, false];
        }
        yield ['not-an-ip', '0.0.0.0/0', false];
    }

    #[DataProvider('headers')]
    public function testPureKeyHeaderSelection(array $server, ?string $expected): void
    {
        self::assertSame($expected, Address::key_header($server));
    }

    public static function headers(): iterable
    {
        yield [[], null];
        yield [['HTTP_X_NOVA_GATE_KEY' => 'direct', 'REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'redirect'], 'direct'];
        yield [['HTTP_X_NOVA_GATE_KEY' => '', 'REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'redirect'], ''];
        yield [['HTTP_X_NOVA_GATE_KEY' => [], 'REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'redirect'], null];
        yield [['REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'redirect'], 'redirect'];
        yield [['REDIRECT_REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'twice'], 'twice'];
        yield [['REDIRECT_REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'twice', 'REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'once'], 'once'];
        yield [['REDIRECT_HTTP_X_NOVA_GATE_KEY' => [], 'REDIRECT_REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'twice'], null];
        yield [['FAKE_REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'fake'], null];
    }

    /** Request glue uses isolated PHP processes and a minimal WordPress seam. */
    private function request(string $scenario): mixed
    {
        $root = var_export(dirname(__DIR__, 2), true);
        $harness = <<<'CODE'
<?php
namespace {
define('ABSPATH', '/unused/');
define('NOVA_GATE_FILE', __FILE__);
$GLOBALS['options'] = ['nova_gate_key_hash' => hash('sha256', str_repeat('a', 64)), 'nova_gate_salt' => 'stored-gate-salt'];
$GLOBALS['hooks'] = [];
$GLOBALS['writes'] = [];
function wp_load_alloptions() { return array_diff_key($GLOBALS['options'], $GLOBALS['not_autoloaded'] ?? []); }
function wp_prime_option_caches($names) {}
function maybe_unserialize($v) { return $v; }
function get_option($k, $default = false) { $GLOBALS['reads'][] = $k; return $GLOBALS['options'][$k] ?? $default; }
function add_option($k, $v, $deprecated = '', $autoload = null) {
    $GLOBALS['adds'][$k][] = [$v, $autoload];
    if (!empty($GLOBALS['fail_add'][$k])) { return false; }
    if (isset($GLOBALS['race'][$k])) {
        $GLOBALS['options'][$k] = $GLOBALS['race'][$k];
        return false;
    }
    if (array_key_exists($k, $GLOBALS['options'])) { return false; }
    $GLOBALS['options'][$k] = $v;
    return true;
}
function wp_cache_delete($key, $group) { $GLOBALS['cache_deletes'][] = [$key, $group]; }
function update_option($k, $v, $autoload = null) {
    $GLOBALS['writes'][$k] = $autoload;
    $GLOBALS['options'][$k] = $v;
    return true;
}
function delete_option($k) { $GLOBALS['deletes'][] = $k; $GLOBALS['delete_locks'][] = $GLOBALS['held_lock'] ?? null; unset($GLOBALS['options'][$k]); }
function add_filter($k, $fn, $priority = 10, $args = 1) { $GLOBALS['hooks'][$k][] = $fn; }
function add_action($k, $fn, $priority = 10, $args = 1) { add_filter($k, $fn, $priority, $args); }
function register_deactivation_hook(...$args) {}
function is_admin() { return false; }
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars($text); }
function esc_attr($text) { return htmlspecialchars($text); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_url($text) { return $text; }
function wp_kses($text, $allowed) { return $text; }
function admin_url($path) { return 'https://site.test/wp-admin/' . $path; }
class WP_Screen { public $id = 'settings_page_nova-gate'; }
function get_current_screen() { return new WP_Screen(); }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function home_url() { return 'https://site.test'; }
function rest_get_url_prefix() { return 'wp-json'; }
function wp_salt($scheme) { $GLOBALS['salt_scheme'] = $scheme; return 'generated-nonce-salt'; }
class WP_Error {
    public function __construct(public $code, public $message, public $data = null) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Request {
    public function __construct(private string $route) {}
    public function get_route() { return $this->route; }
}
class WP_User { public function exists() { return true; } }
function wp_get_current_user() { return new WP_User(); }
function current_user_can($cap) { return true; }
function did_action($name) { return !empty($GLOBALS['application_password']) ? 1 : 0; }
function get_current_user_id() { return 1; }
}
namespace NovaGate {
function with_lock($name, $fn, $busy) {
    $GLOBALS['locked'] = $name;
    if (!empty($GLOBALS['busy'])) { return $busy(); }
    $GLOBALS['held_lock'] = $name;
    try { return $fn(); }
    catch (FreshReadFailed $e) { return $busy(); }
    finally { unset($GLOBALS['held_lock']); }
}
class FreshReadFailed extends \RuntimeException {}
function fresh_option($name) {
    $GLOBALS['fresh_reads'][] = $name;
    if (!empty($GLOBALS['fresh_read_failed'])) { throw new FreshReadFailed('SELECT failed'); }
    return $GLOBALS['fresh'] ?? \get_option($name, null);
}// db_option(): the same database read without clearing caches; the harness records it the same way.
function db_option($name) { return fresh_option($name); }
// db_options(): one batched database read of several rows; same failure switch as fresh_option().
function db_options(array $names) {
    if (!empty($GLOBALS['fresh_read_failed'])) { throw new FreshReadFailed('SELECT failed'); }
    $found = [];
    foreach ($names as $name) { $v = \get_option($name, null); if ($v !== null) { $found[$name] = $v; } }
    return $found;
}
}
namespace {
CODE;
        $harness .= "\nrequire $root . '/includes/class-gate.php';\nrequire $root . '/includes/class-limiter.php';\n";
        $harness .= "if (is_file($root . '/includes/class-address.php')) { require $root . '/includes/class-address.php'; }\n";
        $harness .= "require $root . '/includes/hooks.php';\nrequire $root . '/includes/class-admin.php';\nrequire $root . '/includes/keys.php';\n" . $scenario . "\n}\n";
        $process = proc_open([PHP_BINARY], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $harness);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('nonAutoloadedKeyRows')]
    public function testStoredKeyConfigurationSurvivesMissingAutoloadRow(string $name, mixed $value, array $expected): void
    {
        $scenario = '$GLOBALS["options"] = ' . var_export([$name => $value], true) . ';'
            . '$GLOBALS["not_autoloaded"] = [' . var_export($name, true) . ' => true];' . <<<'CODE'
$hashes = NovaGate\configured_hashes();
$read = NovaGate\filter_enabled_read(false);
$write = NovaGate\filter_enabled_write('1');
echo json_encode([$hashes, NovaGate\is_active(), $read, $write, isset($GLOBALS['options']['nova_gate_open_until'])]);
CODE;
        self::assertSame([$expected, true, '0', '0', true], $this->request($scenario));
    }

    /** Final review: a failed read of the key rows must keep the gate active and matching nothing. */
    public function testFailedKeyRowReadKeepsTheGateActive(): void
    {
        $hash     = hash('sha256', str_repeat('a', 64));
        $scenario = '$GLOBALS["options"] = ' . var_export(['nova_gate_keys' => ['hub' => ['hash' => $hash]]], true) . ';'
            . '$GLOBALS["not_autoloaded"] = ["nova_gate_keys" => true];'
            . '$GLOBALS["fresh_read_failed"] = true;' . <<<'CODE'
$hashes = NovaGate\configured_hashes();
$read = NovaGate\filter_enabled_read(false);
echo json_encode([$hashes, NovaGate\is_active(), $read]);
CODE;
        self::assertSame([[], true, '0'], $this->request($scenario));
    }

    public static function nonAutoloadedKeyRows(): iterable
    {
        $hash = hash('sha256', str_repeat('a', 64));
        yield 'managed keys' => ['nova_gate_keys', ['hub' => ['hash' => $hash]], [$hash]];
        yield 'legacy key' => ['nova_gate_key_hash', $hash, [$hash]];
        yield 'empty managed configuration' => ['nova_gate_keys', [], []];
        yield 'malformed managed configuration' => ['nova_gate_keys', 'junk', []];
        yield 'empty legacy configuration' => ['nova_gate_key_hash', '', ['']];
    }

    #[DataProvider('staleTriesSnapshots')]
    public function testRightKeyHonoursFreshBlockDespiteAutoloadSnapshot(bool $missing): void
    {
        $scenario = ($missing ? '$GLOBALS["not_autoloaded"]["nova_gate_tries"] = true;' : '') . <<<'CODE'
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => str_repeat('a', 64)];
$GLOBALS['options']['nova_gate_tries'] = [];
$id = NovaGate\Limiter::address_id('198.51.100.7', 'stored-gate-salt');
$GLOBALS['fresh'] = [$id => ['n' => 10, 'first' => time(), 'last' => time(), 'until' => time() + 900]];
echo json_encode([NovaGate\state(), NovaGate\key_header_verdict(), $GLOBALS['writes'], $GLOBALS['locked'] ?? null]);
CODE;
        self::assertSame(['locked', 'blocked', [], null], $this->request($scenario));
    }

    public static function staleTriesSnapshots(): iterable
    {
        yield 'missing' => [true];
        yield 'stale' => [false];
    }

    public function testRightKeyFailsClosedOnFreshReadFailure(): void
    {
        self::assertSame(['locked', 'wrong', [], null], $this->request(<<<'CODE'
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => str_repeat('a', 64)];
$GLOBALS['fresh_read_failed'] = true;
echo json_encode([NovaGate\state(), NovaGate\key_header_verdict(), $GLOBALS['writes'], $GLOBALS['locked'] ?? null]);
CODE));
    }

    public function testRightKeyReadsTriesOnlyOnceAndWithoutLock(): void
    {
        self::assertSame(['open_key', 'open_key', 'ok', ['nova_gate_tries'], [], null], $this->request(<<<'CODE'
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => str_repeat('a', 64)];
$first = NovaGate\state();
NovaGate\reset_state();
echo json_encode([$first, NovaGate\state(), NovaGate\key_header_verdict(), $GLOBALS['fresh_reads'] ?? [], $GLOBALS['writes'], $GLOBALS['locked'] ?? null]);
CODE));
    }

    public function testDisabledLimiterNeedsNoFreshReadForRightKey(): void
    {
        self::assertSame(['open_key', [], []], $this->request(<<<'CODE'
define('NOVA_GATE_MAX_TRIES', 0);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => str_repeat('a', 64)];
$GLOBALS['fresh_read_failed'] = true;
echo json_encode([NovaGate\state(), $GLOBALS['fresh_reads'] ?? [], $GLOBALS['writes']]);
CODE));
    }

    public function testClearBlocksRefusesBusyLockWithoutChanges(): void
    {
        self::assertSame(['busy', true, [], []], $this->request(<<<'CODE'
$GLOBALS['busy'] = true;
$GLOBALS['options']['nova_gate_tries'] = ['sentinel' => []];
$result = NovaGate\clear_blocks();
echo json_encode([$result instanceof WP_Error ? $result->get_error_code() : $result, isset($GLOBALS['options']['nova_gate_tries']), $GLOBALS['deletes'] ?? [], $GLOBALS['fresh_reads'] ?? []]);
CODE));
    }

    public function testClearBlocksCountsFreshRowsAndDeletesUnderTriesLock(): void
    {
        self::assertSame([1, false, ['tries'], ['nova_gate_tries']], $this->request(<<<'CODE'
$GLOBALS['options']['nova_gate_tries'] = [];
$GLOBALS['fresh'] = ['sentinel' => ['n' => 10, 'first' => time(), 'last' => time(), 'until' => time() + 900]];
$result = NovaGate\clear_blocks();
echo json_encode([$result, isset($GLOBALS['options']['nova_gate_tries']), $GLOBALS['delete_locks'] ?? [], $GLOBALS['fresh_reads'] ?? []]);
CODE));
    }

    public function testClearBlocksKeepsRowsOnFailedFreshRead(): void
    {
        self::assertSame(['busy', true, []], $this->request(<<<'CODE'
$GLOBALS['fresh_read_failed'] = true;
$GLOBALS['options']['nova_gate_tries'] = ['sentinel' => []];
$result = NovaGate\clear_blocks();
echo json_encode([$result instanceof WP_Error ? $result->get_error_code() : $result, isset($GLOBALS['options']['nova_gate_tries']), $GLOBALS['deletes'] ?? []]);
CODE));
    }

    public function testUnblockCliReportsBusyAsError(): void
    {
        self::assertSame([1, 'Another change is in progress. Nothing was changed; try again in a moment.', true, []], $this->request(<<<'CODE'
define('WP_CLI', true);
class WP_CLI {
    public static function add_command(...$args) {}
    public static function error($message) { throw new RuntimeException($message, 1); }
    public static function success($message) { $GLOBALS['success'][] = $message; }
}
require getcwd() . '/includes/class-cli.php';
$GLOBALS['busy'] = true;
$GLOBALS['options']['nova_gate_tries'] = ['sentinel' => []];
try { (new NovaGate\CLI())->unblock(); $code = 0; $message = ''; }
catch (RuntimeException $e) { $code = $e->getCode(); $message = $e->getMessage(); }
echo json_encode([$code, $message, isset($GLOBALS['options']['nova_gate_tries']), $GLOBALS['success'] ?? []]);
CODE));
    }

    public function testUnblockSettingsRedirectsBusyWithoutChanges(): void
    {
        self::assertSame(['busy', true, []], $this->request(<<<'CODE'
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return $value; }
function check_admin_referer(...$args) {}
function add_query_arg($key, $value, $url) { return $value; }
function wp_safe_redirect($url) { throw new RuntimeException($url); }
$GLOBALS['busy'] = true;
$GLOBALS['options']['nova_gate_tries'] = ['sentinel' => []];
$_POST = ['nova_gate_action' => 'clear_blocks'];
try { (new NovaGate\Admin())->handle_page_post(); $message = ''; }
catch (RuntimeException $e) { $message = $e->getMessage(); }
echo json_encode([$message, isset($GLOBALS['options']['nova_gate_tries']), $GLOBALS['deletes'] ?? []]);
CODE));
    }

    public function testNonAutoloadedKeysKeepApplicationPasswordWritesOffAndHeal(): void
    {
        self::assertSame(['0', false, '0', false], $this->request(<<<'CODE'
$GLOBALS['not_autoloaded']['nova_gate_key_hash'] = true;
$GLOBALS['application_password'] = true;
$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
$GLOBALS['options']['novamira_ai_abilities_domain'] = 'stale.test';
$filtered = NovaGate\filter_enabled_write('1');
NovaGate\self_heal();
echo json_encode([$filtered, isset($GLOBALS['options']['nova_gate_open_until']), $GLOBALS['options']['novamira_ai_abilities_enabled'], isset($GLOBALS['options']['novamira_ai_abilities_domain'])]);
CODE));
    }

    public function testUnblockCliKeepsSuccessOutput(): void
    {
        self::assertSame('Blocks cleared. 1 address(es) were blocked.', $this->request(<<<'CODE'
define('WP_CLI', true);
class WP_CLI {
    public static function add_command(...$args) {}
    public static function error($message) { throw new RuntimeException($message, 1); }
    public static function success($message) { $GLOBALS['success'] = $message; }
}
require getcwd() . '/includes/class-cli.php';
$GLOBALS['fresh'] = ['sentinel' => ['n' => 10, 'first' => time(), 'last' => time(), 'until' => time() + 900]];
(new NovaGate\CLI())->unblock();
echo json_encode($GLOBALS['success']);
CODE));
    }

    public function testFx01HeaderNeedsTrustedPeer(): void
    {
        self::assertSame('198.51.100.7', $this->request(<<<'CODE'
define('NOVA_GATE_IP_HEADER', 'HTTP_X_TEST_IP');
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_TEST_IP' => '192.0.2.4'];
echo json_encode(NovaGate\client_address());
CODE));
    }

    public function testFx01UntrustedPeerCannotRotateAddress(): void
    {
        self::assertSame('198.51.100.7', $this->request(<<<'CODE'
define('NOVA_GATE_IP_HEADER', 'HTTP_X_TEST_IP');
define('NOVA_GATE_TRUSTED_PROXIES', '10.0.0.0/8');
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_TEST_IP' => '192.0.2.4'];
echo json_encode(NovaGate\client_address());
CODE));
    }

    public function testFx01WalksChainFromRight(): void
    {
        self::assertSame('198.51.100.7', $this->request(<<<'CODE'
define('NOVA_GATE_IP_HEADER', 'HTTP_X_FORWARDED_FOR');
define('NOVA_GATE_TRUSTED_PROXIES', '10.0.0.0/8');
$_SERVER = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '192.0.2.4, 198.51.100.7, 10.0.0.3'];
echo json_encode(NovaGate\client_address());
CODE));
    }

    public function testFx04RedirectHeaderFallback(): void
    {
        self::assertSame('redirected', $this->request(<<<'CODE'
$_SERVER = ['REDIRECT_REDIRECT_HTTP_X_NOVA_GATE_KEY' => 'redirected'];
echo json_encode(NovaGate\key_header());
CODE));
    }

    public function testFx02BootHealsBeforeInitAndDeniesRegisteredRoutes(): void
    {
        self::assertSame(['0', 'rest_no_route', 404], $this->request(<<<'CODE'
$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
NovaGate\boot(false);
$result = null;
foreach ($GLOBALS['hooks']['rest_pre_dispatch'] ?? [] as $fn) {
    $result = $fn($result, null, new WP_REST_Request('/mcp/novamira'));
}
echo json_encode([$GLOBALS['options']['novamira_ai_abilities_enabled'], $result->code ?? null, $result->data['status'] ?? null]);
CODE));
    }

    public function testFx08AddedSwitchIsHealedImmediately(): void
    {
        self::assertSame('0', $this->request(<<<'CODE'
NovaGate\boot(true);
$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
foreach ($GLOBALS['hooks']['add_option_novamira_ai_abilities_enabled'] ?? [] as $fn) {
    $fn('novamira_ai_abilities_enabled', '1');
}
echo json_encode($GLOBALS['options']['novamira_ai_abilities_enabled']);
CODE));
    }

    public function testFx11ManualOpenForcesAutoload(): void
    {
        self::assertSame([true, true], $this->request(<<<'CODE'
NovaGate\open_manually();
echo json_encode([$GLOBALS['writes']['nova_gate_open_until'], $GLOBALS['writes']['nova_gate_last_open']]);
CODE));
    }

    public function testFx09AddressUsesStoredGateSaltWithoutWordPressSalt(): void
    {
        self::assertSame([null, true, false], $this->request(<<<'CODE'
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
$id = NovaGate\Limiter::address_id('198.51.100.7', 'stored-gate-salt');
echo json_encode([$GLOBALS['salt_scheme'] ?? null, isset($GLOBALS['options']['nova_gate_tries'][$id]), isset($GLOBALS['adds']['nova_gate_salt'])]);
CODE));
    }

    public function testFx09NonceSaltKeepsExistingAddressIds(): void
    {
        self::assertSame([null, true, false], $this->request(<<<'CODE'
define('NONCE_SALT', 'configured-nonce-salt');
unset($GLOBALS['options']['nova_gate_salt']);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
$id = substr(hash('sha256', '198.51.100.7|configured-nonce-salt'), 0, 16);
echo json_encode([$GLOBALS['salt_scheme'] ?? null, isset($GLOBALS['options']['nova_gate_tries'][$id]), isset($GLOBALS['adds']['nova_gate_salt'])]);
CODE));
    }

    public function testFx09CreatesAutoloadedRandomSaltOnlyOnce(): void
    {
        self::assertSame([true, true, 1, true, 2], $this->request(<<<'CODE'
unset($GLOBALS['options']['nova_gate_salt']);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
$hashes = [hash('sha256', str_repeat('a', 64))];
NovaGate\judge_key_header($hashes);
$salt = $GLOBALS['options']['nova_gate_salt'] ?? '';
NovaGate\judge_key_header($hashes);
$id = NovaGate\Limiter::address_id('198.51.100.7', $salt);
echo json_encode([preg_match('/^[a-f0-9]{64}$/D', $salt) === 1, $salt === ($GLOBALS['options']['nova_gate_salt'] ?? null), count($GLOBALS['adds']['nova_gate_salt'] ?? []), $GLOBALS['adds']['nova_gate_salt'][0][1] ?? null, $GLOBALS['options']['nova_gate_tries'][$id]['n'] ?? null]);
CODE));
    }

    public function testFx09LostSaltAddRaceUsesStoredWinner(): void
    {
        self::assertSame([true, true], $this->request(<<<'CODE'
unset($GLOBALS['options']['nova_gate_salt']);
$GLOBALS['race']['nova_gate_salt'] = str_repeat('b', 64);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
$id = NovaGate\Limiter::address_id('198.51.100.7', str_repeat('b', 64));
echo json_encode([isset($GLOBALS['options']['nova_gate_tries'][$id]), in_array(['alloptions', 'options'], $GLOBALS['cache_deletes'] ?? [], true)]);
CODE));
    }

    #[DataProvider('unusableNonceSalts')]
    public function testFx09UnusableNonceSaltUsesGateSalt(mixed $salt): void
    {
        $scenario = 'define("NONCE_SALT", ' . var_export($salt, true) . ');' . <<<'CODE'
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
$id = NovaGate\Limiter::address_id('198.51.100.7', 'stored-gate-salt');
echo json_encode(isset($GLOBALS['options']['nova_gate_tries'][$id]));
CODE;
        self::assertTrue($this->request($scenario));
    }

    public static function unusableNonceSalts(): iterable
    {
        yield 'empty' => [''];
        yield 'integer' => [42];
        yield 'boolean' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('c3NoncePresence')]
    public function testC3NonAutoloadedSaltKeepsValidKeyOpen(bool $emptyNonce): void
    {
        $scenario = ($emptyNonce ? "define('NONCE_SALT', '');" : '') . <<<'CODE'
$GLOBALS['not_autoloaded']['nova_gate_salt'] = true;
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => str_repeat('a', 64)];
$state = NovaGate\state();
$_SERVER['HTTP_X_NOVA_GATE_KEY'] = 'wrong';
NovaGate\judge_key_header(NovaGate\configured_hashes());
$id = NovaGate\Limiter::address_id('198.51.100.7', 'stored-gate-salt');
echo json_encode([$state, isset($GLOBALS['options']['nova_gate_tries'][$id]), isset($GLOBALS['adds']['nova_gate_salt'])]);
CODE;
        self::assertSame(['open_key', true, false], $this->request($scenario));
    }

    #[DataProvider('c3NoncePresence')]
    public function testC3FailedSaltWriteKeepsValidKeyOpen(bool $emptyNonce): void
    {
        $scenario = ($emptyNonce ? "define('NONCE_SALT', '');" : '') . <<<'CODE'
define('AUTH_KEY', 'test-auth-key');
define('DB_NAME', 'test_db');
unset($GLOBALS['options']['nova_gate_salt']);
$GLOBALS['fail_add']['nova_gate_salt'] = true;
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => str_repeat('a', 64)];
$state = NovaGate\state();
$_SERVER['HTTP_X_NOVA_GATE_KEY'] = 'wrong';
NovaGate\judge_key_header(NovaGate\configured_hashes());
$id = NovaGate\Limiter::address_id('198.51.100.7', 'ec4e91b594b3a60099fa312c347f3ca2e87a7d07f5aba71b685865b05ddf843f');
echo json_encode([$state, isset($GLOBALS['options']['nova_gate_tries'][$id]), isset($GLOBALS['options']['nova_gate_salt'])]);
CODE;
        self::assertSame(['open_key', true, false], $this->request($scenario));
    }

    public static function c3NoncePresence(): iterable
    {
        yield 'absent NONCE_SALT' => [false];
        yield 'empty NONCE_SALT' => [true];
    }

    public function testC3SiteFallbackWorksWithoutAuthKeyOrDatabaseName(): void
    {
        self::assertSame('bf4cac04a59ac984e0f0732599d7cdc2c1fb79fa6abca6429625170ba3eb60f6', $this->request(<<<'CODE'
unset($GLOBALS['options']['nova_gate_salt']);
$GLOBALS['fail_add']['nova_gate_salt'] = true;
echo json_encode(NovaGate\limiter_salt());
CODE));
    }

    public function testR2TestProxyConfigKeepsFakeClientsDistinct(): void
    {
        $config = json_decode(file_get_contents(dirname(__DIR__, 2) . '/.wp-env.json'), true, 512, JSON_THROW_ON_ERROR)['config'];
        self::assertSame('172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1', $config['NOVA_GATE_TRUSTED_PROXIES']);
        foreach (['10.9.2.4', '10.9.2.5', '2001:db8::9'] as $client) {
            self::assertSame($client, Address::resolve(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_TEST_IP' => $client], $config['NOVA_GATE_IP_HEADER'], $config['NOVA_GATE_TRUSTED_PROXIES']));
        }
    }

    public function testFx05BusyLockNeverWritesOrOpens(): void
    {
        self::assertSame(['wrong', false, 'tries'], $this->request(<<<'CODE'
$GLOBALS['busy'] = true;
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
$verdict = NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
echo json_encode([$verdict, isset($GLOBALS['writes']['nova_gate_tries']), $GLOBALS['locked'] ?? null]);
CODE));
    }

    public function testFx10EmptyLegacyIsExplained(): void
    {
        self::assertStringContainsString('legacy key is empty and cannot unlock', $this->request(<<<'CODE'
$GLOBALS['options']['nova_gate_key_hash'] = '';
ob_start();
(new NovaGate\Admin())->notices();
echo json_encode(ob_get_clean());
CODE));
    }

    public function testFx01MissingTrustIsExplained(): void
    {
        self::assertStringContainsString('NOVA_GATE_TRUSTED_PROXIES', $this->request(<<<'CODE'
define('NOVA_GATE_IP_HEADER', 'HTTP_X_TEST_IP');
ob_start();
(new NovaGate\Admin())->notices();
echo json_encode(ob_get_clean());
CODE));
    }
    public function testFx02CoversNovamiraNamespacesAndAliasWithoutBlockingOtherPlugins(): void
    {
        self::assertSame([404, 404, 404, 404, 404, 404, 404, 'existing', 'existing'], $this->request(<<<'CODE'
$_SERVER['REQUEST_URI'] = '/wp-json/novamira/v1/upload';
NovaGate\boot(false);
$results = [];
foreach (['/mcp/novamira', '/mcp/novamira-oauth', '/mcp/novamira-v2/resource', '/mcp/mcp-adapter-default-server', '/novamira/v1/upload', '/novamira/v1/abilities/a/b/run', '/NOVAMIRA/v1/admin-access/token', '/wp/v2/posts', '/mcp/another-plugin'] as $route) {
    $result = NovaGate\guard_late_routes('existing', null, new WP_REST_Request($route));
    $results[] = $result instanceof WP_Error ? $result->data['status'] : $result;
}
echo json_encode($results);
CODE));
    }

    public function testC2LateBootDeniesCoreNovamiraAbilitiesOnly(): void
    {
        self::assertSame([404, 404, 404, 404, 404, 'existing', 'existing', 'existing'], $this->request(<<<'CODE'
$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
NovaGate\boot(false);
$results = [];
foreach (['/wp-abilities/v1/abilities/novamira', '/wp-abilities/v1/abilities/novamira/execute-php/run', '/wp-abilities/v1/abilities/novamira/read-post', '/WP-ABILITIES/v1/abilities/NOVAMIRA/execute-php/run', '/mcp/mcp-adapter-default-server', '/wp-abilities/v1/abilities/another-plugin/do/run', '/wp-abilities/v1/abilities/novamira-extra/do/run', '/wp/v2/posts'] as $route) {
    $result = 'existing';
    foreach ($GLOBALS['hooks']['rest_pre_dispatch'] ?? [] as $fn) {
        $result = $fn($result, null, new WP_REST_Request($route));
    }
    $results[] = $result instanceof WP_Error ? $result->data['status'] : $result;
}
echo json_encode($results);
CODE));
    }

    #[DataProvider('c2OffSwitches')]
    public function testC2LateBootDoesNotRewriteAnOffOrMissingSwitch(mixed $switch): void
    {
        $scenario = '$GLOBALS["options"]["novamira_ai_abilities_enabled"] = ' . var_export($switch, true) . ';' . <<<'CODE'
NovaGate\boot(false);
echo json_encode([array_key_exists('novamira_ai_abilities_enabled', $GLOBALS['writes']), in_array('novamira_ai_abilities_domain', $GLOBALS['deletes'] ?? [], true)]);
CODE;
        self::assertSame([false, false], $this->request($scenario));
    }

    public static function c2OffSwitches(): iterable
    {
        yield 'off string' => ['0'];
        yield 'off integer' => [0];
        yield 'missing' => [null];
    }

    public function testFx02EarlyLoadDoesNotInstallDispatchGuard(): void
    {
        self::assertFalse($this->request(<<<'CODE'
NovaGate\boot(true);
echo json_encode(isset($GLOBALS['hooks']['rest_pre_dispatch']));
CODE));
    }

    public function testFx02LateValidKeyIsAllowed(): void
    {
        self::assertSame(['1', 'existing'], $this->request(<<<'CODE'
$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
$_SERVER['HTTP_X_NOVA_GATE_KEY'] = str_repeat('a', 64);
NovaGate\boot(false);
$result = NovaGate\guard_late_routes('existing', null, new WP_REST_Request('/mcp/novamira'));
echo json_encode([$GLOBALS['options']['novamira_ai_abilities_enabled'], $result]);
CODE));
    }

    public function testFx02LateManualOpenIsAllowed(): void
    {
        self::assertSame(['1', 'existing'], $this->request(<<<'CODE'
$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
$GLOBALS['options']['nova_gate_open_until'] = time() + 60;
NovaGate\boot(false);
echo json_encode([$GLOBALS['options']['novamira_ai_abilities_enabled'], NovaGate\guard_late_routes('existing', null, new WP_REST_Request('/mcp/novamira'))]);
CODE));
    }

    public function testFx02LateInactiveGatePassesThrough(): void
    {
        self::assertSame(['1', 'existing'], $this->request(<<<'CODE'
$GLOBALS['options'] = ['novamira_ai_abilities_enabled' => '1'];
NovaGate\boot(false);
echo json_encode([$GLOBALS['options']['novamira_ai_abilities_enabled'], NovaGate\guard_late_routes('existing', null, new WP_REST_Request('/mcp/novamira'))]);
CODE));
    }

    public function testFx05RechecksFreshBlockInsideLock(): void
    {
        self::assertSame(['blocked', false], $this->request(<<<'CODE'
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
$id = NovaGate\Limiter::address_id('198.51.100.7', 'stored-gate-salt');
$GLOBALS['fresh'] = [$id => ['n' => 10, 'first' => time(), 'last' => time(), 'until' => time() + 900]];
$result = NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
echo json_encode([$result, isset($GLOBALS['writes']['nova_gate_tries'])]);
CODE));
    }

    public function testFx05PreservesAnotherAddressFromFreshTable(): void
    {
        self::assertSame(['wrong', 10, 1], $this->request(<<<'CODE'
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => 'wrong'];
$id = NovaGate\Limiter::address_id('198.51.100.7', 'stored-gate-salt');
$GLOBALS['fresh'] = ['0123456789abcdef' => ['n' => 10, 'first' => time(), 'last' => time(), 'until' => time() + 900]];
$result = NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
$table = $GLOBALS['options']['nova_gate_tries'];
echo json_encode([$result, $table['0123456789abcdef']['n'] ?? null, $table[$id]['n'] ?? null]);
CODE));
    }

    public function testFx05ValidKeyNeverAcquiresLock(): void
    {
        self::assertSame(['ok', false, false], $this->request(<<<'CODE'
$GLOBALS['busy'] = true;
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_NOVA_GATE_KEY' => str_repeat('a', 64)];
$result = NovaGate\judge_key_header([hash('sha256', str_repeat('a', 64))]);
echo json_encode([$result, isset($GLOBALS['locked']), isset($GLOBALS['writes']['nova_gate_tries'])]);
CODE));
    }

    public function testFx08InactiveAddPassesThrough(): void
    {
        self::assertSame('1', $this->request(<<<'CODE'
$GLOBALS['options'] = [];
NovaGate\boot(true);
$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
foreach ($GLOBALS['hooks']['add_option_novamira_ai_abilities_enabled'] ?? [] as $fn) {
    $fn();
}
echo json_encode($GLOBALS['options']['novamira_ai_abilities_enabled']);
CODE));
    }

}
