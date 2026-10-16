<?php
/**
 * The wrong-try limit (seam S-7): pure bookkeeping over a small table of
 * hashed addresses. No WordPress calls live here; hooks.php feeds it plain
 * values and stores the table it returns.
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

final class Limiter
{
    public const DEFAULT_MAX = 10;
    public const MIN_MAX     = 3;
    public const MAX_MAX     = 100;

    /** Seconds after the first wrong try in which the tries are counted together. */
    public const WINDOW = 600;

    /** Seconds an address stays blocked, counted from the try that reached the limit. */
    public const BLOCK = 900;

    /** The table never holds more entries than this. */
    public const CAP = 200;

    /** NOVA_GATE_MAX_TRIES: 0 turns the limit off, other integers are clamped, anything else is the default. */
    public static function clamp_max(mixed $value): int
    {
        if (!is_int($value)) {
            return self::DEFAULT_MAX;
        }
        if ($value === 0) {
            return 0;
        }

        return max(self::MIN_MAX, min(self::MAX_MAX, $value));
    }

    /**
     * The table key for an address: 16 hex characters, never the address itself.
     * An IPv6 address counts as its /64 network (one end user usually holds a whole /64);
     * an IPv4-mapped IPv6 address counts as its IPv4 address. Anything else is hashed as given.
     */
    public static function address_id(string $address, string $salt): string
    {
        return substr(hash('sha256', self::network($address) . '|' . $salt), 0, 16);
    }

    /** True while $id is blocked by its own entry. An address with no entry is never blocked. */
    public static function is_blocked(mixed $table, string $id, int $now): bool
    {
        $entry = self::entry($table, $id);

        return $entry !== null && $entry['until'] > $now;
    }

    /**
     * The table after one wrong try by $id. A blocked address is left as it is
     * (tries during a block are not counted). Prunes stale entries and keeps the
     * table under CAP. Never throws.
     *
     * Every try is counted for the address that made it, and that entry is never the one
     * dropped to keep CAP (see cap()).
     */
    public static function record(mixed $table, string $id, int $now, int $max): array
    {
        $clean = self::prune($table, $now);
        if ($max < 1) {
            return self::cap($clean, $now, null); // Nothing recorded, but an oversized stored table is still trimmed.
        }

        $entry = self::entry($clean, $id);
        if ($entry !== null && $entry['until'] > $now) {
            return self::cap($clean, $now, $id); // Not counted; the blocked entry is kept unchanged.
        }

        $in_window = $entry !== null && $entry['until'] === 0 && $now <= $entry['first'] + self::WINDOW;
        if ($in_window) {
            $entry['n']++;
            $entry['last'] = $now;
        } else {
            $entry = ['n' => 1, 'first' => $now, 'last' => $now, 'until' => 0];
        }
        if ($entry['n'] >= $max) {
            $entry['until'] = $now + self::BLOCK;
        }
        $clean[$id] = $entry;

        return self::cap($clean, $now, $id);
    }

    public static function blocked_count(mixed $table, int $now): int
    {
        $count = 0;
        foreach (self::entries($table) as $entry) {
            if ($entry['until'] > $now) {
                $count++;
            }
        }

        return $count;
    }

    /** Unix time of the most recent wrong try in the table, 0 when there is none. */
    public static function last_try(mixed $table): int
    {
        $last = 0;
        foreach (self::entries($table) as $entry) {
            $last = max($last, $entry['last']);
        }

        return $last;
    }

    // ------------------------------------------------------------------ internals

    /** Entries that are still blocked or still inside their window. */
    private static function prune(mixed $table, int $now): array
    {
        $kept = [];
        foreach (self::entries($table) as $id => $entry) {
            if ($entry['until'] > $now || ($entry['until'] === 0 && $now <= $entry['first'] + self::WINDOW)) {
                $kept[$id] = $entry;
            }
        }

        return $kept;
    }

    /**
     * Keep the table at CAP entries, never dropping $keep (the entry just recorded, or the
     * blocked entry that just tried; null = keep none), so a
     * newcomer is always counted. First go the still-counted entries with the oldest `last`;
     * only when every other entry is an active block, the blocks that end soonest (that
     * attacker's block ends early: accepted, so that no address is ever refused for want of room).
     */
    private static function cap(array $table, int $now, ?string $keep): array
    {
        if (count($table) <= self::CAP) {
            return $table;
        }
        $order = [];
        foreach (array_keys($table) as $id) {
            $id = (string) $id;
            if ($id !== $keep) {
                $order[] = $id;
            }
        }
        usort($order, static function ($a, $b) use ($table, $now): int {
            $blocked_a = $table[$a]['until'] > $now;
            $blocked_b = $table[$b]['until'] > $now;
            if ($blocked_a !== $blocked_b) {
                return $blocked_a ? 1 : -1;
            }
            if ($blocked_a) {
                return [$table[$a]['until'], $table[$a]['last']] <=> [$table[$b]['until'], $table[$b]['last']];
            }

            return $table[$a]['last'] <=> $table[$b]['last'];
        });
        foreach ($order as $id) {
            if (count($table) <= self::CAP) {
                break;
            }
            unset($table[$id]);
        }

        return $table;
    }

    /** The text that is hashed for an address: an IPv6 address becomes its /64 network. */
    private static function network(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }
        $packed = inet_pton($address);
        if (!is_string($packed) || strlen($packed) !== 16) {
            return $address;
        }
        if (strncmp($packed, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            return (string) inet_ntop(substr($packed, 12)); // IPv4-mapped: the IPv4 address itself.
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
    }

    /**
     * The well-formed entries of a table, keyed by their 16-hex id. Anything that
     * is not an array, or any entry that is malformed, is left out.
     *
     * @return array<string, array{n:int, first:int, last:int, until:int}>
     */
    private static function entries(mixed $table): array
    {
        if (!is_array($table)) {
            return [];
        }
        $entries = [];
        foreach ($table as $id => $entry) {
            $normal = self::normalize($entry);
            if ($normal !== null) {
                // PHP turns an all-digit id into an integer key; keep working with it as text.
                $entries[(string) $id] = $normal;
            }
        }

        return $entries;
    }

    /** @return array{n:int, first:int, last:int, until:int}|null */
    private static function entry(mixed $table, string $id): ?array
    {
        if (!is_array($table) || !array_key_exists($id, $table)) {
            return null;
        }

        return self::normalize($table[$id]);
    }

    /** @return array{n:int, first:int, last:int, until:int}|null */
    private static function normalize(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }
        $normal = [];
        foreach (['n', 'first', 'last', 'until'] as $field) {
            $value = $entry[$field] ?? 0;
            if (!is_int($value)) {
                $value = is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : null;
            }
            if ($value === null || $value < 0) {
                return null;
            }
            $normal[$field] = $value;
        }

        return $normal;
    }
}
