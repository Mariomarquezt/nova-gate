<?php
/**
 * The pure decision core of Nova Gate (seam S-2).
 *
 * No WordPress calls live here. Everything that needs WordPress is in
 * hooks.php and feeds plain values into this class.
 *
 * @package NovaGate
 */

declare(strict_types=1);

namespace NovaGate;

final class Gate
{
    public const INACTIVE    = 'inactive';
    public const LOCKED      = 'locked';
    public const OPEN_KEY    = 'open_key';
    public const OPEN_MANUAL = 'open_manual';
    public const OPEN_LINK   = 'open_link';

    public const DEFAULT_MINUTES = 30;
    public const MIN_MINUTES     = 5;
    public const MAX_MINUTES     = 240;

    public const MAX_KEYS = 20;

    /** Routes that Novamira's own one-time links call. Case-sensitive, no decoding. */
    private const LINK_ROUTE_PATTERN = '#^/novamira/v1/(?:upload|admin-access|admin-access/[A-Za-z0-9_-]+)/?$#D';

    private const KEY_PATTERN    = '/^[A-Za-z0-9]{32,128}$/D';
    private const KEY_ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/D';

    /**
     * Decide the gate state for one request.
     *
     * $ctx keys: key_hash ?string, key_hashes ?array, header ?string, route ?string,
     * open_until int, now int, blocked bool.
     * The gate is inactive only when key_hash and key_hashes are both null.
     * Any key that is missing or has the wrong type counts as "not provided",
     * so bad input can only make the result stricter.
     */
    public static function decide(array $ctx): string
    {
        $hashes = self::configured_hashes($ctx['key_hash'] ?? null, $ctx['key_hashes'] ?? null);
        if ($hashes === null) {
            return self::INACTIVE;
        }

        $header = $ctx['header'] ?? null;
        // Not blocked only when the flag is false or absent: any other value is the stricter reading.
        $blocked = ($ctx['blocked'] ?? false) !== false;
        if (!$blocked && self::verify_any(is_string($header) ? $header : null, $hashes)) {
            return self::OPEN_KEY;
        }

        $open_until = self::to_int($ctx['open_until'] ?? 0);
        $now        = self::to_int($ctx['now'] ?? 0);
        if ($open_until > $now) {
            return self::OPEN_MANUAL;
        }

        $route = $ctx['route'] ?? null;
        if (self::is_link_route(is_string($route) ? $route : null)) {
            return self::OPEN_LINK;
        }

        return self::LOCKED;
    }

    public static function is_open(string $state): bool
    {
        return $state === self::OPEN_KEY
            || $state === self::OPEN_MANUAL
            || $state === self::OPEN_LINK;
    }

    /**
     * True only when the header is a well-formed key whose SHA-256 digest equals $hash.
     */
    public static function verify_key(?string $header, ?string $hash): bool
    {
        return self::verify_any($header, [$hash]);
    }

    /**
     * True when the header is a well-formed key whose digest equals ANY valid hash
     * in $hashes. Every entry is compared, with no early exit, so the time of the
     * check does not tell which key matched. Non-string or invalid entries are skipped.
     */
    public static function verify_any(?string $header, array $hashes): bool
    {
        if ($header === null || preg_match(self::KEY_PATTERN, $header) !== 1) {
            return false;
        }
        $digest  = self::hash_key($header);
        $matched = false;
        foreach ($hashes as $hash) {
            if (!is_string($hash) || !self::is_valid_hash($hash)) {
                continue;
            }
            if (hash_equals($hash, $digest)) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * One hash, "a,b c" text, or an array → the list of strings found: trimmed,
     * empty pieces dropped, not validated. Anything else → [].
     */
    public static function parse_hash_list(mixed $value): array
    {
        if (is_string($value)) {
            $pieces = preg_split('/[,\s]+/', $value) ?: [];
        } elseif (is_array($value)) {
            $pieces = $value;
        } else {
            return [];
        }

        $list = [];
        foreach ($pieces as $piece) {
            if (!is_string($piece)) {
                continue;
            }
            $piece = trim($piece);
            if ($piece !== '') {
                $list[] = $piece;
            }
        }

        return $list;
    }

    /** Key id: [a-z0-9][a-z0-9-]{0,39} (seam S-1). */
    public static function is_valid_key_id(mixed $id): bool
    {
        return is_string($id) && preg_match(self::KEY_ID_PATTERN, $id) === 1;
    }

    /**
     * The REST route WordPress will dispatch, or null when this is not a REST
     * request or the answer is ambiguous. Stricter than WordPress, never looser.
     */
    public static function resolve_route(array $server, array $get, array $post, string $home_path, string $rest_prefix): ?string
    {
        $uri  = $server['REQUEST_URI'] ?? null;
        $path = is_string($uri) && $uri !== '' ? explode('?', $uri, 2)[0] : null;

        $prefix = '/' . trim($rest_prefix, '/');
        $home   = trim($home_path, '/');
        $home   = $home === '' ? '' : '/' . $home;

        // 1. A rest_route query variable wins over the URL path, as in WP::parse_request().
        $has_get  = array_key_exists('rest_route', $get);
        $has_post = array_key_exists('rest_route', $post);
        if ($has_get || $has_post) {
            $from_get  = $has_get ? $get['rest_route'] : null;
            $from_post = $has_post ? $post['rest_route'] : null;
            if (($has_get && !is_string($from_get)) || ($has_post && !is_string($from_post))) {
                return null;
            }
            if ($has_get && $has_post && $from_get !== $from_post) {
                return null;
            }
            // WordPress reads rest_route only in WP::parse_request(), on a request that runs
            // index.php: never for admin-ajax.php, wp-admin, xmlrpc.php or any other script.
            if ($path === null || !self::is_front_controller_path($path, $home, $prefix)) {
                return null;
            }
            $route = $has_post ? $from_post : $from_get;

            return $route === '' ? null : $route;
        }

        // 2. Otherwise derive the route from the request path.
        if ($path === null || $prefix === '/') {
            return null;
        }

        $route = self::route_after_prefix($path, $home . $prefix)
            ?? self::route_after_prefix($path, $home . '/index.php' . $prefix);
        if ($route === null) {
            return null;
        }

        // 3. PATH_INFO, when the server sets it, must tell the same story. Servers differ in
        // whether it carries the home path (and index.php), so each of those forms is accepted.
        $path_info = $server['PATH_INFO'] ?? '';
        if (!is_string($path_info)) {
            return null;
        }
        if ($path_info !== '') {
            $info   = explode('?', $path_info, 2)[0];
            $agrees = false;
            foreach ([$prefix, $home . $prefix, $home . '/index.php' . $prefix] as $info_prefix) {
                if (self::route_after_prefix($info, $info_prefix) === $route) {
                    $agrees = true;
                }
            }
            if (!$agrees) {
                return null;
            }
        }

        return $route;
    }

    public static function is_link_route(?string $route): bool
    {
        return $route !== null && preg_match(self::LINK_ROUTE_PATTERN, $route) === 1;
    }

    /** 64 lowercase hex characters from 32 random bytes. */
    public static function generate_key(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash_key(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function is_valid_hash(?string $hash): bool
    {
        return $hash !== null && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    }

    /** Timer minutes: integer 5–240, default 30 for anything non-numeric. */
    public static function clamp_minutes(mixed $value): int
    {
        if (is_bool($value) || $value === null || $value === '') {
            return self::DEFAULT_MINUTES;
        }
        if (is_int($value)) {
            $minutes = $value;
        } elseif (is_float($value)) {
            $minutes = (int) $value;
        } elseif (is_string($value) && is_numeric($value)) {
            $minutes = (int) (float) $value;
        } else {
            return self::DEFAULT_MINUTES;
        }

        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));
    }

    /**
     * The list of hashes the gate is configured with, or null when nothing is
     * configured (gate inactive). A value that is present but has the wrong type
     * is a broken configuration: it keeps the gate active and can match no key.
     *
     * @param mixed $single The v0.1.0 key_hash context value.
     * @param mixed $list   The key_hashes context value.
     */
    private static function configured_hashes(mixed $single, mixed $list): ?array
    {
        if ($single === null && $list === null) {
            return null;
        }

        $hashes = [];
        if (is_array($list)) {
            foreach ($list as $hash) {
                if (is_string($hash)) {
                    $hashes[] = $hash;
                }
            }
        }
        if (is_string($single)) {
            $hashes[] = $single;
        }

        return $hashes;
    }

    /**
     * True when a request with this path runs WordPress's front controller (index.php), so
     * WP::parse_request() reads a rest_route query variable: the home root, home/index.php,
     * or a path under the REST prefix (served by index.php through the rewrite rules).
     * Any dot segment, percent sign or backslash makes it false, because a web server may
     * resolve those to another script (/wp-json/../wp-admin/admin-ajax.php).
     */
    private static function is_front_controller_path(string $path, string $home, string $prefix): bool
    {
        if ($path === '' || strpbrk($path, '%\\') !== false) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        foreach ([$home, $home . '/index.php'] as $base) {
            if ($path === $base . '/' || ($base !== '' && $path === $base)) {
                return true;
            }
            if ($prefix !== '/' && self::route_after_prefix($path, $base . $prefix) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * "/{prefix}" or "/{prefix}/x/y" → "/x/y" ("/" for the bare prefix); anything else → null.
     */
    private static function route_after_prefix(string $path, string $prefix): ?string
    {
        if ($path === $prefix) {
            return '/';
        }
        if (strncmp($path, $prefix . '/', strlen($prefix) + 1) !== 0) {
            return null;
        }
        $rest = substr($path, strlen($prefix) + 1);

        return '/' . $rest;
    }

    private static function to_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }
}
