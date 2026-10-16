<?php
/**
 * Independent edge-case tests for the v0.2.0 pure logic (several keys, wrong-try limit), written
 * by the session model from the spec (AC-32, AC-39 … AC-44, AC-48, AC-49; seams S-2, S-7).
 *
 * Rule these tests enforce: bad input can only make the result STRICTER. A broken key
 * configuration never makes the gate inactive, and a blocked address never opens with a key.
 */

declare(strict_types=1);

namespace NovaGate\Tests\Adversarial;

use NovaGate\Gate;
use NovaGate\Limiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

defined('ABSPATH') || define('ABSPATH', sys_get_temp_dir() . '/');

if (!class_exists(Gate::class)) {
    require_once dirname(__DIR__, 2) . '/includes/class-gate.php';
}
if (!class_exists(Limiter::class)) {
    require_once dirname(__DIR__, 2) . '/includes/class-limiter.php';
}

final class V02AdversarialTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private static function key(string $seed = 'a'): string
    {
        return str_repeat($seed . '1b2c3d4', 8);
    }

    private static function hash(string $seed = 'a'): string
    {
        return hash('sha256', self::key($seed));
    }

    // ------------------------------------------------------------------ several keys

    /** @return iterable<string, array{mixed}> */
    public static function brokenKeyLists(): iterable
    {
        yield 'empty array' => [[]];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'a string that is a valid hash' => [self::hash()];
        yield 'object' => [new \stdClass()];
        yield 'list of junk' => [[null, 1, 2.5, true, [], new \stdClass(), 'not-a-hash', '']];
        yield 'upper-case hash' => [[strtoupper(self::hash())]];
        yield 'hash with spaces' => [[' ' . self::hash() . ' ']];
        yield 'hash with newline' => [[self::hash() . "\n"]];
        yield 'nested list with the right hash' => [[[self::hash()]]];
        yield 'entry shape instead of hash' => [[['hash' => self::hash()]]];
    }

    #[DataProvider('brokenKeyLists')]
    public function test_a_broken_key_list_is_active_and_matches_nothing(mixed $list): void
    {
        $state = Gate::decide(['key_hashes' => $list, 'header' => self::key(), 'now' => self::NOW]);

        $this->assertSame(Gate::LOCKED, $state);
    }

    #[DataProvider('brokenKeyLists')]
    public function test_a_broken_key_list_does_not_hide_a_good_v010_hash(mixed $list): void
    {
        $ctx = ['key_hashes' => $list, 'key_hash' => self::hash('z'), 'now' => self::NOW];

        $this->assertSame(Gate::OPEN_KEY, Gate::decide($ctx + ['header' => self::key('z')]));
        $this->assertSame(Gate::LOCKED, Gate::decide($ctx + ['header' => self::key('q')]));
    }

    public function test_inactive_only_when_both_are_null(): void
    {
        $this->assertSame(Gate::INACTIVE, Gate::decide(['header' => self::key()]));
        $this->assertSame(Gate::INACTIVE, Gate::decide(['key_hash' => null, 'key_hashes' => null, 'header' => self::key()]));
        $this->assertSame(Gate::LOCKED, Gate::decide(['key_hash' => null, 'key_hashes' => [], 'header' => self::key()]));
        $this->assertSame(Gate::LOCKED, Gate::decide(['key_hash' => [], 'key_hashes' => null, 'header' => self::key()]));
    }

    public function test_every_one_of_twenty_keys_opens_and_a_twenty_first_does_not(): void
    {
        $hashes = [];
        foreach (range('a', 't') as $seed) {
            $hashes[] = self::hash($seed);
        }
        $this->assertCount(Gate::MAX_KEYS, $hashes);
        foreach (range('a', 't') as $seed) {
            $this->assertSame(Gate::OPEN_KEY, Gate::decide(['key_hashes' => $hashes, 'header' => self::key($seed), 'now' => self::NOW]), $seed);
        }
        $this->assertSame(Gate::LOCKED, Gate::decide(['key_hashes' => $hashes, 'header' => self::key('u'), 'now' => self::NOW]));
    }

    public function test_a_hash_is_never_accepted_as_a_key(): void
    {
        // Sending the stored digest itself (64 hex = a well-formed key) must not open the gate.
        $this->assertFalse(Gate::verify_any(self::hash(), [self::hash()]));
        $this->assertSame(Gate::LOCKED, Gate::decide(['key_hashes' => [self::hash()], 'header' => self::hash(), 'now' => self::NOW]));
    }

    public function test_verify_any_survives_junk_without_throwing(): void
    {
        $junk = [null, 1, 1.5, true, [], [self::hash()], new \stdClass(), '', str_repeat('g', 64), self::hash('b')];

        $this->assertFalse(Gate::verify_any(self::key('a'), $junk));
        $this->assertTrue(Gate::verify_any(self::key('b'), $junk));
        $this->assertFalse(Gate::verify_any(null, $junk));
        $this->assertFalse(Gate::verify_any('', [self::hash()]));
        $this->assertFalse(Gate::verify_any(self::key() . "\n", [self::hash()]));
    }

    // ------------------------------------------------------------------ blocked

    /** @return iterable<string, array{mixed}> */
    public static function truthyBlockedValues(): iterable
    {
        yield 'true' => [true];
        yield 'int 1' => [1];
        yield 'string 1' => ['1'];
        yield 'string yes' => ['yes'];
        yield 'array' => [[1]];
    }

    #[DataProvider('truthyBlockedValues')]
    public function test_a_blocked_request_never_opens_with_a_right_key(mixed $blocked): void
    {
        $ctx = ['key_hashes' => [self::hash()], 'header' => self::key(), 'blocked' => $blocked, 'now' => self::NOW];

        $this->assertSame(Gate::LOCKED, Gate::decide($ctx));
        $this->assertSame(Gate::LOCKED, Gate::decide(['key_hash' => self::hash()] + $ctx));
    }

    public function test_blocked_leaves_the_other_doors_open(): void
    {
        $ctx = ['key_hashes' => [self::hash()], 'header' => self::key(), 'blocked' => true, 'now' => self::NOW];

        $this->assertSame(Gate::OPEN_MANUAL, Gate::decide($ctx + ['open_until' => self::NOW + 60]));
        $this->assertSame(Gate::OPEN_LINK, Gate::decide($ctx + ['route' => '/novamira/v1/upload']));
        $this->assertSame(Gate::LOCKED, Gate::decide($ctx + ['route' => '/mcp/novamira']));
    }

    public function test_not_blocked_is_only_false_or_absent(): void
    {
        $ctx = ['key_hashes' => [self::hash()], 'header' => self::key(), 'now' => self::NOW];

        $this->assertSame(Gate::OPEN_KEY, Gate::decide($ctx));
        $this->assertSame(Gate::OPEN_KEY, Gate::decide($ctx + ['blocked' => false]));
    }

    // ------------------------------------------------------------------ parse_hash_list / key ids

    public function test_parse_hash_list(): void
    {
        $a = self::hash('a');
        $b = self::hash('b');

        $this->assertSame([$a], Gate::parse_hash_list($a));
        $this->assertSame([$a, $b], Gate::parse_hash_list(" $a ,\n\t$b,, "));
        $this->assertSame([$a, $b], Gate::parse_hash_list([$a, 5, null, [$a], " $b "]));
        $this->assertSame([], Gate::parse_hash_list(''));
        $this->assertSame([], Gate::parse_hash_list(null));
        $this->assertSame([], Gate::parse_hash_list(42));
        $this->assertSame([], Gate::parse_hash_list(true));
        $this->assertSame([], Gate::parse_hash_list(new \stdClass()));
    }

    public function test_a_constant_with_no_valid_hash_is_active_and_unmatchable(): void
    {
        foreach (['', 'nonsense', 42, false, [], [1, 2]] as $constant) {
            $state = Gate::decide(['key_hashes' => Gate::parse_hash_list($constant), 'header' => self::key(), 'now' => self::NOW]);
            $this->assertSame(Gate::LOCKED, $state, var_export($constant, true));
        }
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function keyIds(): iterable
    {
        yield 'one letter' => ['a', true];
        yield 'digits only' => ['2026', true];
        yield 'forty chars' => [str_repeat('a', 40), true];
        yield 'forty-one chars' => [str_repeat('a', 41), false];
        yield 'leading hyphen' => ['-a', false];
        yield 'upper case' => ['Hub', false];
        yield 'underscore' => ['hub_1', false];
        yield 'space' => ['hub 1', false];
        yield 'trailing newline' => ["hub\n", false];
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
        yield 'integer' => [2026, false];
        yield 'array' => [['hub'], false];
        yield 'dot' => ['hub.1', false];
        yield 'unicode' => ['hüb', false];
    }

    #[DataProvider('keyIds')]
    public function test_key_id_rule(mixed $id, bool $valid): void
    {
        $this->assertSame($valid, Gate::is_valid_key_id($id));
    }

    // ------------------------------------------------------------------ limiter

    public function test_window_and_block_boundaries_are_exact(): void
    {
        $t0    = self::NOW;
        $table = [];
        for ($i = 0; $i < 9; $i++) {
            $table = Limiter::record($table, 'x', $t0, 10);
        }
        // The 10th try exactly 600 s after the first still counts ("at most 600 seconds").
        $onEdge = Limiter::record($table, 'x', $t0 + Limiter::WINDOW, 10);
        $this->assertTrue(Limiter::is_blocked($onEdge, 'x', $t0 + Limiter::WINDOW));
        $this->assertTrue(Limiter::is_blocked($onEdge, 'x', $t0 + Limiter::WINDOW + Limiter::BLOCK - 1));
        $this->assertFalse(Limiter::is_blocked($onEdge, 'x', $t0 + Limiter::WINDOW + Limiter::BLOCK));

        // One second later it does not: a new window starts with one try.
        $late = Limiter::record($table, 'x', $t0 + Limiter::WINDOW + 1, 10);
        $this->assertFalse(Limiter::is_blocked($late, 'x', $t0 + Limiter::WINDOW + 1));
        $this->assertSame(1, $late['x']['n']);
    }

    public function test_slow_guessing_under_the_limit_is_never_blocked_and_fast_guessing_always_is(): void
    {
        // 9 tries per window, for 50 windows: never blocked.
        $table = [];
        $now   = self::NOW;
        for ($window = 0; $window < 50; $window++) {
            for ($i = 0; $i < 9; $i++) {
                $table = Limiter::record($table, 'slow', $now + $i, 10);
                $this->assertFalse(Limiter::is_blocked($table, 'slow', $now + $i));
            }
            $now += Limiter::WINDOW + 1;
        }

        // A fast guesser: over one hour, count the tries that were not refused.
        $table   = [];
        $allowed = 0;
        for ($second = 0; $second < 3600; $second++) {
            $at = self::NOW + $second;
            if (Limiter::is_blocked($table, 'fast', $at)) {
                continue;
            }
            $allowed++;
            $table = Limiter::record($table, 'fast', $at, 10);
        }
        // Each cycle is 10 tries then 900 s of block: at most 4 full cycles fit in an hour.
        $this->assertLessThanOrEqual(40, $allowed);
        $this->assertGreaterThanOrEqual(30, $allowed);
    }

    public function test_a_flood_of_other_addresses_cannot_unblock_a_blocked_one(): void
    {
        $table = [];
        for ($i = 0; $i < 10; $i++) {
            $table = Limiter::record($table, 'victim', self::NOW, 10);
        }
        $this->assertTrue(Limiter::is_blocked($table, 'victim', self::NOW));

        for ($i = 0; $i < 1000; $i++) {
            $table = Limiter::record($table, sprintf('%016x', $i), self::NOW + 1 + ($i % 60), 10);
            $this->assertLessThanOrEqual(Limiter::CAP, count($table));
        }
        $this->assertTrue(Limiter::is_blocked($table, 'victim', self::NOW + 61));
    }

    public function test_more_blocked_addresses_than_the_cap_still_respects_the_cap(): void
    {
        $table = [];
        for ($address = 0; $address < Limiter::CAP + 50; $address++) {
            for ($i = 0; $i < 3; $i++) {
                $table = Limiter::record($table, sprintf('%016x', $address), self::NOW + $address, 3);
            }
        }
        $this->assertCount(Limiter::CAP, $table);
        $this->assertSame(Limiter::CAP, Limiter::blocked_count($table, self::NOW + 300));
        // fx-06 return 1 (availability first): a newcomer is always counted, so when every other
        // entry is a block, the block that ends soonest makes room. The 200 latest blocks stay.
        for ($address = 0; $address < 50; $address++) {
            $this->assertFalse(Limiter::is_blocked($table, sprintf('%016x', $address), self::NOW + 300), "early block $address made room");
        }
        for ($address = 50; $address < Limiter::CAP + 50; $address++) {
            $this->assertTrue(Limiter::is_blocked($table, sprintf('%016x', $address), self::NOW + 300));
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function junkTables(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['a:0:{}'];
        yield 'int' => [7];
        yield 'object' => [new \stdClass()];
        yield 'list of scalars' => [[1, 'x', null, true]];
        yield 'negative numbers' => [['x' => ['n' => -5, 'first' => -1, 'last' => -1, 'until' => -1]]];
        yield 'floats and text' => [['x' => ['n' => 2.5, 'first' => 'soon', 'last' => [], 'until' => '1e99']]];
        yield 'missing fields' => [['x' => []]];
        yield 'until far in the future as text' => [['y' => ['n' => 1, 'first' => 1, 'last' => 1, 'until' => '99999999999x']]];
    }

    #[DataProvider('junkTables')]
    public function test_junk_tables_never_throw_and_never_block_or_unblock_by_accident(mixed $junk): void
    {
        $this->assertFalse(Limiter::is_blocked($junk, 'x', self::NOW));
        $this->assertSame(0, Limiter::blocked_count($junk, self::NOW));
        $this->assertIsInt(Limiter::last_try($junk));

        $table = Limiter::record($junk, 'x', self::NOW, 3);
        $this->assertSame(['n' => 1, 'first' => self::NOW, 'last' => self::NOW, 'until' => 0], $table['x']);
        $this->assertFalse(Limiter::is_blocked($table, 'x', self::NOW));
    }

    public function test_all_digit_ids_work_through_a_round_trip_of_php_array_keys(): void
    {
        $id    = '1234567890123456';
        $table = [];
        for ($i = 0; $i < 3; $i++) {
            // unserialize(serialize()) is what the options table does to the array.
            $table = unserialize(serialize(Limiter::record($table, $id, self::NOW, 3)));
        }
        $this->assertTrue(Limiter::is_blocked($table, $id, self::NOW + 1));
        $this->assertSame(1, Limiter::blocked_count($table, self::NOW + 1));
        $this->assertFalse(Limiter::is_blocked($table, '1234567890123457', self::NOW + 1));
    }

    public function test_address_id_never_contains_the_address_and_depends_on_the_salt(): void
    {
        foreach (['203.0.113.7', '2001:db8::1', 'unknown', ''] as $address) {
            $id = Limiter::address_id($address, 'salt-one');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/D', $id);
            $this->assertNotSame($id, Limiter::address_id($address, 'salt-two'));
            if ($address !== '') {
                $this->assertStringNotContainsString($address, $id);
            }
        }
        $this->assertNotSame(Limiter::address_id('1.2.3.4', ''), Limiter::address_id('1.2.3.5', ''));
    }

    public function test_limit_off_records_nothing_and_blocks_nobody(): void
    {
        $table = [];
        for ($i = 0; $i < 500; $i++) {
            $table = Limiter::record($table, 'x', self::NOW + $i, Limiter::clamp_max(0));
        }
        $this->assertSame([], $table);
        $this->assertFalse(Limiter::is_blocked($table, 'x', self::NOW + 500));
    }

    public function test_clamp_max_never_returns_one_or_two_or_a_negative_number(): void
    {
        foreach ([-1000, -1, 1, 2, PHP_INT_MIN] as $value) {
            $this->assertSame(Limiter::MIN_MAX, Limiter::clamp_max($value), (string) $value);
        }
        $this->assertSame(Limiter::MAX_MAX, Limiter::clamp_max(PHP_INT_MAX));
        foreach (['0', '5', 5.0, null, true, false, [], '', 'off'] as $value) {
            $this->assertSame(Limiter::DEFAULT_MAX, Limiter::clamp_max($value), var_export($value, true));
        }
    }
}
