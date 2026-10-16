<?php
/**
 * Unit tests for the v0.2.0 additions to NovaGate\Gate (seam S-2 additions).
 * Pure PHP, no WordPress. Each test names the acceptance criterion it proves.
 */

declare(strict_types=1);

namespace NovaGate\Tests\Unit;

use NovaGate\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GateKeysTest extends TestCase
{
    private const KEY_A = 'a3f1c2d4e5b6978877665544332211009988776655443322110000ffeeddccbb';
    private const KEY_B = 'b4e2d3c5f6a7089988776655443322110099887766554433221100aabbccddee';
    private const KEY_C = 'c5f3e4d6a7b8190099887766554433221100998877665544332211bbccddeeff';
    private const NOW   = 1_700_000_000;

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private static function ctx(array $over = []): array
    {
        return array_merge([
            'key_hashes' => [self::hash(self::KEY_A), self::hash(self::KEY_B)],
            'header'     => null,
            'route'      => null,
            'open_until' => 0,
            'now'        => self::NOW,
            'blocked'    => false,
        ], $over);
    }

    // ---------------------------------------------------------------- AC-32

    public function test_ac32_any_configured_key_opens(): void
    {
        self::assertSame(Gate::OPEN_KEY, Gate::decide(self::ctx(['header' => self::KEY_A])));
        self::assertSame(Gate::OPEN_KEY, Gate::decide(self::ctx(['header' => self::KEY_B])));
    }

    public function test_ac32_a_well_formed_third_key_is_locked(): void
    {
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => self::KEY_C])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => null])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => ''])));
    }

    public function test_ac32_verify_any_matches_first_middle_and_last_entry(): void
    {
        $hashes = [self::hash(self::KEY_A), self::hash(self::KEY_B), self::hash(self::KEY_C)];
        self::assertTrue(Gate::verify_any(self::KEY_A, $hashes));
        self::assertTrue(Gate::verify_any(self::KEY_B, $hashes));
        self::assertTrue(Gate::verify_any(self::KEY_C, $hashes));
        self::assertFalse(Gate::verify_any('d' . substr(self::KEY_C, 1), $hashes));
    }

    public function test_ac32_verify_any_rejects_malformed_headers(): void
    {
        $hashes = [self::hash(self::KEY_A)];
        self::assertFalse(Gate::verify_any(null, $hashes));
        self::assertFalse(Gate::verify_any('', $hashes));
        self::assertFalse(Gate::verify_any(str_repeat('a', 31), [self::hash(str_repeat('a', 31))]));
        self::assertFalse(Gate::verify_any(str_repeat('a', 129), [self::hash(str_repeat('a', 129))]));
        self::assertFalse(Gate::verify_any(self::KEY_A . '!', [self::hash(self::KEY_A . '!')]));
        self::assertFalse(Gate::verify_any(self::KEY_A, []));
    }

    // ---------------------------------------------------------------- AC-33

    public function test_ac33_single_key_hash_still_opens_without_key_hashes(): void
    {
        $ctx = ['key_hash' => self::hash(self::KEY_A), 'header' => self::KEY_A, 'now' => self::NOW];
        self::assertSame(Gate::OPEN_KEY, Gate::decide($ctx));
    }

    public function test_ac33_key_hash_and_key_hashes_are_both_honoured(): void
    {
        $ctx = self::ctx(['key_hash' => self::hash(self::KEY_C), 'header' => self::KEY_C]);
        self::assertSame(Gate::OPEN_KEY, Gate::decide($ctx));
        self::assertSame(Gate::OPEN_KEY, Gate::decide(self::ctx(['key_hash' => self::hash(self::KEY_C), 'header' => self::KEY_A])));
    }

    public function test_ac33_inactive_only_when_both_are_null(): void
    {
        self::assertSame(Gate::INACTIVE, Gate::decide(['key_hash' => null, 'key_hashes' => null, 'header' => self::KEY_A]));
        self::assertSame(Gate::INACTIVE, Gate::decide([]));
        self::assertSame(Gate::LOCKED, Gate::decide(['key_hash' => null, 'key_hashes' => [], 'header' => self::KEY_A]));
        self::assertSame(Gate::LOCKED, Gate::decide(['key_hash' => '', 'key_hashes' => null]));
    }

    // ---------------------------------------------------------------- AC-38

    public function test_ac38_max_keys_is_twenty(): void
    {
        self::assertSame(20, Gate::MAX_KEYS);
    }

    // ---------------------------------------------------------------- AC-39

    public static function hash_list_provider(): array
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);

        return [
            'one hash'            => [$a, [$a]],
            'comma'               => ["$a,$b", [$a, $b]],
            'comma and spaces'    => [" $a , $b ", [$a, $b]],
            'whitespace only'     => ["$a\n$b\t", [$a, $b]],
            'array'               => [[$a, $b], [$a, $b]],
            'array with junk'     => [[$a, 5, null, ['x'], " $b "], [$a, $b]],
            'array with empties'  => [['', ' ', $a], [$a]],
            'empty string'        => ['', []],
            'separators only'     => [', ,', []],
            'not validated'       => ['zzz, not-a-hash', ['zzz', 'not-a-hash']],
            'int'                 => [42, []],
            'null'                => [null, []],
            'bool'                => [true, []],
            'float'               => [1.5, []],
        ];
    }

    #[DataProvider('hash_list_provider')]
    public function test_ac39_parse_hash_list(mixed $value, array $expected): void
    {
        self::assertSame($expected, Gate::parse_hash_list($value));
    }

    public function test_ac39_constant_with_no_valid_hash_is_active_and_unmatchable(): void
    {
        $hashes = Gate::parse_hash_list('zzz, not-a-hash');
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['key_hashes' => $hashes, 'header' => self::KEY_A])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['key_hashes' => $hashes, 'header' => 'zzz'])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['key_hashes' => Gate::parse_hash_list(''), 'header' => self::KEY_A])));
    }

    // ---------------------------------------------------------------- AC-40

    public function test_ac40_invalid_entries_never_match_but_the_gate_stays_active(): void
    {
        $broken = ['', 'ABCD', str_repeat('A', 64), 123, null, ['x'], self::hash(self::KEY_A) . 'ff'];
        self::assertFalse(Gate::verify_any(self::KEY_A, $broken));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['key_hashes' => $broken, 'header' => self::KEY_A])));
        // The upper-case digest is not valid storage even when it is the right digest.
        self::assertFalse(Gate::verify_any(self::KEY_A, [strtoupper(self::hash(self::KEY_A))]));
    }

    public function test_ac40_valid_entries_still_match_next_to_broken_ones(): void
    {
        $mixed = ['', 123, self::hash(self::KEY_B), ['x']];
        self::assertTrue(Gate::verify_any(self::KEY_B, $mixed));
        self::assertSame(Gate::OPEN_KEY, Gate::decide(self::ctx(['key_hashes' => $mixed, 'header' => self::KEY_B])));
    }

    public function test_ac40_key_hashes_that_is_not_an_array_is_active_and_unmatchable(): void
    {
        self::assertSame(Gate::LOCKED, Gate::decide(['key_hashes' => 'oops', 'header' => self::KEY_A]));
        self::assertSame(Gate::LOCKED, Gate::decide(['key_hashes' => 7, 'header' => self::KEY_A]));
    }

    // ---------------------------------------------------------------- AC-42 / AC-46 (pure part)

    public function test_ac42_blocked_ignores_the_right_key(): void
    {
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => self::KEY_A, 'blocked' => true])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => self::KEY_B, 'blocked' => true, 'route' => '/mcp/novamira'])));
    }

    public function test_ac42_only_false_or_absent_means_not_blocked(): void
    {
        // Changed by the integrator: a flag of the wrong type is the stricter reading (blocked).
        self::assertSame(Gate::OPEN_KEY, Gate::decide(self::ctx(['header' => self::KEY_A, 'blocked' => false])));
        self::assertSame(Gate::OPEN_KEY, Gate::decide(self::ctx(['header' => self::KEY_A, 'blocked' => null])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => self::KEY_A, 'blocked' => 0])));
        self::assertSame(Gate::LOCKED, Gate::decide(self::ctx(['header' => self::KEY_A, 'blocked' => 'false'])));
    }

    public function test_ac46_blocked_address_still_gets_manual_open_and_link_routes(): void
    {
        self::assertSame(Gate::OPEN_MANUAL, Gate::decide(self::ctx(['header' => self::KEY_A, 'blocked' => true, 'open_until' => self::NOW + 10])));
        self::assertSame(Gate::OPEN_LINK, Gate::decide(self::ctx(['header' => self::KEY_C, 'blocked' => true, 'route' => '/novamira/v1/upload'])));
        self::assertSame(Gate::OPEN_LINK, Gate::decide(self::ctx(['header' => null, 'blocked' => true, 'route' => '/novamira/v1/admin-access/abc'])));
    }

    public function test_ac42_blocked_with_inactive_gate_is_still_inactive(): void
    {
        self::assertSame(Gate::INACTIVE, Gate::decide(['blocked' => true, 'header' => self::KEY_A]));
    }

    // ---------------------------------------------------------------- key id (S-1, AC-55)

    public static function good_ids(): array
    {
        return [['a'], ['0'], ['ci'], ['hub-1'], ['default'], ['a-'], [str_repeat('a', 40)], ['0' . str_repeat('-', 39)]];
    }

    #[DataProvider('good_ids')]
    public function test_key_id_pattern_accepts(string $id): void
    {
        self::assertTrue(Gate::is_valid_key_id($id));
    }

    public static function bad_ids(): array
    {
        return [
            [''], ['-a'], ['A'], ['Hub'], ['a b'], ['a_b'], ['a.b'], ['ä'], ["a\n"], ['a/b'],
            [str_repeat('a', 41)], [42], [null], [true], [['a']], [1.5],
        ];
    }

    #[DataProvider('bad_ids')]
    public function test_key_id_pattern_rejects(mixed $id): void
    {
        self::assertFalse(Gate::is_valid_key_id($id));
    }
}
