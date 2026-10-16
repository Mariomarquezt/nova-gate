<?php
/**
 * Unit tests for NovaGate\Limiter (seam S-7). Pure PHP, no WordPress.
 * Each test names the acceptance criterion it proves.
 */

declare(strict_types=1);

namespace NovaGate\Tests\Unit;

use NovaGate\Limiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LimiterTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const X   = 'aaaaaaaaaaaaaaaa';
    private const Y   = 'bbbbbbbbbbbbbbbb';

    /** $n wrong tries by $id, one per second starting at $start. */
    private static function tries(array $table, string $id, int $n, int $start, int $max = 10): array
    {
        for ($i = 0; $i < $n; $i++) {
            $table = Limiter::record($table, $id, $start + $i, $max);
        }

        return $table;
    }

    // ---------------------------------------------------------------- constants

    public function test_constants_match_the_spec(): void
    {
        self::assertSame(10, Limiter::DEFAULT_MAX);
        self::assertSame(3, Limiter::MIN_MAX);
        self::assertSame(100, Limiter::MAX_MAX);
        self::assertSame(600, Limiter::WINDOW);
        self::assertSame(900, Limiter::BLOCK);
        self::assertSame(200, Limiter::CAP);
    }

    // ---------------------------------------------------------------- AC-41

    public function test_ac41_nine_tries_do_not_block(): void
    {
        $table = self::tries([], self::X, 9, self::NOW);
        self::assertFalse(Limiter::is_blocked($table, self::X, self::NOW + 9));
        self::assertSame(9, $table[self::X]['n']);
        self::assertSame(0, $table[self::X]['until']);
    }

    public function test_ac41_tenth_try_inside_600s_blocks_for_900s_from_the_tenth(): void
    {
        $table = self::tries([], self::X, 9, self::NOW);
        $tenth = self::NOW + 600; // exactly 600 s after the first: still inside the window
        $table = Limiter::record($table, self::X, $tenth, 10);

        self::assertSame($tenth + 900, $table[self::X]['until']);
        self::assertTrue(Limiter::is_blocked($table, self::X, $tenth));
        self::assertTrue(Limiter::is_blocked($table, self::X, $tenth + 899));
        self::assertFalse(Limiter::is_blocked($table, self::X, $tenth + 900));
    }

    public function test_ac41_tenth_try_after_the_window_starts_a_new_window(): void
    {
        $table = self::tries([], self::X, 9, self::NOW);
        $table = Limiter::record($table, self::X, self::NOW + 601, 10);

        self::assertFalse(Limiter::is_blocked($table, self::X, self::NOW + 601));
        self::assertSame(1, $table[self::X]['n']);
        self::assertSame(self::NOW + 601, $table[self::X]['first']);
    }

    public function test_ac41_max_is_honoured(): void
    {
        $table = self::tries([], self::X, 2, self::NOW, 3);
        self::assertFalse(Limiter::is_blocked($table, self::X, self::NOW + 2));
        $table = Limiter::record($table, self::X, self::NOW + 2, 3);
        self::assertTrue(Limiter::is_blocked($table, self::X, self::NOW + 2));
    }

    // ---------------------------------------------------------------- AC-42

    public function test_ac42_tries_during_a_block_are_not_counted_and_do_not_extend_it(): void
    {
        $table  = self::tries([], self::X, 10, self::NOW);
        $before = $table[self::X];
        $table  = Limiter::record($table, self::X, self::NOW + 100, 10);

        self::assertSame($before, $table[self::X]);
        self::assertTrue(Limiter::is_blocked($table, self::X, self::NOW + 100));
    }

    // ---------------------------------------------------------------- AC-43

    public function test_ac43_a_block_on_one_address_changes_nothing_for_another(): void
    {
        $table = self::tries([], self::X, 10, self::NOW);
        self::assertTrue(Limiter::is_blocked($table, self::X, self::NOW + 10));
        self::assertFalse(Limiter::is_blocked($table, self::Y, self::NOW + 10));

        $table = Limiter::record($table, self::Y, self::NOW + 10, 10);
        self::assertFalse(Limiter::is_blocked($table, self::Y, self::NOW + 10));
        self::assertSame(1, $table[self::Y]['n']);
        self::assertSame(10, $table[self::X]['n']);
    }

    // ---------------------------------------------------------------- AC-44

    public function test_ac44_after_the_block_ends_the_counter_starts_from_zero(): void
    {
        $table = self::tries([], self::X, 10, self::NOW);
        $until = $table[self::X]['until'];
        self::assertFalse(Limiter::is_blocked($table, self::X, $until));

        $table = Limiter::record($table, self::X, $until + 1, 10);
        self::assertSame(1, $table[self::X]['n']);
        self::assertSame($until + 1, $table[self::X]['first']);
        self::assertSame(0, $table[self::X]['until']);
        self::assertFalse(Limiter::is_blocked($table, self::X, $until + 1));
    }

    public function test_ac44_tries_whose_window_ended_are_forgotten(): void
    {
        $table = self::tries([], self::X, 5, self::NOW);
        // Another address writes 601 s later: X is neither blocked nor inside its window.
        $table = Limiter::record($table, self::Y, self::NOW + 601, 10);
        self::assertArrayNotHasKey(self::X, $table);
        self::assertArrayHasKey(self::Y, $table);
    }

    public function test_ac44_an_expired_block_is_dropped_on_the_next_write(): void
    {
        $table = self::tries([], self::X, 10, self::NOW);
        $until = $table[self::X]['until'];
        $table = Limiter::record($table, self::Y, $until, 10);
        self::assertArrayNotHasKey(self::X, $table);
    }

    public function test_ac44_entries_inside_their_window_or_blocked_are_kept_on_write(): void
    {
        $table = self::tries([], self::X, 3, self::NOW);
        $table = self::tries($table, self::Y, 10, self::NOW + 1);
        $table = Limiter::record($table, 'cccccccccccccccc', self::NOW + 600, 10);
        self::assertArrayHasKey(self::X, $table);
        self::assertArrayHasKey(self::Y, $table);
        self::assertArrayHasKey('cccccccccccccccc', $table);
    }

    // ---------------------------------------------------------------- AC-48

    public function test_ac48_address_id_is_the_first_16_hex_of_sha256_with_the_salt(): void
    {
        $expected = substr(hash('sha256', '203.0.113.9|pepper'), 0, 16);
        self::assertSame($expected, Limiter::address_id('203.0.113.9', 'pepper'));
        self::assertSame(substr(hash('sha256', '203.0.113.9|'), 0, 16), Limiter::address_id('203.0.113.9', ''));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', Limiter::address_id('unknown', ''));
        self::assertNotSame(Limiter::address_id('203.0.113.9', 'a'), Limiter::address_id('203.0.113.9', 'b'));
        self::assertNotSame(Limiter::address_id('203.0.113.9', 'a'), Limiter::address_id('203.0.113.8', 'a'));
    }

    public function test_ac48_entry_shape(): void
    {
        $table = Limiter::record([], self::X, self::NOW, 10);
        self::assertSame([self::X => ['n' => 1, 'first' => self::NOW, 'last' => self::NOW, 'until' => 0]], $table);

        $table = Limiter::record($table, self::X, self::NOW + 7, 10);
        self::assertSame(['n' => 2, 'first' => self::NOW, 'last' => self::NOW + 7, 'until' => 0], $table[self::X]);
    }

    public function test_ac48_table_never_holds_more_than_200_entries(): void
    {
        $table = [];
        for ($i = 0; $i < 300; $i++) {
            $table = Limiter::record($table, sprintf('%016x', $i), self::NOW + $i, 10);
            self::assertLessThanOrEqual(200, count($table));
            self::assertArrayHasKey(sprintf('%016x', $i), $table, "newcomer $i is counted");
        }
        self::assertCount(200, $table);
    }

    public function test_ac48_table_never_holds_more_than_200_entries_dropping_oldest_last_first(): void
    {
        $table = [];
        for ($i = 0; $i < 200; $i++) {
            $table = Limiter::record($table, sprintf('%016x', $i), self::NOW + $i, 10);
        }
        self::assertCount(200, $table);

        $table = Limiter::record($table, 'ffffffffffffffff', self::NOW + 200, 10);
        self::assertCount(200, $table);
        self::assertArrayNotHasKey(sprintf('%016x', 0), $table); // oldest `last` dropped
        self::assertArrayHasKey(sprintf('%016x', 1), $table);
        self::assertArrayHasKey('ffffffffffffffff', $table);
    }

    public function test_ac48_blocked_entries_are_dropped_last(): void
    {
        $table = self::tries([], self::X, 10, self::NOW); // blocked, oldest `last`
        for ($i = 0; $i < 200; $i++) {
            $table = Limiter::record($table, sprintf('%016x', $i), self::NOW + 20 + $i, 10);
        }
        self::assertCount(200, $table);
        self::assertArrayHasKey(self::X, $table);
        self::assertArrayNotHasKey(sprintf('%016x', 0), $table);
    }

    // ---------------------------------------------------------------- fx-06 (return 1): availability first

    /** A stored table of $n active blocks; block $i ends at NOW + 800 - $i (b0 ends last). */
    private static function blocks(int $n): array
    {
        $table = [];
        for ($i = 0; $i < $n; $i++) {
            $table[sprintf('%016x', $i)] = ['n' => 10, 'first' => self::NOW - 100, 'last' => self::NOW - 100, 'until' => self::NOW + 800 - $i];
        }

        return $table;
    }

    public function test_fx06_full_table_of_200_blocks_still_counts_a_newcomer(): void
    {
        $table = Limiter::record(self::blocks(200), self::X, self::NOW, 10);

        self::assertCount(200, $table);
        self::assertArrayHasKey(self::X, $table, 'the newcomer\'s try was thrown away');
        self::assertSame(['n' => 1, 'first' => self::NOW, 'last' => self::NOW, 'until' => 0], $table[self::X]);
        self::assertArrayNotHasKey(sprintf('%016x', 199), $table, 'the block that ends soonest made room');
        self::assertArrayHasKey(sprintf('%016x', 198), $table);
        self::assertArrayHasKey(sprintf('%016x', 0), $table);
        self::assertSame(199, Limiter::blocked_count($table, self::NOW));
    }

    public function test_fx06_an_address_without_an_entry_is_never_blocked(): void
    {
        $table = self::blocks(200);
        $table['overflow'] = ['n' => 10, 'first' => self::NOW, 'last' => self::NOW, 'until' => self::NOW + 900];

        self::assertFalse(Limiter::is_blocked($table, self::X, self::NOW));
        self::assertFalse(Limiter::is_blocked($table, 'cccccccccccccccc', self::NOW));
        self::assertTrue(Limiter::is_blocked($table, sprintf('%016x', 0), self::NOW));
    }

    public function test_fx06_an_address_is_blocked_by_its_own_tries_while_the_table_is_full_of_blocks(): void
    {
        $table = self::blocks(200);
        for ($i = 0; $i < 9; $i++) {
            self::assertFalse(Limiter::is_blocked($table, self::X, self::NOW + $i));
            $table = Limiter::record($table, self::X, self::NOW + $i, 10);
            self::assertSame($i + 1, $table[self::X]['n']);
            self::assertCount(200, $table);
        }
        $table = Limiter::record($table, self::X, self::NOW + 9, 10);
        self::assertTrue(Limiter::is_blocked($table, self::X, self::NOW + 9));
        self::assertSame(self::NOW + 9 + 900, $table[self::X]['until']);
        self::assertFalse(Limiter::is_blocked($table, self::Y, self::NOW + 9), 'another address is not affected');
    }

    public function test_fx06_rotating_addresses_are_each_counted_on_their_own(): void
    {
        // 400 addresses taking turns over a full table of blocks. Every try is recorded for the
        // address that made it (the entry just recorded is never the one evicted) and the table
        // stays at CAP. A rotating attacker can push its own older entries out and so reset their
        // counts: accepted (residual), because keys are unguessable and availability comes first.
        $table = self::blocks(200);
        for ($i = 0; $i < 2000; $i++) {
            $id    = sprintf('%016x', 0x100000 + $i % 400);
            $at    = self::NOW + intdiv($i, 100);
            $before = isset($table[$id]) && $table[$id]['until'] === 0 ? $table[$id]['n'] : 0;
            $table = Limiter::record($table, $id, $at, 10);
            self::assertLessThanOrEqual(Limiter::CAP, count($table));
            self::assertArrayHasKey($id, $table, "try $i was not recorded");
            self::assertSame($before + 1, $table[$id]['n'], "try $i counted for its own address");
        }
    }

    public function test_fx06_199_blocks_and_a_newcomer(): void
    {
        $table = Limiter::record(self::blocks(199), self::X, self::NOW, 10);
        self::assertCount(200, $table);
        self::assertSame(1, $table[self::X]['n']);
        self::assertSame(199, Limiter::blocked_count($table, self::NOW));

        // The next newcomer pushes out the only still-counted entry (X), not a block and not itself.
        $table = Limiter::record($table, self::Y, self::NOW + 1, 10);
        self::assertCount(200, $table);
        self::assertArrayHasKey(self::Y, $table);
        self::assertArrayNotHasKey(self::X, $table);
        self::assertSame(199, Limiter::blocked_count($table, self::NOW + 1));
    }

    public function test_fx06_the_just_recorded_entry_is_never_evicted(): void
    {
        // An oversized stored table whose other entries carry a later `last` (clock skew):
        // the address that just tried has the oldest `last` and used to be dropped first.
        $table = [];
        for ($i = 0; $i < 205; $i++) {
            $table[sprintf('%016x', $i)] = ['n' => 1, 'first' => self::NOW - 10, 'last' => self::NOW + 100, 'until' => 0];
        }
        $table[self::X] = ['n' => 3, 'first' => self::NOW - 10, 'last' => self::NOW - 10, 'until' => 0];

        $table = Limiter::record($table, self::X, self::NOW, 10);

        self::assertCount(200, $table);
        self::assertArrayHasKey(self::X, $table);
        self::assertSame(4, $table[self::X]['n']);
    }

    public function test_fx06_eviction_order(): void
    {
        // 150 blocks ending at different times, 60 still-counted entries, all stored (oversized).
        $table = [];
        for ($i = 0; $i < 150; $i++) {
            $table[sprintf('b%015x', $i)] = ['n' => 10, 'first' => self::NOW - 300, 'last' => self::NOW - 300 + $i, 'until' => self::NOW + 600 - $i];
        }
        for ($i = 0; $i < 60; $i++) {
            $table[sprintf('a%015x', $i)] = ['n' => 1, 'first' => self::NOW - 200, 'last' => self::NOW - 200 + $i, 'until' => 0];
        }

        $table = Limiter::record($table, sprintf('a%015x', 0), self::NOW, 10); // the oldest still-counted tries again

        self::assertCount(200, $table);
        self::assertSame(2, $table[sprintf('a%015x', 0)]['n'], 'the entry just recorded is kept');
        for ($i = 1; $i <= 10; $i++) {
            self::assertArrayNotHasKey(sprintf('a%015x', $i), $table, "still-counted a$i (oldest `last`) dropped first");
        }
        self::assertArrayHasKey(sprintf('a%015x', 11), $table);
        self::assertSame(150, Limiter::blocked_count($table, self::NOW), 'no block dropped while a still-counted entry could go');

        // Only blocks besides the newcomer: the blocks that end soonest go, not the oldest `last`.
        $over = [];
        for ($i = 0; $i < 203; $i++) {
            $over[sprintf('b%015x', $i)] = ['n' => 10, 'first' => self::NOW - 300, 'last' => self::NOW - 300 + $i, 'until' => self::NOW + 600 - $i];
        }
        $over = Limiter::record($over, self::X, self::NOW, 10);
        self::assertCount(200, $over);
        self::assertSame(1, $over[self::X]['n']);
        for ($i = 199; $i < 203; $i++) {
            self::assertArrayNotHasKey(sprintf('b%015x', $i), $over, "block b$i (ends soonest) dropped");
        }
        self::assertArrayHasKey(sprintf('b%015x', 0), $over, 'the block with the oldest `last` but the latest end stays');
        self::assertArrayHasKey(sprintf('b%015x', 198), $over);
    }

    // ---------------------------------------------------------------- fx-06 (return 1): IPv6 by /64

    public function test_fx06_ipv6_addresses_in_one_64_share_an_id(): void
    {
        $id = Limiter::address_id('2001:db8:1:2::1', 'pepper');
        self::assertSame($id, Limiter::address_id('2001:db8:1:2:ffff:ffff:ffff:ffff', 'pepper'));
        self::assertSame($id, Limiter::address_id('2001:0db8:0001:0002:0000:0000:0000:0009', 'pepper'), 'another spelling of the same /64');
        self::assertSame($id, Limiter::address_id('2001:DB8:1:2::abcd', 'pepper'));
        self::assertSame(substr(hash('sha256', '2001:db8:1:2::|pepper'), 0, 16), $id);
    }

    public function test_fx06_different_ipv6_64s_differ(): void
    {
        self::assertNotSame(Limiter::address_id('2001:db8:1:2::1', ''), Limiter::address_id('2001:db8:1:3::1', ''));
        self::assertNotSame(Limiter::address_id('2001:db8:1:2::1', ''), Limiter::address_id('2001:db9:1:2::1', ''));
        self::assertNotSame(Limiter::address_id('::1', ''), Limiter::address_id('::1', 'x'));
    }

    public function test_fx06_ipv4_mapped_ipv6_is_the_ipv4_address(): void
    {
        self::assertSame(Limiter::address_id('203.0.113.9', 'pepper'), Limiter::address_id('::ffff:203.0.113.9', 'pepper'));
        self::assertSame(Limiter::address_id('203.0.113.9', 'pepper'), Limiter::address_id('::FFFF:cb00:7109', 'pepper'));
        self::assertNotSame(Limiter::address_id('203.0.113.9', ''), Limiter::address_id('::ffff:203.0.113.8', ''), 'IPv4 is never grouped');
    }

    public function test_fx06_non_addresses_are_hashed_as_given(): void
    {
        foreach (['unknown', '', 'fe80::1%eth0', 'not an ip', '1.2.3'] as $value) {
            self::assertSame(substr(hash('sha256', $value . '|s'), 0, 16), Limiter::address_id($value, 's'), $value);
        }
    }

    public function test_ac48_a_non_array_table_counts_as_empty(): void
    {
        foreach ([null, 'x', 5, true, 1.5] as $junk) {
            self::assertFalse(Limiter::is_blocked($junk, self::X, self::NOW));
            self::assertSame(0, Limiter::blocked_count($junk, self::NOW));
            self::assertSame(0, Limiter::last_try($junk));
            self::assertSame(1, Limiter::record($junk, self::X, self::NOW, 10)[self::X]['n']);
        }
    }

    public function test_ac48_broken_entries_never_throw_and_are_dropped(): void
    {
        $table = [
            self::X => 'junk',
            self::Y => ['n' => 'a', 'first' => null, 'last' => [], 'until' => 'soon'],
            'cccccccccccccccc' => ['until' => self::NOW + 10],
            'eeeeeeeeeeeeeeee' => ['n' => -1, 'first' => self::NOW, 'last' => self::NOW, 'until' => 0],
        ];
        self::assertFalse(Limiter::is_blocked($table, self::X, self::NOW));
        self::assertFalse(Limiter::is_blocked($table, self::Y, self::NOW));
        self::assertTrue(Limiter::is_blocked($table, 'cccccccccccccccc', self::NOW));
        self::assertSame(1, Limiter::blocked_count($table, self::NOW));

        $written = Limiter::record($table, 'dddddddddddddddd', self::NOW, 10);
        self::assertArrayNotHasKey(self::X, $written);
        self::assertArrayNotHasKey(self::Y, $written);
        self::assertArrayNotHasKey('eeeeeeeeeeeeeeee', $written);
        self::assertArrayHasKey('cccccccccccccccc', $written);
        self::assertSame(['n' => 1, 'first' => self::NOW, 'last' => self::NOW, 'until' => 0], $written['dddddddddddddddd']);
    }

    public function test_ac48_an_all_digit_id_survives_php_integer_keys(): void
    {
        // PHP stores "1234567890123456" as the integer key 1234567890123456.
        $id    = '1234567890123456';
        $table = self::tries([], $id, 10, self::NOW);
        self::assertTrue(Limiter::is_blocked($table, $id, self::NOW + 10));
        $table = Limiter::record($table, self::Y, self::NOW + 10, 10);
        self::assertTrue(Limiter::is_blocked($table, $id, self::NOW + 10));
        self::assertSame(1, Limiter::blocked_count($table, self::NOW + 10));
    }

    public function test_ac48_a_broken_entry_for_the_trying_address_restarts_cleanly(): void
    {
        $table = Limiter::record([self::X => 'junk'], self::X, self::NOW, 10);
        self::assertSame(['n' => 1, 'first' => self::NOW, 'last' => self::NOW, 'until' => 0], $table[self::X]);
    }

    /** 201 live, still-counted stored entries (one more than CAP); X has the oldest `last`. */
    private static function oversized_live(): array
    {
        $table = [];
        for ($i = 0; $i < 200; $i++) {
            $table[sprintf('%016x', $i)] = ['n' => 2, 'first' => self::NOW - 10, 'last' => self::NOW - 5 + $i % 5, 'until' => 0];
        }
        $table[self::X] = ['n' => 3, 'first' => self::NOW - 10, 'last' => self::NOW - 9, 'until' => 0];

        return $table;
    }

    public function test_fx06_max_zero_still_trims_an_oversized_table_and_counts_nothing(): void
    {
        $stored = self::oversized_live();
        self::assertCount(201, $stored);

        $table = Limiter::record($stored, self::Y, self::NOW, 0);
        self::assertCount(200, $table);
        self::assertArrayNotHasKey(self::Y, $table, 'max 0 records nothing');
        foreach ($table as $id => $entry) {
            self::assertSame($stored[$id], $entry, "entry $id unchanged (no increment)");
        }

        $table = Limiter::record($stored, self::X, self::NOW, 0);
        self::assertCount(200, $table);
        self::assertArrayNotHasKey(self::X, $table, 'with nothing recorded, the oldest `last` still goes first');
        foreach ($table as $id => $entry) {
            self::assertSame($stored[$id], $entry, "entry $id unchanged (no increment)");
        }
    }

    public function test_fx06_a_try_while_blocked_still_trims_an_oversized_table(): void
    {
        $stored = self::oversized_live();
        $stored[self::X] = ['n' => 10, 'first' => self::NOW - 10, 'last' => self::NOW - 9, 'until' => self::NOW + 500];

        $table = Limiter::record($stored, self::X, self::NOW, 10);
        self::assertCount(200, $table);
        self::assertSame($stored[self::X], $table[self::X], 'the blocked entry is kept and unchanged');
        self::assertTrue(Limiter::is_blocked($table, self::X, self::NOW));
        foreach ($table as $id => $entry) {
            self::assertSame($stored[$id], $entry, "entry $id unchanged");
        }
    }

    public function test_ac48_record_with_max_zero_records_nothing(): void
    {
        self::assertSame([], Limiter::record([], self::X, self::NOW, 0));
        $table = self::tries([], self::Y, 2, self::NOW);
        self::assertSame($table, Limiter::record($table, self::X, self::NOW + 1, 0));
    }

    // ---------------------------------------------------------------- AC-49

    public static function clamp_provider(): array
    {
        return [
            'not defined'   => [null, 10],
            'zero stays'    => [0, 0],
            'in range'      => [25, 25],
            'low clamp'     => [1, 3],
            'two clamp'     => [2, 3],
            'three'         => [3, 3],
            'hundred'       => [100, 100],
            'high clamp'    => [101, 100],
            'huge'          => [PHP_INT_MAX, 100],
            'negative'      => [-4, 3],
            'string number' => ['5', 10],
            'string zero'   => ['0', 10],
            'float'         => [5.0, 10],
            'bool true'     => [true, 10],
            'bool false'    => [false, 10],
            'array'         => [[5], 10],
            'text'          => ['five', 10],
        ];
    }

    #[DataProvider('clamp_provider')]
    public function test_ac49_clamp_max(mixed $value, int $expected): void
    {
        self::assertSame($expected, Limiter::clamp_max($value));
    }

    // ---------------------------------------------------------------- AC-51 / AC-53 helpers

    public function test_ac51_blocked_count_and_last_try(): void
    {
        self::assertSame(0, Limiter::blocked_count([], self::NOW));
        self::assertSame(0, Limiter::last_try([]));

        $table = self::tries([], self::X, 10, self::NOW);
        $table = self::tries($table, self::Y, 3, self::NOW + 50);
        self::assertSame(1, Limiter::blocked_count($table, self::NOW + 60));
        self::assertSame(0, Limiter::blocked_count($table, $table[self::X]['until']));
        self::assertSame(self::NOW + 52, Limiter::last_try($table));
    }
}
