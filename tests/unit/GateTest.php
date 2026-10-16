<?php
/**
 * Unit tests for NovaGate\Gate (seam S-2). Pure PHP, no WordPress.
 *
 * Each test names the acceptance criterion it proves in its method name.
 */

declare(strict_types=1);

namespace NovaGate\Tests\Unit;

use NovaGate\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase
{
    /** A fixed key and its SHA-256 hex digest, used by many tests. */
    private const KEY = 'a3f1c2d4e5b6978877665544332211009988776655443322110000ffeeddccbb';

    private static function hash(): string
    {
        return hash('sha256', self::KEY);
    }

    private static function ctx(array $over = []): array
    {
        return array_merge([
            'key_hash'   => self::hash(),
            'header'     => null,
            'route'      => null,
            'open_until' => 0,
            'now'        => 1_700_000_000,
        ], $over);
    }

    // ----------------------------------------------------------------- AC-1

    public function test_ac1_no_key_hash_means_inactive(): void
    {
        self::assertSame(Gate::INACTIVE, Gate::decide(self::ctx(['key_hash' => null])));
        self::assertSame(Gate::INACTIVE, Gate::decide([]));
        self::assertFalse(Gate::is_open(Gate::INACTIVE));
    }

    public function test_ac1_inactive_even_with_header_manual_open_and_link_route(): void
    {
        $ctx = self::ctx([
            'key_hash'   => null,
            'header'     => self::KEY,
            'route'      => '/novamira/v1/upload',
            'open_until' => 1_700_000_999,
        ]);
        self::assertSame(Gate::INACTIVE, Gate::decide($ctx));
    }

    // ----------------------------------------------------------------- AC-2

    public function test_ac2_hash_and_nothing_else_means_locked(): void
    {
        $state = Gate::decide(self::ctx());
        self::assertSame(Gate::LOCKED, $state);
        self::assertFalse(Gate::is_open($state));
    }

    public function test_ac2_locked_when_route_is_not_rest(): void
    {
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['route' => null])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['route' => '/mcp/novamira'])));
    }

    // ----------------------------------------------------------------- AC-3

    public function test_ac3_right_key_opens(): void
    {
        $state = Gate::decide(self::ctx(['header' => self::KEY]));
        self::assertSame(Gate::OPEN_KEY, $state);
        self::assertTrue(Gate::is_open($state));
        self::assertTrue(Gate::verify_key(self::KEY, self::hash()));
    }

    public function test_ac3_key_wins_over_route_and_timer(): void
    {
        $ctx = self::ctx([
            'header'     => self::KEY,
            'route'      => '/mcp/novamira',
            'open_until' => 1_700_000_999,
        ]);
        self::assertSame(Gate::OPEN_KEY, Gate::decide($ctx));
    }

    // ----------------------------------------------------------------- AC-4

    public static function bad_headers(): array
    {
        $key = self::KEY;
        return [
            'null'                  => [null],
            'empty'                 => [''],
            'too short (31)'        => [str_repeat('a', 31)],
            'too long (129)'        => [str_repeat('a', 129)],
            'space inside'          => [substr($key, 0, 32) . ' ' . substr($key, 33)],
            'dash inside'           => [substr($key, 0, 32) . '-' . substr($key, 33)],
            'newline appended'      => [$key . "\n"],
            'unicode'               => [substr($key, 0, 60) . 'ñandú'],
            'well-formed wrong key' => [strrev($key)],
            'key with one flipped'  => [substr($key, 0, 63) . ($key[63] === 'b' ? 'c' : 'b')],
            'uppercase variant'     => [strtoupper($key)],
            'the hash itself'       => [hash('sha256', $key)],
        ];
    }

    #[DataProvider('bad_headers')]
    public function test_ac4_bad_or_wrong_header_is_locked(?string $header): void
    {
        self::assertFalse(Gate::verify_key($header, self::hash()));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => $header])));
    }

    public function test_ac4_header_length_bounds_are_inclusive(): void
    {
        $short = str_repeat('Z', 32);
        $long  = str_repeat('z9', 64); // 128 chars
        self::assertTrue(Gate::verify_key($short, Gate::hash_key($short)));
        self::assertTrue(Gate::verify_key($long, Gate::hash_key($long)));
    }

    public function test_ac4_comparison_is_on_sha256_hex(): void
    {
        self::assertSame(hash('sha256', self::KEY), Gate::hash_key(self::KEY));
        self::assertSame(64, strlen(Gate::hash_key('x')));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Gate::hash_key('x'));
    }

    public function test_ac4_verify_never_matches_when_hash_missing_or_malformed(): void
    {
        self::assertFalse(Gate::verify_key(self::KEY, null));
        self::assertFalse(Gate::verify_key(self::KEY, ''));
        self::assertFalse(Gate::verify_key(self::KEY, strtoupper(self::hash())));
        self::assertFalse(Gate::verify_key(self::KEY, substr(self::hash(), 1)));
    }

    // ----------------------------------------------------------------- AC-5

    public static function link_routes(): array
    {
        return [
            ['/novamira/v1/upload'],
            ['/novamira/v1/upload/'],
            ['/novamira/v1/admin-access'],
            ['/novamira/v1/admin-access/'],
            ['/novamira/v1/admin-access/abc'],
            ['/novamira/v1/admin-access/AbC-0_9/'],
        ];
    }

    #[DataProvider('link_routes')]
    public function test_ac5_link_routes_open(string $route): void
    {
        self::assertTrue(Gate::is_link_route($route));
        $state = Gate::decide(self::ctx(['route' => $route]));
        self::assertSame(Gate::OPEN_LINK, $state);
        self::assertTrue(Gate::is_open($state));
    }

    public static function non_link_routes(): array
    {
        return [
            'null'                 => [null],
            'empty'                => [''],
            'root'                 => ['/'],
            'mcp'                  => ['/mcp/novamira'],
            'ability run'          => ['/novamira/v1/abilities/x/y/run'],
            'uploadX'              => ['/novamira/v1/uploadX'],
            'upload/extra'         => ['/novamira/v1/upload/extra'],
            'double slash'         => ['/novamira/v1/upload//'],
            'no leading slash'     => ['novamira/v1/upload'],
            'percent-encoded'      => ['/novamira/v1/uplo%61d'],
            'percent in nonce'     => ['/novamira/v1/admin-access/ab%63'],
            'nonce with slash'     => ['/novamira/v1/admin-access/a/b'],
            'nonce with dot'       => ['/novamira/v1/admin-access/a.b'],
            'empty nonce segments' => ['/novamira/v1/admin-access//'],
            'uppercase'            => ['/novamira/v1/UPLOAD'],
            'v2'                   => ['/novamira/v2/upload'],
            'prefix only'          => ['/novamira/v1'],
            'wp-json kept'         => ['/wp-json/novamira/v1/upload'],
            'trailing space'       => ['/novamira/v1/upload '],
            'newline'              => ["/novamira/v1/upload\n"],
        ];
    }

    #[DataProvider('non_link_routes')]
    public function test_ac5_every_other_route_is_locked(?string $route): void
    {
        self::assertFalse(Gate::is_link_route($route));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['route' => $route])));
    }

    // ----------------------------------------------------------------- AC-6

    private static function resolve(array $server, array $get = [], array $post = [], string $home = '', string $prefix = 'wp-json'): ?string
    {
        return Gate::resolve_route($server, $get, $post, $home, $prefix);
    }

    public function test_ac6_plain_path_resolves(): void
    {
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => '/wp-json/novamira/v1/upload']));
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => '/wp-json/novamira/v1/upload?foo=bar']));
        self::assertSame('/mcp/novamira', self::resolve(['REQUEST_URI' => '/wp-json/mcp/novamira']));
    }

    public function test_ac6_rest_route_in_get_beats_the_path(): void
    {
        $route = self::resolve(
            ['REQUEST_URI' => '/wp-json/novamira/v1/upload?rest_route=/mcp/novamira'],
            ['rest_route' => '/mcp/novamira']
        );
        self::assertSame('/mcp/novamira', $route);
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['route' => $route])));
    }

    public function test_ac6_rest_route_in_post_beats_the_path(): void
    {
        $route = self::resolve(
            ['REQUEST_URI' => '/wp-json/novamira/v1/upload', 'REQUEST_METHOD' => 'POST'],
            [],
            ['rest_route' => '/mcp/novamira']
        );
        self::assertSame('/mcp/novamira', $route);
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['route' => $route])));
    }

    public function test_ac6_rest_route_in_get_and_post_that_differ_is_null(): void
    {
        $route = self::resolve(
            ['REQUEST_URI' => '/wp-json/novamira/v1/upload?rest_route=/novamira/v1/upload'],
            ['rest_route' => '/novamira/v1/upload'],
            ['rest_route' => '/mcp/novamira']
        );
        self::assertNull($route);
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['route' => $route])));
    }

    public function test_ac6_rest_route_in_get_and_post_that_agree_is_that_route(): void
    {
        $route = self::resolve(
            ['REQUEST_URI' => '/wp-json/mcp/novamira?rest_route=/novamira/v1/upload'],
            ['rest_route' => '/novamira/v1/upload'],
            ['rest_route' => '/novamira/v1/upload']
        );
        self::assertSame('/novamira/v1/upload', $route);
    }

    public function test_ac6_rest_route_that_is_not_a_string_is_null(): void
    {
        self::assertNull(self::resolve(['REQUEST_URI' => '/wp-json/novamira/v1/upload'], ['rest_route' => ['/novamira/v1/upload']]));
        self::assertNull(self::resolve(['REQUEST_URI' => '/wp-json/novamira/v1/upload'], [], ['rest_route' => ['x']]));
        self::assertNull(self::resolve(['REQUEST_URI' => '/wp-json/novamira/v1/upload'], ['rest_route' => '/novamira/v1/upload'], ['rest_route' => ['/novamira/v1/upload']]));
    }

    public function test_ac6_empty_rest_route_param_is_null(): void
    {
        self::assertNull(self::resolve(['REQUEST_URI' => '/wp-json/novamira/v1/upload?rest_route='], ['rest_route' => '']));
    }

    public function test_ac6_rest_route_param_without_rest_path(): void
    {
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => '/?rest_route=/novamira/v1/upload'], ['rest_route' => '/novamira/v1/upload']));
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => '/index.php?rest_route=/novamira/v1/upload'], ['rest_route' => '/novamira/v1/upload']));
    }

    public static function path_forms(): array
    {
        return [
            'plain'                 => ['/wp-json/novamira/v1/upload', ''],
            'index.php'             => ['/index.php/wp-json/novamira/v1/upload', ''],
            'home path'             => ['/blog/wp-json/novamira/v1/upload', '/blog'],
            'home path + index.php' => ['/blog/index.php/wp-json/novamira/v1/upload', '/blog'],
            'home path trailing'    => ['/blog/wp-json/novamira/v1/upload', '/blog/'],
            'with query'            => ['/blog/index.php/wp-json/novamira/v1/upload?x=1', '/blog'],
        ];
    }

    #[DataProvider('path_forms')]
    public function test_ac6_path_forms_resolve(string $uri, string $home): void
    {
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => $uri], [], [], $home));
    }

    public static function non_rest_paths(): array
    {
        return [
            'no prefix'                 => ['/novamira/v1/upload', ''],
            'wrong prefix'              => ['/wp-jsonx/novamira/v1/upload', ''],
            'prefix glued'              => ['/wp-jsonnovamira/v1/upload', ''],
            'missing home path'         => ['/wp-json/novamira/v1/upload', '/blog'],
            'wrong home path'           => ['/site/wp-json/novamira/v1/upload', '/blog'],
            'home path glued'           => ['/blogwp-json/novamira/v1/upload', '/blog'],
            'prefix before home'        => ['/wp-json/blog/novamira/v1/upload', '/blog'],
            'admin page'                => ['/wp-admin/options-general.php?page=nova-gate', ''],
            'front page'                => ['/', ''],
            'empty uri'                 => ['', ''],
            'double slash before'       => ['//wp-json/novamira/v1/upload', ''],
            'absolute url'              => ['http://example.test/wp-json/novamira/v1/upload', ''],
            'custom prefix mismatch'    => ['/wp-json/novamira/v1/upload', '', 'api'],
        ];
    }

    #[DataProvider('non_rest_paths')]
    public function test_ac6_path_not_under_home_and_prefix_is_null(string $uri, string $home, string $prefix = 'wp-json'): void
    {
        self::assertNull(self::resolve(['REQUEST_URI' => $uri], [], [], $home, $prefix));
    }

    public function test_ac6_custom_rest_prefix(): void
    {
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => '/api/novamira/v1/upload'], [], [], '', 'api'));
    }

    public function test_ac6_no_request_uri_is_null(): void
    {
        self::assertNull(self::resolve([]));
        self::assertNull(self::resolve(['REQUEST_URI' => ['/wp-json/x']]));
    }

    public function test_ac6_no_url_decoding(): void
    {
        $route = self::resolve(['REQUEST_URI' => '/wp-json/novamira/v1/uplo%61d']);
        self::assertSame('/novamira/v1/uplo%61d', $route);
        self::assertFalse(Gate::is_link_route($route));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['route' => $route])));
    }

    public function test_ac6_root_rest_paths_resolve_to_slash(): void
    {
        self::assertSame('/', self::resolve(['REQUEST_URI' => '/wp-json']));
        self::assertSame('/', self::resolve(['REQUEST_URI' => '/wp-json/']));
        self::assertSame('/', self::resolve(['REQUEST_URI' => '/blog/wp-json/?x=1'], [], [], '/blog'));
        self::assertFalse(Gate::is_link_route('/'));
    }

    public function test_ac6_path_info_that_agrees_is_fine(): void
    {
        $server = [
            'REQUEST_URI' => '/blog/index.php/wp-json/novamira/v1/upload',
            'PATH_INFO'   => '/wp-json/novamira/v1/upload',
        ];
        self::assertSame('/novamira/v1/upload', self::resolve($server, [], [], '/blog'));
    }

    public function test_ac6_path_info_that_differs_is_null(): void
    {
        $server = [
            'REQUEST_URI' => '/index.php/wp-json/novamira/v1/upload',
            'PATH_INFO'   => '/wp-json/mcp/novamira',
        ];
        self::assertNull(self::resolve($server));

        $server = [
            'REQUEST_URI' => '/wp-json/novamira/v1/upload',
            'PATH_INFO'   => '/novamira/v1/upload', // no prefix: cannot be derived
        ];
        self::assertNull(self::resolve($server));
    }

    public function test_ac6_empty_path_info_is_ignored(): void
    {
        $server = ['REQUEST_URI' => '/wp-json/novamira/v1/upload', 'PATH_INFO' => ''];
        self::assertSame('/novamira/v1/upload', self::resolve($server));
    }

    public function test_ac6_rest_route_param_wins_even_when_path_info_set(): void
    {
        $server = [
            'REQUEST_URI' => '/index.php/wp-json/novamira/v1/upload?rest_route=/mcp/novamira',
            'PATH_INFO'   => '/wp-json/novamira/v1/upload',
        ];
        self::assertSame('/mcp/novamira', self::resolve($server, ['rest_route' => '/mcp/novamira']));
    }

    // ----------------------------------------------------------------- fx-12: rest_route only where WordPress parses it

    public static function rest_route_not_parsed_here(): array
    {
        return [
            'admin-ajax.php'                 => ['/wp-admin/admin-ajax.php', ''],
            'wp-admin/'                      => ['/wp-admin/', ''],
            'wp-admin'                       => ['/wp-admin', ''],
            'admin-post.php'                 => ['/wp-admin/admin-post.php', ''],
            'options page'                   => ['/wp-admin/options-general.php', ''],
            'xmlrpc.php'                     => ['/xmlrpc.php', ''],
            'wp-login.php'                   => ['/wp-login.php', ''],
            'wp-cron.php'                    => ['/wp-cron.php', ''],
            'a plugin file'                  => ['/wp-content/plugins/x/y.php', ''],
            'index.php glued'                => ['/index.phpx', ''],
            'index.php as a directory'       => ['/index.php.bak/', ''],
            'a front-end page'               => ['/hello-world/', ''],
            'subdir: admin-ajax.php'         => ['/sub/wp-admin/admin-ajax.php', '/sub'],
            'subdir: root outside home'      => ['/', '/sub'],
            'subdir: index.php outside home' => ['/index.php', '/sub'],
            'subdir: home glued'             => ['/subx/', '/sub'],
            'dot segment via the prefix'     => ['/wp-json/../wp-admin/admin-ajax.php', ''],
            'dot segment via index.php'      => ['/index.php/../wp-admin/admin-ajax.php', ''],
            'single dot segment'             => ['/wp-json/./x', ''],
            'trailing dot-dot'               => ['/wp-json/..', ''],
            'encoded dot segment'            => ['/wp-json/%2e%2e/wp-admin/admin-ajax.php', ''],
            'backslash'                      => ['/wp-json\\..\\wp-admin\\admin-ajax.php', ''],
            'empty path'                     => ['', ''],
        ];
    }

    #[DataProvider('rest_route_not_parsed_here')]
    public function test_fx12_rest_route_outside_the_front_controller_is_null(string $path, string $home): void
    {
        $route = '/novamira/v1/upload';
        self::assertNull(self::resolve(['REQUEST_URI' => $path . '?rest_route=' . $route], ['rest_route' => $route], [], $home), 'GET');
        self::assertNull(self::resolve(['REQUEST_URI' => $path], [], ['rest_route' => $route], $home), 'POST');
        self::assertNull(self::resolve(['REQUEST_URI' => $path . '?x=1'], ['rest_route' => $route], ['rest_route' => $route], $home), 'GET + POST');
    }

    public function test_fx12_rest_route_without_a_request_uri_is_null(): void
    {
        self::assertNull(self::resolve([], ['rest_route' => '/novamira/v1/upload']));
        self::assertNull(self::resolve(['REQUEST_URI' => ['/']], ['rest_route' => '/novamira/v1/upload']));
    }

    public static function rest_route_parsed_here(): array
    {
        return [
            'root'                  => ['/', ''],
            'index.php'             => ['/index.php', ''],
            'index.php/'            => ['/index.php/', ''],
            'rest prefix'           => ['/wp-json/novamira/v1/upload', ''],
            'bare rest prefix'      => ['/wp-json', ''],
            'index.php + prefix'    => ['/index.php/wp-json/mcp/novamira', ''],
            'subdir home'           => ['/sub/', '/sub'],
            'subdir home, no slash' => ['/sub', '/sub'],
            'subdir index.php'      => ['/sub/index.php', '/sub'],
            'subdir index.php/'     => ['/sub/index.php/', '/sub/'],
            'subdir rest prefix'    => ['/sub/wp-json/x', '/sub'],
        ];
    }

    #[DataProvider('rest_route_parsed_here')]
    public function test_fx12_rest_route_on_the_front_controller_resolves(string $path, string $home): void
    {
        $route = '/novamira/v1/upload';
        self::assertSame($route, self::resolve(['REQUEST_URI' => $path . '?rest_route=' . $route], ['rest_route' => $route], [], $home), 'GET');
        self::assertSame($route, self::resolve(['REQUEST_URI' => $path], [], ['rest_route' => $route], $home), 'POST');
    }

    // ----------------------------------------------------------------- fx-13: PATH_INFO with the home path

    public static function path_info_shapes(): array
    {
        return [
            'prefix only'            => ['/sub/wp-json/novamira/v1/upload', '/wp-json/novamira/v1/upload'],
            'home + prefix'          => ['/sub/wp-json/novamira/v1/upload', '/sub/wp-json/novamira/v1/upload'],
            'home + index + prefix'  => ['/sub/index.php/wp-json/novamira/v1/upload', '/sub/index.php/wp-json/novamira/v1/upload'],
            'index URI, home + pfx'  => ['/sub/index.php/wp-json/novamira/v1/upload', '/sub/wp-json/novamira/v1/upload'],
            'with a query'           => ['/sub/wp-json/novamira/v1/upload?x=1', '/sub/wp-json/novamira/v1/upload'],
        ];
    }

    #[DataProvider('path_info_shapes')]
    public function test_fx13_path_info_with_or_without_the_home_path_agrees(string $uri, string $info): void
    {
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => $uri, 'PATH_INFO' => $info], [], [], '/sub'));
        self::assertSame('/novamira/v1/upload', self::resolve(['REQUEST_URI' => $uri, 'PATH_INFO' => $info], [], [], '/sub/'));
    }

    public static function path_info_mismatches(): array
    {
        return [
            'home + prefix, other route'  => ['/sub/wp-json/mcp/novamira'],
            'index + prefix, other route' => ['/sub/index.php/wp-json/mcp/novamira'],
            'other home'                  => ['/other/wp-json/novamira/v1/upload'],
            'home glued'                  => ['/subwp-json/novamira/v1/upload'],
            'no prefix'                   => ['/novamira/v1/upload'],
            'home, no prefix'             => ['/sub/novamira/v1/upload'],
            'prefix twice'                => ['/sub/wp-json/sub/wp-json/novamira/v1/upload'],
        ];
    }

    #[DataProvider('path_info_mismatches')]
    public function test_fx13_path_info_that_tells_another_story_is_null(string $info): void
    {
        self::assertNull(self::resolve(['REQUEST_URI' => '/sub/wp-json/novamira/v1/upload', 'PATH_INFO' => $info], [], [], '/sub'));
    }

    // ----------------------------------------------------------------- AC-7

    public function test_ac7_order_inactive_first(): void
    {
        self::assertSame(Gate::INACTIVE, Gate::decide(self::ctx([
            'key_hash' => null, 'header' => self::KEY, 'open_until' => 1_700_000_999, 'route' => '/novamira/v1/upload',
        ])));
    }

    public function test_ac7_order_key_before_manual_before_link(): void
    {
        $all = self::ctx(['header' => self::KEY, 'open_until' => 1_700_000_999, 'route' => '/novamira/v1/upload']);
        self::assertSame(Gate::OPEN_KEY, Gate::decide($all));

        $all['header'] = 'wrong-but-ignored?';
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide($all));

        $all['open_until'] = 1_700_000_000; // equal to now: not strictly greater
        self::assertSame(Gate::OPEN_LINK, Gate::decide($all));

        $all['route'] = '/mcp/novamira';
        self::assertSame(Gate::LOCKED, Gate::decide($all));
    }

    public function test_ac7_manual_open_requires_open_until_strictly_after_now(): void
    {
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide(self::ctx(['open_until' => 1_700_000_001])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['open_until' => 1_700_000_000])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['open_until' => 0])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['open_until' => -5])));
    }

    public function test_ac7_manual_open_accepts_numeric_strings_only_as_ints(): void
    {
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide(self::ctx(['open_until' => '1700000001'])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['open_until' => 'soon'])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['open_until' => null])));
    }

    public static function malformed_hashes(): array
    {
        return [
            'uppercase'      => [strtoupper(hash('sha256', 'k'))],
            'too short'      => [substr(hash('sha256', 'k'), 0, 63)],
            'too long'       => [hash('sha256', 'k') . 'a'],
            'non hex'        => [str_repeat('g', 64)],
            'empty'          => [''],
            'spaces'         => [str_repeat(' ', 64)],
        ];
    }

    #[DataProvider('malformed_hashes')]
    public function test_ac7_malformed_hash_means_active_and_no_key_matches(string $hash): void
    {
        self::assertFalse(Gate::is_valid_hash($hash));
        // Active: not inactive.
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['key_hash' => $hash])));
        // No key can match, even one whose digest "would" match after normalisation.
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['key_hash' => $hash, 'header' => self::KEY])));
        self::assertFalse(Gate::verify_key(self::KEY, $hash));
        // Manual open and link routes still work: the gate is merely active.
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide(self::ctx(['key_hash' => $hash, 'open_until' => 1_700_000_999])));
        self::assertSame(Gate::OPEN_LINK, Gate::decide(self::ctx(['key_hash' => $hash, 'route' => '/novamira/v1/upload'])));
    }

    public function test_ac7_is_valid_hash(): void
    {
        self::assertTrue(Gate::is_valid_hash(self::hash()));
        self::assertTrue(Gate::is_valid_hash(str_repeat('0', 64)));
        self::assertFalse(Gate::is_valid_hash(null));
        self::assertFalse(Gate::is_valid_hash(''));
        self::assertFalse(Gate::is_valid_hash(strtoupper(self::hash())));
        self::assertFalse(Gate::is_valid_hash(self::hash() . "\n"));
    }

    public function test_ac7_is_open_only_for_the_three_open_states(): void
    {
        self::assertTrue(Gate::is_open(Gate::OPEN_KEY));
        self::assertTrue(Gate::is_open(Gate::OPEN_MANUAL));
        self::assertTrue(Gate::is_open(Gate::OPEN_LINK));
        self::assertFalse(Gate::is_open(Gate::LOCKED));
        self::assertFalse(Gate::is_open(Gate::INACTIVE));
        self::assertFalse(Gate::is_open('open'));
        self::assertFalse(Gate::is_open(''));
    }

    public function test_ac7_decide_never_throws_on_garbage_context(): void
    {
        $garbage = self::ctx([
            'key_hash'   => ['not', 'a', 'string'],
            'header'     => ['x'],
            'route'      => 42,
            'open_until' => ['y'],
            'now'        => 'z',
        ]);
        self::assertSame(Gate::LOCKED, Gate::decide($garbage));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['key_hash' => 123])));
    }

    // ------------------------------------------------- key generation (AC-13 pure part)

    public function test_generate_key_is_64_lowercase_hex_and_random(): void
    {
        $a = Gate::generate_key();
        $b = Gate::generate_key();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $b);
        self::assertNotSame($a, $b);
        self::assertTrue(Gate::verify_key($a, Gate::hash_key($a)));
        self::assertFalse(Gate::verify_key($b, Gate::hash_key($a)));
    }

    // ---------------------------------------------------------------- AC-11

    public static function minutes(): array
    {
        return [
            'default on null'   => [null, 30],
            'default on empty'  => ['', 30],
            'non numeric'       => ['abc', 30],
            'array'             => [[10], 30],
            'bool true'         => [true, 30],
            'bool false'        => [false, 30],
            'in range int'      => [45, 45],
            'in range string'   => ['45', 45],
            'float truncates'   => [45.9, 45],
            'numeric string sp' => [' 45 ', 45],
            'min'               => [5, 5],
            'max'               => [240, 240],
            'below min'         => [4, 5],
            'zero'              => [0, 5],
            'negative'          => [-10, 5],
            'above max'         => [241, 240],
            'huge'              => [999999, 240],
            'string below'      => ['1', 5],
            'string above'      => ['1000', 240],
        ];
    }

    #[DataProvider('minutes')]
    public function test_ac11_clamp_minutes(mixed $value, int $expected): void
    {
        self::assertSame($expected, Gate::clamp_minutes($value));
    }
}
