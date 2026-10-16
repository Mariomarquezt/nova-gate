<?php
/**
 * Independent edge-case tests for NovaGate\Gate, written by the session model from the spec
 * (seam S-2, AC-1 … AC-7, AC-11) without reading the implementation.
 *
 * Rule these tests enforce: Nova Gate may be STRICTER than WordPress, never looser. So a case is
 * asserted only when the safe answer is unambiguous. Where WordPress itself would still dispatch a
 * harmless link route (for example a doubled leading slash), nothing is asserted.
 */

declare(strict_types=1);

namespace NovaGate\Tests\Adversarial;

use NovaGate\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

defined('ABSPATH') || define('ABSPATH', sys_get_temp_dir() . '/');

if (!class_exists(Gate::class)) {
    require_once dirname(__DIR__, 2) . '/includes/class-gate.php';
}

final class GateAdversarialTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const PREFIX = 'wp-json';

    /** A fixed, well-formed key that contains letters, so its upper-case form is a different string. */
    private static function key(): string
    {
        return str_repeat('a1b2c3d4', 8);
    }

    private static function hash(): string
    {
        return hash('sha256', self::key());
    }

    /** Resolve the route and decide, the way the plugin glue must. */
    private static function stateFor(array $server, array $get = [], array $post = [], ?string $header = null, int $openUntil = 0, string $home = ''): string
    {
        return Gate::decide([
            'key_hash' => self::hash(),
            'header' => $header,
            'route' => Gate::resolve_route($server, $get, $post, $home, self::PREFIX),
            'open_until' => $openUntil,
            'now' => self::NOW,
        ]);
    }

    // ---------------------------------------------------------------- route resolution

    public function test_plain_link_paths_resolve(): void
    {
        self::assertSame('/novamira/v1/upload', Gate::resolve_route(['REQUEST_URI' => '/wp-json/novamira/v1/upload'], [], [], '', self::PREFIX));
        self::assertSame('/novamira/v1/upload', Gate::resolve_route(['REQUEST_URI' => '/wp-json/novamira/v1/upload?x=1'], ['x' => '1'], [], '', self::PREFIX));
        self::assertSame('/novamira/v1/upload', Gate::resolve_route(['REQUEST_URI' => '/index.php/wp-json/novamira/v1/upload'], [], [], '', self::PREFIX));
        self::assertSame('/novamira/v1/upload', Gate::resolve_route(['REQUEST_URI' => '/blog/wp-json/novamira/v1/upload'], [], [], '/blog', self::PREFIX));
        self::assertSame('/novamira/v1/upload', Gate::resolve_route(['REQUEST_URI' => '/api/novamira/v1/upload'], [], [], '', 'api'));
    }

    public function test_rest_route_parameter_beats_the_path(): void
    {
        $server = ['REQUEST_URI' => '/wp-json/novamira/v1/upload?rest_route=/mcp/novamira'];

        self::assertSame('/mcp/novamira', Gate::resolve_route($server, ['rest_route' => '/mcp/novamira'], [], '', self::PREFIX));
        self::assertSame(Gate::LOCKED, self::stateFor($server, ['rest_route' => '/mcp/novamira']));
    }

    public function test_rest_route_in_a_form_body_beats_the_path(): void
    {
        $server = ['REQUEST_URI' => '/wp-json/novamira/v1/upload'];

        self::assertSame('/mcp/novamira', Gate::resolve_route($server, [], ['rest_route' => '/mcp/novamira'], '', self::PREFIX));
        self::assertSame(Gate::LOCKED, self::stateFor($server, [], ['rest_route' => '/mcp/novamira']));
    }

    public function test_conflicting_rest_route_in_get_and_post_is_ambiguous(): void
    {
        $server = ['REQUEST_URI' => '/wp-json/novamira/v1/upload'];
        $get = ['rest_route' => '/novamira/v1/upload'];
        $post = ['rest_route' => '/mcp/novamira'];

        self::assertNull(Gate::resolve_route($server, $get, $post, '', self::PREFIX));
        self::assertSame(Gate::LOCKED, self::stateFor($server, $get, $post));
        self::assertSame(Gate::LOCKED, self::stateFor($server, $post, $get));
    }

    public function test_rest_route_that_is_not_a_string_is_ambiguous(): void
    {
        $server = ['REQUEST_URI' => '/wp-json/novamira/v1/upload'];

        self::assertNull(Gate::resolve_route($server, ['rest_route' => ['/novamira/v1/upload']], [], '', self::PREFIX));
        self::assertNull(Gate::resolve_route($server, [], ['rest_route' => ['/novamira/v1/upload']], '', self::PREFIX));
        self::assertSame(Gate::LOCKED, self::stateFor($server, ['rest_route' => ['/novamira/v1/upload']]));
    }

    public function test_path_info_that_disagrees_with_the_request_uri_is_ambiguous(): void
    {
        $server = ['REQUEST_URI' => '/wp-json/novamira/v1/upload', 'PATH_INFO' => '/wp-json/mcp/novamira'];

        self::assertNull(Gate::resolve_route($server, [], [], '', self::PREFIX));
        self::assertSame(Gate::LOCKED, self::stateFor($server));
    }

    public function test_path_info_that_agrees_keeps_the_route(): void
    {
        $server = ['REQUEST_URI' => '/index.php/wp-json/novamira/v1/upload', 'PATH_INFO' => '/wp-json/novamira/v1/upload'];

        self::assertSame('/novamira/v1/upload', Gate::resolve_route($server, [], [], '', self::PREFIX));
    }

    public static function notRestRequests(): array
    {
        return [
            'front page' => [['REQUEST_URI' => '/'], ''],
            'wp-admin' => [['REQUEST_URI' => '/wp-admin/admin.php?page=x'], ''],
            'no request uri at all' => [[], ''],
            'empty request uri' => [['REQUEST_URI' => ''], ''],
            'rest path outside the home path' => [['REQUEST_URI' => '/blog/wp-json/novamira/v1/upload'], ''],
            'home path required but missing' => [['REQUEST_URI' => '/wp-json/novamira/v1/upload'], '/blog'],
            'prefix only as a substring' => [['REQUEST_URI' => '/wp-jsonx/novamira/v1/upload'], ''],
            'prefix later in the path' => [['REQUEST_URI' => '/page/wp-json/novamira/v1/upload'], ''],
        ];
    }

    /** @dataProvider notRestRequests */
    #[DataProvider('notRestRequests')]
    public function test_non_rest_requests_resolve_to_null(array $server, string $home): void
    {
        self::assertNull(Gate::resolve_route($server, [], [], $home, self::PREFIX));
    }

    public function test_wrong_rest_prefix_resolves_to_null(): void
    {
        self::assertNull(Gate::resolve_route(['REQUEST_URI' => '/wp-json/novamira/v1/upload'], [], [], '', 'api'));
    }

    public static function pathsThatMustStayLocked(): array
    {
        return [
            'the MCP route itself' => ['/wp-json/mcp/novamira'],
            'legacy MCP alias' => ['/wp-json/mcp/mcp-adapter-default-server'],
            'oauth MCP route' => ['/wp-json/mcp/novamira-oauth'],
            'ability run route' => ['/wp-json/novamira/v1/abilities/novamira/execute-php/run'],
            'upload with a suffix letter' => ['/wp-json/novamira/v1/uploadX'],
            'upload with an extra segment' => ['/wp-json/novamira/v1/upload/extra'],
            'upload then dot-dot to mcp' => ['/wp-json/novamira/v1/upload/../../../mcp/novamira'],
            'percent-encoded letter' => ['/wp-json/novamira/v1/uplo%61d'],
            'percent-encoded slash' => ['/wp-json/novamira%2Fv1%2Fupload'],
            'second rest prefix after the link route' => ['/wp-json/novamira/v1/upload/wp-json/mcp/novamira'],
            'index.php after the link route' => ['/wp-json/novamira/v1/upload/index.php/wp-json/mcp/novamira'],
            'admin-access with two segments' => ['/wp-json/novamira/v1/admin-access/abc/def'],
            'admin-access with an encoded slash' => ['/wp-json/novamira/v1/admin-access/abc%2Fdef'],
            'batch endpoint' => ['/wp-json/batch/v1'],
            'rest index' => ['/wp-json/'],
            'nova gate status route' => ['/wp-json/nova-gate/v1/status'],
            'wp settings route' => ['/wp-json/wp/v2/settings'],
        ];
    }

    /** @dataProvider pathsThatMustStayLocked */
    #[DataProvider('pathsThatMustStayLocked')]
    public function test_keyless_request_to_any_other_path_is_locked(string $uri): void
    {
        self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => $uri]));
        self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => '/index.php' . $uri]));
        self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => $uri . '?a=b'], ['a' => 'b']));
    }

    public static function linkPaths(): array
    {
        return [
            'upload' => ['/wp-json/novamira/v1/upload'],
            'upload trailing slash' => ['/wp-json/novamira/v1/upload/'],
            'admin-access exchange' => ['/wp-json/novamira/v1/admin-access'],
            'admin-access login' => ['/wp-json/novamira/v1/admin-access/Ab_c-123'],
        ];
    }

    /** @dataProvider linkPaths */
    #[DataProvider('linkPaths')]
    public function test_keyless_request_to_a_link_path_is_open_link(string $uri): void
    {
        self::assertSame(Gate::OPEN_LINK, self::stateFor(['REQUEST_URI' => $uri]));
    }

    /** @dataProvider linkPaths */
    #[DataProvider('linkPaths')]
    public function test_link_path_with_a_rest_route_override_is_locked(string $uri): void
    {
        foreach (['/mcp/novamira', '/novamira/v1/abilities/a/b/run', '/batch/v1', '/'] as $override) {
            self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => $uri], ['rest_route' => $override]), "GET override $override");
            self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => $uri], [], ['rest_route' => $override]), "POST override $override");
        }
    }

    // ---------------------------------------------------------------- is_link_route

    public static function linkRoutes(): array
    {
        return [
            ['/novamira/v1/upload'],
            ['/novamira/v1/upload/'],
            ['/novamira/v1/admin-access'],
            ['/novamira/v1/admin-access/'],
            ['/novamira/v1/admin-access/abc_DEF-123'],
            ['/novamira/v1/admin-access/abc_DEF-123/'],
        ];
    }

    /** @dataProvider linkRoutes */
    #[DataProvider('linkRoutes')]
    public function test_link_routes_are_recognised(string $route): void
    {
        self::assertTrue(Gate::is_link_route($route));
    }

    public static function notLinkRoutes(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'root' => ['/'],
            'mcp' => ['/mcp/novamira'],
            'ability run' => ['/novamira/v1/abilities/a/b/run'],
            'suffix letter' => ['/novamira/v1/uploadX'],
            'extra segment' => ['/novamira/v1/upload/extra'],
            'prefix segment' => ['/x/novamira/v1/upload'],
            'query inside the route' => ['/novamira/v1/upload?rest_route=/mcp/novamira'],
            // PHP's `$` matches before a final newline unless the D modifier is used.
            'trailing newline' => ["/novamira/v1/upload\n"],
            'embedded newline' => ["/novamira/v1/upload\n/mcp/novamira"],
            'nul byte' => ["/novamira/v1/upload\0/mcp/novamira"],
            'nonce with a slash' => ['/novamira/v1/admin-access/a/b'],
            'nonce with an encoded slash' => ['/novamira/v1/admin-access/a%2Fb'],
            'nonce with a space' => ['/novamira/v1/admin-access/a b'],
            'nonce with a dot' => ['/novamira/v1/admin-access/a.b'],
            'nonce trailing newline' => ["/novamira/v1/admin-access/abc\n"],
            'gutenberg route' => ['/novamira/v1/gutenberg/batches'],
            'other namespace version' => ['/novamira/v2/upload'],
        ];
    }

    /** @dataProvider notLinkRoutes */
    #[DataProvider('notLinkRoutes')]
    public function test_everything_else_is_not_a_link_route(?string $route): void
    {
        self::assertFalse(Gate::is_link_route($route));
    }

    // ---------------------------------------------------------------- key verification

    public function test_the_right_key_verifies(): void
    {
        self::assertTrue(Gate::verify_key(self::key(), self::hash()));
    }

    public function test_keys_at_the_length_limits_verify(): void
    {
        foreach ([32, 128] as $length) {
            $key = str_repeat('Z9', intdiv($length, 2));
            self::assertTrue(Gate::verify_key($key, hash('sha256', $key)), "length $length");
        }
    }

    public static function badHeaders(): array
    {
        $key = self::key();

        return [
            'null' => [null],
            'empty' => [''],
            'leading space' => [' ' . $key],
            'trailing space' => [$key . ' '],
            'trailing newline' => [$key . "\n"],
            'upper-case form of the key' => [strtoupper($key)],
            'one character short' => [substr($key, 0, 63)],
            'one character extra' => [$key . 'a'],
            'well-formed wrong key' => [str_repeat('0', 64)],
            'the hash instead of the key' => [self::hash()],
            'non-ascii' => [str_repeat('é', 32)],
            'symbols' => [str_repeat('a-b_', 16)],
            'nul inside' => [substr($key, 0, 32) . "\0" . substr($key, 33)],
        ];
    }

    /** @dataProvider badHeaders */
    #[DataProvider('badHeaders')]
    public function test_a_bad_header_never_verifies(?string $header): void
    {
        self::assertFalse(Gate::verify_key($header, self::hash()));
        self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => '/wp-json/mcp/novamira'], [], [], $header));
    }

    public function test_keys_outside_the_length_limits_never_verify_even_with_their_own_hash(): void
    {
        foreach ([1, 31, 129, 4096] as $length) {
            $key = str_repeat('a', $length);
            self::assertFalse(Gate::verify_key($key, hash('sha256', $key)), "length $length");
        }
    }

    public function test_a_key_with_symbols_never_verifies_even_with_its_own_hash(): void
    {
        $key = str_repeat('ab-_', 16);
        self::assertFalse(Gate::verify_key($key, hash('sha256', $key)));
    }

    public static function badHashes(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'too short' => ['abc'],
            'upper-case digest of the right key' => [strtoupper(self::hash())],
            'right digest with a space' => [self::hash() . ' '],
            'not hex' => [str_repeat('g', 64)],
            '63 hex characters' => [substr(self::hash(), 0, 63)],
            '65 hex characters' => [self::hash() . 'a'],
        ];
    }

    /** @dataProvider badHashes */
    #[DataProvider('badHashes')]
    public function test_a_bad_stored_hash_matches_no_key(?string $hash): void
    {
        self::assertFalse(Gate::verify_key(self::key(), $hash));
        self::assertFalse(Gate::is_valid_hash($hash));
    }

    public function test_generated_keys_are_64_lowercase_hex_unique_and_verify(): void
    {
        $first = Gate::generate_key();
        $second = Gate::generate_key();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first);
        self::assertNotSame($first, $second);
        self::assertSame(hash('sha256', $first), Gate::hash_key($first));
        self::assertTrue(Gate::is_valid_hash(Gate::hash_key($first)));
        self::assertTrue(Gate::verify_key($first, Gate::hash_key($first)));
        self::assertFalse(Gate::verify_key($second, Gate::hash_key($first)));
    }

    // ---------------------------------------------------------------- decision order

    public function test_no_hash_means_inactive_whatever_else_is_true(): void
    {
        self::assertSame(Gate::INACTIVE, Gate::decide([
            'key_hash' => null,
            'header' => self::key(),
            'route' => '/novamira/v1/upload',
            'open_until' => self::NOW + 999,
            'now' => self::NOW,
        ]));
        self::assertSame(Gate::INACTIVE, Gate::decide(['header' => self::key(), 'now' => self::NOW]));
    }

    public function test_an_empty_stored_hash_is_a_broken_setup_and_never_opens_for_a_key(): void
    {
        // SPEC-SILENT: the builder treats '' as "configured but unusable" (active, unmatchable),
        // which is the stricter reading of AC-7. Either way no key may open it.
        $base = ['key_hash' => '', 'open_until' => 0, 'now' => self::NOW];

        self::assertNotSame(Gate::OPEN_KEY, Gate::decide($base + ['header' => self::key(), 'route' => '/mcp/novamira']));
        self::assertNotSame(Gate::OPEN_KEY, Gate::decide($base + ['header' => '', 'route' => '/mcp/novamira']));
        self::assertFalse(Gate::verify_key('', ''));
        self::assertFalse(Gate::verify_key(self::key(), ''));
    }

    public function test_the_key_wins_over_every_other_open_reason(): void
    {
        self::assertSame(Gate::OPEN_KEY, self::stateFor(['REQUEST_URI' => '/wp-json/novamira/v1/upload'], [], [], self::key(), self::NOW + 60));
        self::assertSame(Gate::OPEN_KEY, self::stateFor(['REQUEST_URI' => '/wp-json/mcp/novamira'], [], [], self::key()));
    }

    public function test_manual_open_wins_over_a_link_route_and_ends_exactly_at_the_time(): void
    {
        $link = ['REQUEST_URI' => '/wp-json/novamira/v1/upload'];
        $mcp = ['REQUEST_URI' => '/wp-json/mcp/novamira'];

        self::assertSame(Gate::OPEN_MANUAL, self::stateFor($link, [], [], null, self::NOW + 1));
        self::assertSame(Gate::OPEN_MANUAL, self::stateFor($mcp, [], [], null, self::NOW + 1));
        self::assertSame(Gate::LOCKED, self::stateFor($mcp, [], [], null, self::NOW), 'open_until == now is closed');
        self::assertSame(Gate::LOCKED, self::stateFor($mcp, [], [], null, self::NOW - 1));
        self::assertSame(Gate::LOCKED, self::stateFor($mcp, [], [], null, -5));
    }

    public function test_a_wrong_key_does_not_cancel_a_link_route_or_a_manual_open(): void
    {
        $wrong = str_repeat('0', 64);

        self::assertSame(Gate::OPEN_LINK, self::stateFor(['REQUEST_URI' => '/wp-json/novamira/v1/upload'], [], [], $wrong));
        self::assertSame(Gate::OPEN_MANUAL, self::stateFor(['REQUEST_URI' => '/wp-json/mcp/novamira'], [], [], $wrong, self::NOW + 60));
    }

    public function test_a_malformed_stored_hash_keeps_the_gate_active_and_unmatchable(): void
    {
        foreach (['abc', strtoupper(self::hash()), str_repeat('g', 64)] as $hash) {
            $base = ['key_hash' => $hash, 'open_until' => 0, 'now' => self::NOW];

            self::assertSame(Gate::LOCKED, Gate::decide($base + ['header' => self::key(), 'route' => '/mcp/novamira']), $hash);
            self::assertSame(Gate::LOCKED, Gate::decide($base + ['header' => null, 'route' => null]), $hash);
            self::assertSame(Gate::OPEN_LINK, Gate::decide($base + ['header' => null, 'route' => '/novamira/v1/upload']), $hash);
        }
    }

    public function test_missing_context_keys_never_throw_and_never_open(): void
    {
        self::assertSame(Gate::INACTIVE, Gate::decide([]));
        self::assertSame(Gate::LOCKED, Gate::decide(['key_hash' => self::hash()]));
        self::assertSame(Gate::LOCKED, Gate::decide(['key_hash' => self::hash(), 'now' => self::NOW]));
    }

    public function test_junk_context_values_never_throw_and_never_give_open_key(): void
    {
        $junk = [null, '', 'abc', [], 12, 1.5, true, new \stdClass()];

        foreach ($junk as $value) {
            $state = Gate::decide([
                'key_hash' => self::hash(),
                'header' => $value,
                'route' => $value,
                'open_until' => is_int($value) ? 0 : $value,
                'now' => self::NOW,
            ]);

            // fx-16: none of these is a usable open time, a key or a route, so the only right answer is LOCKED.
            self::assertSame(Gate::LOCKED, $state, var_export($value, true));
        }
    }

    public function test_junk_open_until_never_reads_as_a_future_time(): void
    {
        // now = 0, so any junk value that is read as a positive number would open the gate.
        foreach ([true, ' 1', '1 ', '1e3', '0x10', [1], new \stdClass(), null, false, '', 'abc'] as $value) {
            $state = Gate::decide(['key_hash' => self::hash(), 'open_until' => $value, 'now' => 0]);
            self::assertSame(Gate::LOCKED, $state, var_export($value, true));
        }
        // The documented exceptions (Gate::to_int): an int, a float and an all-digit string are times.
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide(['key_hash' => self::hash(), 'open_until' => 5, 'now' => 0]));
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide(['key_hash' => self::hash(), 'open_until' => 1.5, 'now' => 0]));
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide(['key_hash' => self::hash(), 'open_until' => '5', 'now' => 0]));
    }

    // ---------------------------------------------------------------- fx-12 rest_route outside the front controller

    public static function nonFrontScripts(): array
    {
        return [
            'admin-ajax.php' => ['/wp-admin/admin-ajax.php', ''],
            'admin-post.php' => ['/wp-admin/admin-post.php', ''],
            'wp-admin' => ['/wp-admin/', ''],
            'xmlrpc.php' => ['/xmlrpc.php', ''],
            'wp-login.php' => ['/wp-login.php', ''],
            'subdir admin-ajax.php' => ['/blog/wp-admin/admin-ajax.php', '/blog'],
            'subdir site, root path' => ['/', '/blog'],
            'dot segments' => ['/wp-json/novamira/v1/upload/../../../../wp-admin/admin-ajax.php', ''],
            'encoded dot segments' => ['/wp-json/%2E%2E/wp-admin/admin-ajax.php', ''],
        ];
    }

    #[DataProvider('nonFrontScripts')]
    public function test_link_rest_route_on_a_non_front_script_is_locked(string $path, string $home): void
    {
        foreach (['/novamira/v1/upload', '/novamira/v1/admin-access/abc'] as $link) {
            self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => $path . '?rest_route=' . $link], ['rest_route' => $link], [], null, 0, $home), "GET $link");
            self::assertSame(Gate::LOCKED, self::stateFor(['REQUEST_URI' => $path], [], ['rest_route' => $link], null, 0, $home), "POST $link");
        }
    }

    public function test_link_rest_route_on_the_front_controller_still_opens(): void
    {
        $link = '/novamira/v1/upload';
        self::assertSame(Gate::OPEN_LINK, self::stateFor(['REQUEST_URI' => '/index.php?rest_route=' . $link], ['rest_route' => $link]));
        self::assertSame(Gate::OPEN_LINK, self::stateFor(['REQUEST_URI' => '/?rest_route=' . $link], ['rest_route' => $link]));
        self::assertSame(Gate::OPEN_LINK, self::stateFor(['REQUEST_URI' => '/blog/?rest_route=' . $link], ['rest_route' => $link], [], null, 0, '/blog'));
        self::assertSame(Gate::OPEN_LINK, self::stateFor(['REQUEST_URI' => '/blog/index.php?rest_route=' . $link], ['rest_route' => $link], [], null, 0, '/blog'));
    }

    // ---------------------------------------------------------------- fx-13 PATH_INFO in a subdirectory

    public function test_subdirectory_path_info_with_the_home_path_opens_a_link(): void
    {
        $server = ['REQUEST_URI' => '/blog/wp-json/novamira/v1/upload', 'PATH_INFO' => '/blog/wp-json/novamira/v1/upload'];
        self::assertSame(Gate::OPEN_LINK, self::stateFor($server, [], [], null, 0, '/blog'));

        $server = ['REQUEST_URI' => '/blog/wp-json/novamira/v1/upload', 'PATH_INFO' => '/blog/wp-json/mcp/novamira'];
        self::assertSame(Gate::LOCKED, self::stateFor($server, [], [], null, 0, '/blog'));
    }

    public function test_is_open_is_true_only_for_the_three_open_states(): void
    {
        self::assertTrue(Gate::is_open(Gate::OPEN_KEY));
        self::assertTrue(Gate::is_open(Gate::OPEN_MANUAL));
        self::assertTrue(Gate::is_open(Gate::OPEN_LINK));
        self::assertFalse(Gate::is_open(Gate::LOCKED));
        self::assertFalse(Gate::is_open(Gate::INACTIVE));
        self::assertFalse(Gate::is_open(''));
        self::assertFalse(Gate::is_open('open'));
        self::assertFalse(Gate::is_open('OPEN_KEY'));
    }

    // ---------------------------------------------------------------- timer

    public static function minutes(): array
    {
        return [
            'below range' => [4, 5],
            'low edge' => [5, 5],
            'default' => [30, 30],
            'high edge' => [240, 240],
            'above range' => [241, 240],
            'negative' => [-1, 5],
            'zero' => [0, 5],
            'numeric string' => ['30', 30],
            'big float' => [1e3, 240],
            'word' => ['abc', 30],
            'null' => [null, 30],
            'array' => [[], 30],
            'empty string' => ['', 30],
        ];
    }

    /**
     * @dataProvider minutes
     * @param mixed $input
     */
    #[DataProvider('minutes')]
    public function test_minutes_are_clamped($input, int $expected): void
    {
        self::assertSame($expected, Gate::clamp_minutes($input));
    }

    public function test_odd_minute_inputs_stay_inside_the_range(): void
    {
        // SPEC-SILENT: rounding of 30.9 and the meaning of booleans are not defined; only the range is.
        foreach ([30.9, true, false, '12abc', ' 45 ', PHP_INT_MAX, INF, NAN] as $input) {
            $minutes = Gate::clamp_minutes($input);
            self::assertGreaterThanOrEqual(5, $minutes, var_export($input, true));
            self::assertLessThanOrEqual(240, $minutes, var_export($input, true));
        }
    }
}
