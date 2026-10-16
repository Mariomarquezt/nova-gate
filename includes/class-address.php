<?php
/**
 * Pure request address and key-header selection. Addresses never leave the
 * request; hooks.php stores only Limiter::address_id().
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

final class Address
{
    /** @param mixed $header Server key configured by NOVA_GATE_IP_HEADER.
     *  @param mixed $trusted Comma-separated proxy IPs / CIDRs, or null.
     */
    public static function resolve(array $server, $header, $trusted): string
    {
        $peer = self::valid_ip($server['REMOTE_ADDR'] ?? null);
        if ($peer === null) {
            return 'unknown';
        }
        if (!is_string($header) || !self::is_trusted($peer, $trusted)) {
            return $peer;
        }
        $forwarded = $server[$header] ?? null;
        if (!is_string($forwarded)) {
            return $peer;
        }

        // Walk only the trusted suffix. Text left of the first untrusted
        // client may have been supplied by that client and is never used.
        $client = $peer;
        foreach (array_reverse(explode(',', $forwarded)) as $part) {
            $ip = self::forwarded_ip($part);
            if ($ip === null) {
                return $peer; // Junk inside the trusted suffix: fail closed.
            }
            if (!self::is_trusted($ip, $trusted)) {
                return $ip;
            }
            $client = $ip;
        }

        return $client; // All hops were valid and trusted: use the leftmost.
    }

    /** A forwarded entry may contain an IPv4 port or a bracketed IPv6 port. */
    private static function forwarded_ip(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\[([^\]]+)\]:([0-9]{1,5})$/D', $value, $match) === 1) {
            return (int) $match[2] <= 65535 && filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? $match[1] : null;
        }
        if (preg_match('/^([0-9.]+):([0-9]{1,5})$/D', $value, $match) === 1) {
            return (int) $match[2] <= 65535 ? self::valid_ip($match[1]) : null;
        }

        return self::valid_ip($value);
    }

    /** Whether configuration contains at least one usable IP or CIDR. */
    public static function has_valid_ranges($ranges): bool
    {
        if (!is_string($ranges)) {
            return false;
        }
        foreach (explode(',', $ranges) as $range) {
            if (self::is_trusted(trim(explode('/', $range)[0]), $range)) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $ranges */
    public static function is_trusted(string $ip, $ranges): bool
    {
        if (self::valid_ip($ip) === null || !is_string($ranges)) {
            return false;
        }
        $address = inet_pton(trim($ip));
        foreach (explode(',', $ranges) as $range) {
            $parts = explode('/', trim($range));
            if (count($parts) > 2 || self::valid_ip($parts[0]) === null) {
                continue;
            }
            $network = inet_pton(trim($parts[0]));
            // IPv4-mapped IPv6 peers and entries also match IPv4 ranges.
            // Keep the original bytes when matching an explicit IPv6 range.
            $candidate = strlen($network) === 4 && substr($address, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
                ? substr($address, 12) : $address;
            if (strlen($candidate) !== strlen($network)) {
                continue;
            }
            $bits = strlen($network) * 8;
            if (isset($parts[1])) {
                if (preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $parts[1]) !== 1 || (int) $parts[1] > $bits) {
                    continue;
                }
                $bits = (int) $parts[1];
            }
            $bytes = intdiv($bits, 8);
            $rest  = $bits % 8;
            if (substr($candidate, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($candidate[$bytes]) ^ ord($network[$bytes])) & (0xff << (8 - $rest))) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Direct header wins, then the nearest Apache internal redirect. */
    public static function key_header(array $server): ?string
    {
        $selected = null;
        foreach ($server as $name => $value) {
            if (is_string($name) && preg_match('/^(?:REDIRECT_)*HTTP_X_NOVA_GATE_KEY$/D', $name) === 1
                && ($selected === null || strlen($name) < strlen($selected))) {
                $selected = $name;
            }
        }

        return $selected !== null && is_string($server[$selected]) ? $server[$selected] : null;
    }

    /** @param mixed $value */
    public static function valid_ip($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
    }
}
