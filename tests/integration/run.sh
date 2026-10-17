#!/usr/bin/env bash
#
# Nova Gate integration tests: real WordPress in wp-env + the frozen Novamira test double.
#
# Needs: Docker, Node (npx), curl, php (local CLI, for JSON parsing), sha256sum.
# Prints one PASS/FAIL line per check and exits 1 on any FAIL.
# It does not stop wp-env; run `npx @wordpress/env stop` yourself when done.
#
# Wrong-try limit and this suite: .wp-env.json defines NOVA_GATE_IP_HEADER=HTTP_X_TEST_IP, so a
# request can carry its own test address in the X-Test-IP header. Every section that sends wrong
# keys uses its own address (10.x.y.z), and the tries table is cleared between sections, so the
# limit never blocks a later right-key check by accident. The REMOTE_ADDR path (no test header)
# is proven on its own in the AC-49 section.
#
set -u
cd "$(dirname "$0")/../.." || exit 1

BASE="http://localhost:${WP_ENV_PORT:-8931}"
ADMIN_USER="admin"
ADMIN_PASS="password"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

FAIL=0
PASSED=0
FAILED=0
pass() { echo "PASS: $1"; PASSED=$((PASSED + 1)); }
fail() { echo "FAIL: $1 -- $2"; FAILED=$((FAILED + 1)); FAIL=1; }
check() { # label expected actual
    if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected [$2] got [$3]"; fi
}
check_contains() { # label needle haystack-file
    if grep -qF -- "$2" "$3"; then pass "$1"; else fail "$1" "missing [$2]"; fi
}
check_not_contains() { # label needle haystack-file
    if grep -qF -- "$2" "$3"; then fail "$1" "found [$2]"; else pass "$1"; fi
}
check_between() { # label low high actual
    if [ "$4" -ge "$2" ] 2>/dev/null && [ "$4" -le "$3" ] 2>/dev/null; then pass "$1"; else fail "$1" "expected $2..$3 got [$4]"; fi
}

# A "real HTTP answer" is 1xx-4xx. 000 is a curl/transport failure and 5xx is a
# server fault; neither proves that the thing a negative check looks for is
# absent (fx-17). Negative HTTP checks must see a real answer first.
http_answered() { case "$1" in [1-4][0-9][0-9]) return 0 ;; *) return 1 ;; esac }
check_http_not200() { # label code  (real answer, and not 200)
    if ! http_answered "$2"; then fail "$1" "no real HTTP answer (code $2)"
    elif [ "$2" = "200" ]; then fail "$1" "got 200"
    else pass "$1"; fi
}
check_http_not_contains() { # label needle body-file code  (real answer, then absence)
    if ! http_answered "$4"; then fail "$1" "no real HTTP answer (code $4)"; return; fi
    check_not_contains "$1" "$2" "$3"
}
gate_state() { # request-name -> the X-Nova-Gate-State header the helper put on that response
    header_value "$TMP/$1.head" X-Nova-Gate-State
}
check_gate_not_open() { # label request-name  (the gate reported a non-open state for that request)
    local s
    s="$(gate_state "$2")"
    case "$s" in
        locked) pass "$1" ;;
        *) fail "$1" "gate state [$s] is open or the state header is missing" ;;
    esac
}

wpq() { npx @wordpress/env run cli wp "$@" 2>/dev/null; }
wpx() { npx @wordpress/env run cli wp "$@" >"$TMP/wpx.out" 2>"$TMP/wpx.err"; echo $?; } # exit code only; output in $TMP/wpx.*
sql() { wpq db query "$1" --skip-column-names; }
db_enabled() { sql "SELECT option_value FROM wp_options WHERE option_name='novamira_ai_abilities_enabled'"; }
db_domain_rows() { sql "SELECT COUNT(*) FROM wp_options WHERE option_name='novamira_ai_abilities_domain'"; }
db_set_enabled_raw() { # value  (writes the real database value, no WordPress API)
    sql "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('novamira_ai_abilities_enabled', '$1', 'yes') ON DUPLICATE KEY UPDATE option_value='$1'"
}
opt() { wpq option get "$1" 2>/dev/null; }
opt_exists() { # name -> yes|no ; any other CLI failure -> cli-error (fx-18: a FAIL, never a masked "no")
    local out rc
    out="$(npx @wordpress/env run cli wp option get "$1" 2>&1)"; rc=$?
    if [ "$rc" -eq 0 ]; then echo yes; return; fi
    case "$out" in
        *"Does it exist?"*) echo no ;;
        *) echo "cli-error" ;;
    esac
}
opt_autoload() { sql "SELECT autoload FROM wp_options WHERE option_name='$1'"; }
keys_json() { wpq option get nova_gate_keys --format=json 2>/dev/null; }
key_field() { # id field  -> value from nova_gate_keys
    keys_json > "$TMP/keys.json"
    php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo isset($d[$argv[2]][$argv[3]]) ? (string) $d[$argv[2]][$argv[3]] : "<absent>";' "$TMP/keys.json" "$1" "$2"
}
key_ids() { keys_json > "$TMP/keys.json"; php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? implode(",", array_keys($d)) : "<none>";' "$TMP/keys.json"; }
tries_json() { wpq option get nova_gate_tries --format=json 2>/dev/null; }
tries_sum_n() { wpq eval 'echo array_sum(array_map(static fn($e) => (int) ($e["n"] ?? 0), (array) get_option("nova_gate_tries", [])));' | tr -d '[:space:]'; }
tries_count() { wpq eval 'echo count((array) get_option("nova_gate_tries", []));' | tr -d '[:space:]'; }
blocked_count() { wpq eval 'echo NovaGate\blocked_now();' | tr -d '[:space:]'; }
clear_tries() { wpq option delete nova_gate_tries >/dev/null 2>&1; }

# req NAME METHOD URL [curl args...]  -> sets CODE, writes $TMP/NAME.body and $TMP/NAME.head
req() {
    local name="$1" method="$2" url="$3"
    shift 3
    CODE="$(curl -s -o "$TMP/$name.body" -D "$TMP/$name.head" -w '%{http_code}' -X "$method" "$url" "$@")"
}
json_get() { # file key
    php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $v = is_array($d) && array_key_exists($argv[2], $d) ? $d[$argv[2]] : "<absent>"; echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v) : (string) $v);' "$1" "$2"
}
json_keys() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? implode(",", array_keys($d)) : "<not json>";' "$1"; }
json_count() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? count($d) : "<not json>";' "$1"; }
header_value() { # file header-name
    grep -i "^$2:" "$1" | tail -1 | sed 's/^[^:]*: *//' | tr -d '\r'
}

login() { # user pass jar
    : > "$3"
    curl -s -c "$3" -b "$3" -o /dev/null "$BASE/wp-login.php"
    curl -s -c "$3" -b "$3" -o /dev/null -X POST "$BASE/wp-login.php" \
        --data-urlencode "log=$1" --data-urlencode "pwd=$2" --data-urlencode "wp-submit=Log In" \
        --data-urlencode "redirect_to=$BASE/wp-admin/" --data-urlencode "testcookie=1"
}
settings_page() { # jar -> file ; sets PAGE_CODE
    PAGE_CODE="$(curl -s -b "$1" -o "$2" -w '%{http_code}' "$BASE/wp-admin/options-general.php?page=nova-gate")"
}
nonce_for() { # action file
    sed -n "s/.*name=\"nova_gate_action\" value=\"$1\"><input type=\"hidden\" name=\"nova_gate_nonce\" value=\"\([A-Za-z0-9]*\)\".*/\1/p" "$2" | head -1
}
post_action() { # name jar action nonce [extra --data-urlencode args]
    local name="$1" jar="$2" action="$3" nonce="$4"
    shift 4
    req "$name" POST "$BASE/wp-admin/options-general.php?page=nova-gate" -b "$jar" \
        --data-urlencode "nova_gate_action=$action" --data-urlencode "nova_gate_nonce=$nonce" "$@"
}
add_key_ui() { # name jar label  -> KEY (extracted) ; CODE
    settings_page "$2" "$TMP/$1.page.html"
    local nonce
    nonce="$(nonce_for add "$TMP/$1.page.html")"
    post_action "$1" "$2" add "$nonce" --data-urlencode "nova_gate_label=$3"
    KEY_OUT="$(extract_key "$TMP/$1.body")"
}
remove_key_ui() { # name jar id ; CODE
    settings_page "$2" "$TMP/$1.page.html"
    local nonce
    nonce="$(nonce_for remove "$TMP/$1.page.html")"
    post_action "$1" "$2" remove "$nonce" --data-urlencode "nova_gate_key_id=$3"
}
mcp() { # name [curl args]
    local name="$1"
    shift
    req "$name" POST "$BASE/wp-json/mcp/novamira" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$@"
}
status() { # name [curl args]
    local name="$1"
    shift
    req "$name" GET "$BASE/wp-json/nova-gate/v1/status" "$@"
}
extract_key() { grep -o 'id="nova-gate-new-key">[a-f0-9]\{64\}<' "$1" | grep -o '[a-f0-9]\{64\}' | head -1; }
sha256() { printf '%s' "$1" | sha256sum | cut -d' ' -f1; }
wrong_key() { printf '%s' "$1" | rev; }
# Ten wrong keys from one test address: name-prefix address key
ten_wrong() {
    local i
    for i in 1 2 3 4 5 6 7 8 9 10; do
        mcp "$1$i" -H "X-Test-IP: $2" -H "X-Nova-Gate-Key: $(wrong_key "$3")"
    done
}

# ------------------------------------------------------------------------------
# Test helper mu-plugin. Written into the container at the start, removed at the end.
# Not part of the plugin. Gives the tests: an app-password-authenticated REST route that
# turns Novamira's switch on, a nonce for the double's toggle, a cookie "open" endpoint,
# a fake Novamira admin page, a query counter, a CLI-class probe, and a late-load switch (AC-19).
# ------------------------------------------------------------------------------
read -r -d '' HELPER_PHP <<'PHP'
<?php
/* Nova Gate integration test helper. Written by tests/integration/run.sh. Not part of the plugin. */
declare(strict_types=1);

if (get_option('nova_gate_test_late') === '1') {
    add_filter('option_active_plugins', static function ($plugins) {
        return is_array($plugins) ? array_values(array_diff($plugins, ['novamira/novamira.php'])) : $plugins;
    });
    require_once WP_PLUGIN_DIR . '/novamira/novamira.php';
}

add_action('rest_api_init', static function (): void {
    register_rest_route('nova-gate-test/v1', '/open', [
        'methods'  => 'POST',
        'callback' => static function () {
            $updated = update_option('novamira_ai_abilities_enabled', '1');
            return [
                'updated'      => $updated,
                'user'         => get_current_user_id(),
                'app_password' => did_action('application_password_did_authenticate') > 0,
                'open_until'   => (int) get_option('nova_gate_open_until', 0),
            ];
        },
        'permission_callback' => static function () { return current_user_can('manage_options'); },
    ]);
    register_rest_route('nova-gate-test/v1', '/queries', [
        'methods'  => 'GET',
        'callback' => static function () {
            global $wpdb;
            $all  = is_array($wpdb->queries) ? $wpdb->queries : [];
            $hits = [];
            $writes = 0;
            foreach ($all as $q) {
                $sql = (string) ($q[0] ?? '');
                if (stripos($sql, 'nova_gate_test_late') !== false) {
                    continue; // This helper's own flag, not Nova Gate's.
                }
                if (stripos($sql, 'nova_gate') !== false || stripos($sql, 'novamira_ai') !== false) {
                    $hits[] = preg_replace('/\s+/', ' ', $sql);
                    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql)) {
                        $writes++;
                    }
                }
            }
            return ['total' => count($all), 'hits' => $hits, 'writes' => $writes, 'state' => NovaGate\state(), 'cli_loaded' => class_exists('NovaGate\CLI')];
        },
        'permission_callback' => '__return_true',
    ]);
    // fx-22: a stand-in for other plugins' own MCP adapters (e.g. Rank Math's
    // mcp-adapter-default-server). Nova Gate gates Novamira only; this route
    // must answer for a logged-in admin no matter what the gate state is.
    register_rest_route('other-mcp/v1', '/ping', [
        'methods'  => 'GET',
        'callback' => static fn() => new WP_REST_Response(['other' => 'ok'], 200),
        'permission_callback' => static function () { return current_user_can('manage_options'); },
    ]);
});

// fx-19/fx-30: expose this request's own gate state on the response, so a check
// can prove "not open" even when WordPress's answer is also just a 404. The
// mu-plugin outlives the plugin (AC-31 deactivation), so guard the call.
add_filter('rest_post_dispatch', static function ($response) {
    if ($response instanceof WP_REST_Response && function_exists('NovaGate\\state')) {
        $response->header('X-Nova-Gate-State', NovaGate\state());
    }
    return $response;
});
add_action('send_headers', static function (): void {
    if (!headers_sent() && function_exists('NovaGate\\state')) {
        header('X-Nova-Gate-State: ' . NovaGate\state());
    }
});

add_action('admin_post_nova_gate_test_nonce', static function (): void {
    if (!current_user_can('manage_options')) { wp_die('no', '', ['response' => 403]); }
    echo wp_create_nonce('novamira_toggle_ai_abilities');
    exit;
});

$nova_gate_test_open = static function (): void {
    $updated = update_option('novamira_ai_abilities_enabled', '1');
    echo wp_json_encode(['updated' => $updated, 'user' => get_current_user_id(), 'open_until' => (int) get_option('nova_gate_open_until', 0)]);
    exit;
};
add_action('admin_post_nova_gate_test_open', $nova_gate_test_open);
add_action('admin_post_nopriv_nova_gate_test_open', $nova_gate_test_open);

add_action('admin_menu', static function (): void {
    add_options_page('Novamira test', 'Novamira test', 'manage_options', 'novamira-test', static function (): void {
        echo '<div class="wrap"><h1>Novamira test page</h1></div>';
    });
});
PHP

install_helper() {
    local b64
    b64="$(printf '%s\n' "$HELPER_PHP" | base64 -w0)"
    wpq eval "wp_mkdir_p(WPMU_PLUGIN_DIR); echo file_put_contents(WPMU_PLUGIN_DIR . '/nova-gate-test-helper.php', base64_decode('$b64')) ? 'helper-ok' : 'helper-fail';"
}
remove_helper() {
    wpq eval "@unlink(WPMU_PLUGIN_DIR . '/nova-gate-test-helper.php'); echo 'helper-removed';"
}

# ==============================================================================
echo "== Start wp-env"
if ! npx @wordpress/env start >"$TMP/start.log" 2>&1; then
    tail -20 "$TMP/start.log"
    fail "wp-env start" "could not start; see output above"
    echo "RESULT: $PASSED passed, $FAILED failed"
    exit 1
fi
pass "wp-env start"
# lock.php branches (NULL / failed GET_LOCK = busy, failed fresh read = busy, SQLite = unlocked) against a fake $wpdb.
if php tests/probes/lock-probe.php includes/lock.php > "$TMP/lock-probe.out" 2>&1; then pass "lock.php probe: $(tail -1 "$TMP/lock-probe.out")"; else fail "lock.php probe" "$(cat "$TMP/lock-probe.out")"; fi
wpq eval '@file_put_contents(WP_CONTENT_DIR . "/debug.log", "");' >/dev/null 2>&1 # AC-24 scans this run only

echo "== Baseline"
check "test helper installed" "helper-ok" "$(install_helper | tail -1)"
wpq config delete NOVA_GATE_KEY_HASH >/dev/null 2>&1
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
wpq config delete NOVA_GATE_MAX_TRIES >/dev/null 2>&1
wpq option delete nova_gate_test_late >/dev/null 2>&1
for o in nova_gate_key_hash nova_gate_keys nova_gate_tries nova_gate_open_until nova_gate_timer_minutes nova_gate_last_open nova_gate_salt; do wpq option delete "$o" >/dev/null 2>&1; done
sql "DELETE FROM wp_options WHERE option_name IN ('novamira_ai_abilities_enabled','novamira_ai_abilities_domain')" >/dev/null
wpq plugin activate novamira nova-gate >/dev/null 2>&1
wpq user delete nova_sub --yes >/dev/null 2>&1
wpq user create nova_sub nova_sub@example.test --role=subscriber --user_pass=subpass123 >/dev/null 2>&1
wpq user application-password delete "$ADMIN_USER" --all >/dev/null 2>&1
ADMIN_ID="$(wpq user get "$ADMIN_USER" --field=ID | tr -d '[:space:]')"
check "plugins active" "nova-gate novamira" "$(wpq plugin list --status=active --field=name | tr -d '\r' | sort | tr '\n' ' ' | sed 's/ $//')"
check "NOVA_GATE_IP_HEADER is set for the test site" "HTTP_X_TEST_IP" "$(wpq config get NOVA_GATE_IP_HEADER | tr -d '[:space:]')"
check "wpx reports a failing command with exit 1" "1" "$(wpx nova-gate no-such-command)"

# ------------------------------------------------------------------------------ AC-28
echo "== AC-28 load order"
status s28
check "AC-28 status HTTP 200" "200" "$CODE"
check "AC-28 early is true with both plugins active" "true" "$(json_get "$TMP/s28.body" early)"
check "AC-28 active is false before a key exists" "false" "$(json_get "$TMP/s28.body" active)"

# ------------------------------------------------------------------------------ AC-12 / AC-52 inactive
echo "== AC-12 inactive passthrough"
wpq eval 'novamira_enable_ai_abilities();' >/dev/null
check "AC-12 enable writes through to the database when inactive" "1" "$(db_enabled)"
check "AC-12 domain written through when inactive" "1" "$(db_domain_rows)"
mcp m12
check "AC-12 MCP route answers 200 with no key when inactive and switched on" "200" "$CODE"
status s52i -H "X-Test-IP: 10.0.52.1" -H "X-Nova-Gate-Key: $(printf 'z%.0s' $(seq 1 64))"
check "AC-52 inactive gate: a header still gives wrong" "wrong" "$(json_get "$TMP/s52i.body" key_header)"
check "AC-45/52 inactive gate: the wrong header is not counted" "no" "$(opt_exists nova_gate_tries)"
wpq eval 'novamira_disable_ai_abilities();' >/dev/null
check "AC-12 disable writes through when inactive" "0" "$(db_enabled)"
mcp m12b
check "AC-12 MCP route 404 after disable" "404" "$CODE"
# Set it on again so AC-13/AC-35 can prove that the first key switches it off.
wpq eval 'novamira_enable_ai_abilities();' >/dev/null
check "AC-12/35 setup: database switch is 1 before key creation" "1" "$(db_enabled)"

# ------------------------------------------------------------------------------ AC-13 → AC-35 / AC-22 / AC-23a / AC-24
echo "== AC-35 add key (ported AC-13; cookie login through wp-login.php)"
JAR="$TMP/admin.cookies"
login "$ADMIN_USER" "$ADMIN_PASS" "$JAR"
req dash GET "$BASE/wp-admin/" -b "$JAR"
check "cookie login works" "200" "$CODE"

req plugins GET "$BASE/wp-admin/plugins.php" -b "$JAR"
check_contains "AC-23a no-key notice on Plugins screen" "Nova Gate has no key yet" "$TMP/plugins.body"
settings_page "$JAR" "$TMP/page0.html"
check_contains "AC-22 status says No key yet" "No key yet" "$TMP/page0.html"
check_contains "AC-23a no-key notice on the Nova Gate page" "Nova Gate has no key yet" "$TMP/page0.html"
check_contains "AC-22 Add key form offered" 'value="add"' "$TMP/page0.html"
NONCE="$(nonce_for add "$TMP/page0.html")"
check "AC-35 add nonce found in page" "yes" "$([ -n "$NONCE" ] && echo yes || echo no)"

post_action add1 "$JAR" add "$NONCE" --data-urlencode "nova_gate_label=  Hub One  "
check "AC-35 add responds 200" "200" "$CODE"
KEY="$(extract_key "$TMP/add1.body")"
check "AC-35 key is 64 lowercase hex, shown in the POST response" "yes" "$(printf '%s' "$KEY" | grep -q '^[a-f0-9]\{64\}$' && echo yes || echo no)"
HASH="$(sha256 "$KEY")"
check "AC-35 id is sanitize_title(label)" "hub-one" "$(key_ids)"
check "AC-35 only the SHA-256 hex digest is stored" "$HASH" "$(key_field hub-one hash)"
check "AC-35 label stored trimmed" "Hub One" "$(key_field hub-one label)"
check_between "AC-35 creation time stored" "$(( $(date +%s) - 120 ))" "$(( $(date +%s) + 120 ))" "$(key_field hub-one created)"
check "AC-35 nova_gate_keys is autoloaded" "yes" "$(case "$(opt_autoload nova_gate_keys)" in on|yes) echo yes;; *) echo "no ($(opt_autoload nova_gate_keys))";; esac)"
check "AC-35 v0.1.0 option not created" "no" "$(opt_exists nova_gate_key_hash)"
check "AC-35 first key: database switch forced to 0" "0" "$(db_enabled)"
check "AC-35 first key: domain option deleted" "0" "$(db_domain_rows)"
check "AC-35 key not stored in wp_options" "0" "$(sql "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%$KEY%'")"
check "AC-35 key not stored in wp_usermeta" "0" "$(sql "SELECT COUNT(*) FROM wp_usermeta WHERE meta_value LIKE '%$KEY%'")"
check "AC-35 key not stored in wp_postmeta" "0" "$(sql "SELECT COUNT(*) FROM wp_postmeta WHERE meta_value LIKE '%$KEY%'")"
check_http_not_contains "AC-24 hash never in the add response" "$HASH" "$TMP/add1.body" "$CODE"
settings_page "$JAR" "$TMP/page1.html"
check_http_not_contains "AC-24 key not in page source after the one-time display" "$KEY" "$TMP/page1.html" "$PAGE_CODE"
check_http_not_contains "AC-24 hash not in page source" "$HASH" "$TMP/page1.html" "$PAGE_CODE"

echo "== AC-35 refusals"
post_action add_dup "$JAR" add "$NONCE" --data-urlencode "nova_gate_label=hub one"
check "AC-35 duplicate id redirects with a message" "302" "$CODE"
check "AC-35 duplicate id message" "yes" "$(grep -qi 'nova_gate_msg=exists' "$TMP/add_dup.head" && echo yes || echo no)"
check "AC-35 duplicate id: nothing changes" "hub-one" "$(key_ids)"
post_action add_empty "$JAR" add "$NONCE" --data-urlencode "nova_gate_label=!!!"
check "AC-35 empty id message" "yes" "$(grep -qi 'nova_gate_msg=bad_id' "$TMP/add_empty.head" && echo yes || echo no)"
post_action add_blank "$JAR" add "$NONCE" --data-urlencode "nova_gate_label=   "
check "AC-35 blank label message" "yes" "$(grep -qi 'nova_gate_msg=bad_label' "$TMP/add_blank.head" && echo yes || echo no)"
post_action add_long "$JAR" add "$NONCE" --data-urlencode "nova_gate_label=$(printf 'a%.0s' $(seq 1 61))"
check "AC-35 61-character label refused" "yes" "$(grep -qi 'nova_gate_msg=bad_label' "$TMP/add_long.head" && echo yes || echo no)"
check "AC-35 refusals: still one key" "hub-one" "$(key_ids)"
req msg_exists GET "$BASE/wp-admin/options-general.php?page=nova-gate&nova_gate_msg=exists" -b "$JAR"
check_contains "AC-35 exists message rendered" "already exists" "$TMP/msg_exists.body"
req msg_badid GET "$BASE/wp-admin/options-general.php?page=nova-gate&nova_gate_msg=bad_id" -b "$JAR"
check_contains "AC-35 bad id message rendered" "no usable id" "$TMP/msg_badid.body"

echo "== AC-22 settings page content"
check_contains "AC-22 status Locked" ">Locked<" "$TMP/page1.html"
check_contains "AC-22 key table shows the id" "<code>hub-one</code>" "$TMP/page1.html"
check_contains "AC-22 key table shows the label" "<td>Hub One</td>" "$TMP/page1.html"
check_contains "AC-22 per-key Remove" 'name="nova_gate_key_id" value="hub-one"' "$TMP/page1.html"
check_contains "AC-22 Add key form" 'value="add"' "$TMP/page1.html"
check_contains "AC-22 timer field" 'name="nova_gate_minutes"' "$TMP/page1.html"
check_contains "AC-22 Claude Code CLI example with --header" "--header &quot;X-Nova-Gate-Key: YOUR_KEY&quot;" "$TMP/page1.html"
check_contains "AC-22 JSON headers block" "&quot;headers&quot;" "$TMP/page1.html"
check_contains "AC-22 mcp-wordpress-remote CUSTOM_HEADERS" "CUSTOM_HEADERS" "$TMP/page1.html"
check_contains "AC-22 curl example" "curl -i -X POST $BASE/wp-json/mcp/novamira" "$TMP/page1.html"
# v0.2.2: every example carries the WordPress login Novamira needs, and the curl test starts with initialize.
check_contains "AC-22 Claude Code CLI example sends the login" "--header &quot;Authorization: Basic YOUR_LOGIN_BASE64&quot;" "$TMP/page1.html"
check_contains "AC-22 JSON example sends the login" "&quot;Authorization&quot;: &quot;Basic YOUR_LOGIN_BASE64&quot;" "$TMP/page1.html"
check_contains "AC-22 mcp-wordpress-remote user name" "WP_API_USERNAME" "$TMP/page1.html"
check_contains "AC-22 mcp-wordpress-remote application password" "WP_API_PASSWORD" "$TMP/page1.html"
check_contains "AC-22 curl example sends the login" "-u &quot;YOUR_USERNAME:YOUR_APP_PASSWORD&quot;" "$TMP/page1.html"
check_contains "AC-22 curl example starts with initialize" "&quot;method&quot;:&quot;initialize&quot;" "$TMP/page1.html"
check_contains "AC-22 Base64 helper" "base64 | tr -d" "$TMP/page1.html"
check_not_contains "AC-22 no tools/list curl example" "&quot;method&quot;:&quot;tools/list&quot;" "$TMP/page1.html"
check_contains "AC-22 examples use the real MCP URL" "$BASE/wp-json/mcp/novamira" "$TMP/page1.html"
check_contains "AC-22 status URL shown" "$BASE/wp-json/nova-gate/v1/status" "$TMP/page1.html"
check_contains "AC-22 last manual open: never yet" "Never." "$TMP/page1.html"
check_contains "AC-22 disclaimer line" "Independent add-on. Not made, approved or supported by Novamira." "$TMP/page1.html"
check_contains "AC-22 footer link" 'href="https://monkeywebs.com/" target="_blank" rel="noopener noreferrer">Made by Monkey Webs – see our other tools</a>' "$TMP/page1.html"
check "AC-22 exactly one monkeywebs.com link on the page" "1" "$(grep -o 'href="https://monkeywebs.com/"' "$TMP/page1.html" | wc -l | tr -d ' ')"
check_contains "AC-51 blocked count shown" "Addresses blocked now: 0" "$TMP/page1.html"
check_contains "AC-51 last wrong try none" "Last wrong try: none" "$TMP/page1.html"
check_contains "AC-50 Clear blocks button" 'value="clear_blocks"' "$TMP/page1.html"
req page_sub GET "$BASE/wp-admin/options-general.php?page=nova-gate"
check "AC-22 page is not shown to logged-out visitors" "302" "$CODE"

# ------------------------------------------------------------------------------ AC-21 / AC-52 / AC-29
echo "== AC-52 status route (changes AC-21)"
status s21
check "AC-21 200 without login" "200" "$CODE"
check "AC-52 exactly the four keys" "nova_gate,active,early,key_header" "$(json_keys "$TMP/s21.body")"
check "AC-63 nova_gate version 0.2.2" "0.2.2" "$(json_get "$TMP/s21.body" nova_gate)"
check "AC-21 active true" "true" "$(json_get "$TMP/s21.body" active)"
check "AC-21 key_header missing" "missing" "$(json_get "$TMP/s21.body" key_header)"
check "AC-21 Cache-Control no-store" "no-store" "$(header_value "$TMP/s21.head" Cache-Control)"
check_http_not_contains "AC-21 never returns the hash" "$HASH" "$TMP/s21.body" "$CODE"
status s21ok -H "X-Nova-Gate-Key: $KEY"
check "AC-21 key_header ok with the right key" "ok" "$(json_get "$TMP/s21ok.body" key_header)"
check "AC-45 a right key on the status route records nothing" "no" "$(opt_exists nova_gate_tries)"
status s21wrong -H "X-Test-IP: 10.0.21.1" -H "X-Nova-Gate-Key: $(wrong_key "$KEY")"
check "AC-21 key_header wrong with a wrong key" "wrong" "$(json_get "$TMP/s21wrong.body" key_header)"
check "AC-47 one request to the status route = exactly one wrong try" "1" "$(tries_sum_n)"
check "AC-48 nova_gate_tries is autoloaded" "yes" "$(case "$(opt_autoload nova_gate_tries)" in on|yes) echo yes;; *) echo "no ($(opt_autoload nova_gate_tries))";; esac)"
tries_json > "$TMP/tries21.json"
check_not_contains "AC-48 raw address never stored" "10.0.21.1" "$TMP/tries21.json"
check "AC-48 entry keyed by 16 hex characters" "yes" "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $k = array_keys((array) $d); echo (count($k) === 1 && preg_match("/^[a-f0-9]{16}$/", (string) $k[0])) ? "yes" : "no";' "$TMP/tries21.json")"
check "AC-48 entry has n, first, last, until" "n,first,last,until" "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo implode(",", array_keys((array) reset($d)));' "$TMP/tries21.json")"
check "AC-48 id is the first 16 hex of sha256(address|NONCE_SALT)" "$(wpq eval 'echo substr(hash("sha256", "10.0.21.1|" . NONCE_SALT), 0, 16);' | tr -d '[:space:]')" "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo (string) array_keys((array) $d)[0];' "$TMP/tries21.json")"
clear_tries

echo "== AC-29 end to end over HTTP"
mcp m29a
check "AC-29 MCP without header -> 404" "404" "$CODE"
check "AC-29 MCP without header -> rest_no_route" "rest_no_route" "$(json_get "$TMP/m29a.body" code)"
check "AC-29 database still 0 (a)" "0" "$(db_enabled)"
mcp m29b -H "X-Nova-Gate-Key: $KEY"
check "AC-29 MCP with the right key -> 200" "200" "$CODE"
check "AC-29 MCP with key answers from the test double" "mcp" "$(json_get "$TMP/m29b.body" fixture)"
check "AC-29 database still 0 (b)" "0" "$(db_enabled)"
mcp m29c -H "X-Test-IP: 10.0.29.1" -H "X-Nova-Gate-Key: $(wrong_key "$KEY")"
check "AC-29 MCP with a wrong key -> 404" "404" "$CODE"
check "AC-29 database still 0 (c)" "0" "$(db_enabled)"
req u29 POST "$BASE/wp-json/novamira/v1/upload"
check "AC-29 upload without key -> 200" "200" "$CODE"
check "AC-29 upload enabled true" "true" "$(json_get "$TMP/u29.body" enabled)"
check "AC-29 database still 0 (d)" "0" "$(db_enabled)"
req a29 POST "$BASE/wp-json/novamira/v1/abilities/a/b/run"
check "AC-29 ability run without key -> 403" "403" "$CODE"
check "AC-29 ability run -> novamira_disabled" "novamira_disabled" "$(json_get "$TMP/a29.body" code)"
check "AC-29 database still 0 (e)" "0" "$(db_enabled)"
req q29 POST "$BASE/wp-json/novamira/v1/upload?rest_route=/mcp/novamira"
check "AC-29 upload path with ?rest_route=/mcp/novamira -> 404" "404" "$CODE"
check "AC-29 database still 0 (f)" "0" "$(db_enabled)"
req f29 POST "$BASE/wp-json/novamira/v1/upload" -H "Content-Type: application/x-www-form-urlencoded" --data "rest_route=/mcp/novamira"
check "AC-29 form body rest_route=/mcp/novamira -> 404" "404" "$CODE"
check "AC-29 database still 0 (g)" "0" "$(db_enabled)"
# The reverse trick: MCP path, link route in the form body. WordPress dispatches the body's
# route, so the answer must come from upload and never from MCP.
req r29 POST "$BASE/wp-json/mcp/novamira" -H "Content-Type: application/x-www-form-urlencoded" --data "rest_route=/novamira/v1/upload"
check "AC-29 MCP path with form body rest_route=<upload> is answered by upload, not MCP" "upload" "$(json_get "$TMP/r29.body" fixture)"
check_http_not_contains "AC-29 MCP path with form body rest_route=<upload> has no MCP answer" '"fixture":"mcp"' "$TMP/r29.body" "$CODE"
check "AC-29 database still 0 (i)" "0" "$(db_enabled)"
# Plain permalinks: no /wp-json/ in the path at all.
req i29 POST "$BASE/index.php?rest_route=/mcp/novamira" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "AC-29 index.php?rest_route=/mcp/novamira without key -> 404" "404" "$CODE"
check "AC-29 index.php?rest_route=/mcp/novamira without key -> rest_no_route" "rest_no_route" "$(json_get "$TMP/i29.body" code)"
req i29k POST "$BASE/index.php?rest_route=/mcp/novamira" -H "Content-Type: application/json" -H "X-Nova-Gate-Key: $KEY" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "AC-29 index.php?rest_route=/mcp/novamira with the key -> 200" "200" "$CODE"
check "AC-29 index.php?rest_route=/mcp/novamira with the key answers from MCP" "mcp" "$(json_get "$TMP/i29k.body" fixture)"
# rest_route in the query string and in the form body disagree: locked, and no MCP answer.
req x29 POST "$BASE/wp-json/novamira/v1/upload?rest_route=/novamira/v1/upload" -H "Content-Type: application/x-www-form-urlencoded" --data "rest_route=/mcp/novamira"
check_http_not200 "AC-29 disagreeing rest_route values are not 200" "$CODE"
check_http_not_contains "AC-29 disagreeing rest_route values give no MCP answer" '"fixture":"mcp"' "$TMP/x29.body" "$CODE"
req y29 POST "$BASE/wp-json/mcp/novamira?rest_route=/novamira/v1/upload" -H "Content-Type: application/x-www-form-urlencoded" --data "rest_route=/mcp/novamira"
check_http_not200 "AC-29 disagreeing rest_route values on the MCP path are not 200" "$CODE"
check_http_not_contains "AC-29 disagreeing rest_route values on the MCP path give no MCP answer" '"fixture":"mcp"' "$TMP/y29.body" "$CODE"
check "AC-29 database still 0 (j)" "0" "$(db_enabled)"
req g29 GET "$BASE/wp-json/novamira/v1/admin-access/abc123"
check "AC-29 admin-access/<nonce> link route without key -> 200" "200" "$CODE"
req p29 POST "$BASE/wp-json/novamira/v1/uplo%61d"
check_http_not200 "AC-29 percent-encoded upload path without key is not 200" "$CODE"
check_gate_not_open "fx-19 percent-encoded upload path: the gate state is not open" p29
check "AC-29 database still 0 (h)" "0" "$(db_enabled)"

# fx-30 route-trick matrix: each must be answered by WordPress (a real HTTP answer),
# must not be a 200 MCP answer, and the gate must not have opened for the request.
req b30 POST "$BASE/index.php" -H "Content-Type: application/x-www-form-urlencoded" --data "rest_route=/mcp/novamira"
check_http_not200 "fx-30 rest_route in the POST body on index.php is not 200" "$CODE"
check "fx-30 rest_route in the POST body gives rest_no_route" "rest_no_route" "$(json_get "$TMP/b30.body" code)"
check_gate_not_open "fx-30 rest_route in the POST body: the gate state is not open" b30
req j30 POST "$BASE/index.php" -H "Content-Type: application/json" -d '{"rest_route":"/mcp/novamira"}'
check_http_not_contains "fx-30 rest_route in a JSON body gives no MCP answer" '"fixture":"mcp"' "$TMP/j30.body" "$CODE"
check_gate_not_open "fx-30 rest_route in a JSON body: the gate state is not open" j30
req u30 POST "$BASE/wp-json/MCP/novamira" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check_http_not200 "fx-30 upper-case /wp-json/MCP/novamira without key is not 200" "$CODE"
check_gate_not_open "fx-30 upper-case MCP path: the gate state is not open" u30
req d30 POST "$BASE/wp-json//mcp/novamira" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check_http_not200 "fx-30 double-slash /wp-json//mcp/novamira without key is not 200" "$CODE"
check_gate_not_open "fx-30 double-slash MCP path: the gate state is not open" d30
clear_tries

# ------------------------------------------------------------------------------ AC-32 / AC-34 / AC-14 ported
echo "== AC-32 two keys, any opens; AC-14 ported (add new, remove old); AC-34 remove one"
add_key_ui add2 "$JAR" "Laptop"
KEY2="$KEY_OUT"
check "AC-32 second key added and shown once" "yes" "$([ -n "$KEY2" ] && [ "$KEY2" != "$KEY" ] && echo yes || echo no)"
HASH2="$(sha256 "$KEY2")"
check "AC-32 two keys stored" "hub-one,laptop" "$(key_ids)"
check "AC-32 second hash stored" "$HASH2" "$(key_field laptop hash)"
check "AC-32 first hash unchanged" "$HASH" "$(key_field hub-one hash)"
mcp m32a -H "X-Nova-Gate-Key: $KEY"
check "AC-32 key A -> 200" "200" "$CODE"
mcp m32b -H "X-Nova-Gate-Key: $KEY2"
check "AC-32 key B -> 200" "200" "$CODE"
KEY_THIRD="$(php -r 'echo bin2hex(random_bytes(32));')"
mcp m32c -H "X-Test-IP: 10.0.32.1" -H "X-Nova-Gate-Key: $KEY_THIRD"
check "AC-32 a well-formed third key -> 404" "404" "$CODE"
status s32 -H "X-Nova-Gate-Key: $KEY2"
check "AC-52 ok for any matching key" "ok" "$(json_get "$TMP/s32.body" key_header)"
check "AC-29 database still 0 (two keys)" "0" "$(db_enabled)"
remove_key_ui rm1 "$JAR" hub-one
check "AC-34 remove redirects" "302" "$CODE"
check "AC-34 message: other keys keep working" "removed" "$(header_value "$TMP/rm1.head" Location | sed 's/.*nova_gate_msg=//')"
check "AC-34 only B stored" "laptop" "$(key_ids)"
mcp m34a -H "X-Test-IP: 10.0.34.1" -H "X-Nova-Gate-Key: $KEY"
check "AC-34/AC-14 removed key -> 404" "404" "$CODE"
mcp m34b -H "X-Nova-Gate-Key: $KEY2"
check "AC-34/AC-14 remaining key -> 200" "200" "$CODE"
remove_key_ui rm_unknown "$JAR" nope
check "AC-34 unknown id message" "yes" "$(grep -qi 'nova_gate_msg=unknown' "$TMP/rm_unknown.head" && echo yes || echo no)"
check "AC-34 unknown id: nothing changes" "laptop" "$(key_ids)"
clear_tries

# ------------------------------------------------------------------------------ AC-17
echo "== AC-17 permissions and CSRF"
KEYS_BEFORE="$(keys_json)"
req c17a POST "$BASE/wp-admin/options-general.php?page=nova-gate" -b "$JAR" --data-urlencode "nova_gate_action=add" --data-urlencode "nova_gate_label=x"
check "AC-17 add without nonce is refused (403)" "403" "$CODE"
check "AC-17 keys unchanged without nonce" "$KEYS_BEFORE" "$(keys_json)"
req c17b POST "$BASE/wp-admin/options-general.php?page=nova-gate" -b "$JAR" --data-urlencode "nova_gate_action=remove" --data-urlencode "nova_gate_key_id=laptop" --data-urlencode "nova_gate_nonce=0123456789"
check "AC-17 remove with a bad nonce is refused (403)" "403" "$CODE"
check "AC-17 keys unchanged with bad nonce" "$KEYS_BEFORE" "$(keys_json)"
settings_page "$JAR" "$TMP/page17.html"
RNONCE="$(nonce_for remove "$TMP/page17.html")"
req c17c POST "$BASE/wp-admin/options-general.php?page=nova-gate" --data-urlencode "nova_gate_action=remove" --data-urlencode "nova_gate_key_id=laptop" --data-urlencode "nova_gate_nonce=$RNONCE"
check "AC-17 logged-out POST is sent to login" "302" "$CODE"
check "AC-17 keys unchanged when logged out" "$KEYS_BEFORE" "$(keys_json)"
SUBJAR="$TMP/sub.cookies"
login nova_sub subpass123 "$SUBJAR"
req c17d POST "$BASE/wp-admin/options-general.php?page=nova-gate" -b "$SUBJAR" --data-urlencode "nova_gate_action=remove" --data-urlencode "nova_gate_key_id=laptop" --data-urlencode "nova_gate_nonce=$RNONCE"
check "AC-17 subscriber cannot act (403)" "403" "$CODE"
check "AC-17 keys unchanged for subscriber" "$KEYS_BEFORE" "$(keys_json)"
req c17e GET "$BASE/wp-admin/options-general.php?page=nova-gate" -b "$SUBJAR"
check "AC-17 subscriber cannot see the page (403)" "403" "$CODE"

# ------------------------------------------------------------------------------ AC-8 / AC-30 / AC-23b / AC-9 / AC-10
echo "== AC-30 manual open end to end (cookie admin through the double's own toggle)"
req tn GET "$BASE/wp-admin/admin-post.php?action=nova_gate_test_nonce" -b "$JAR"
TOGGLE_NONCE="$(tr -d '[:space:]' < "$TMP/tn.body")"
check "toggle nonce obtained for the cookie session" "yes" "$([ -n "$TOGGLE_NONCE" ] && echo yes || echo no)"
NOW="$(date +%s)"
req t1 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=on&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
check "AC-8 toggle on redirects (302)" "302" "$CODE"
UNTIL="$(opt nova_gate_open_until | tr -d '[:space:]')"
check "AC-8 open_until is about now + 30 minutes" "yes" "$([ "$UNTIL" -ge $((NOW + 1800 - 60)) ] && [ "$UNTIL" -le $((NOW + 1800 + 60)) ] && echo yes || echo "no ($UNTIL vs $NOW)")"
check "AC-8 database switch stays 0" "0" "$(db_enabled)"
wpq option get nova_gate_last_open --format=json > "$TMP/last_open.json"
check "AC-8 last_open user is the admin" "$ADMIN_ID" "$(json_get "$TMP/last_open.json" user)"
LAST_T="$(json_get "$TMP/last_open.json" time)"
check "AC-8 last_open time is now" "yes" "$([ "$LAST_T" -ge $((NOW - 60)) ] && [ "$LAST_T" -le $((NOW + 60)) ] && echo yes || echo "no ($LAST_T)")"
mcp m30a
check "AC-30 MCP without key -> 200 while manually open" "200" "$CODE"
check "AC-30 database still 0 while open" "0" "$(db_enabled)"
req dash2 GET "$BASE/wp-admin/" -b "$JAR"
check_contains "AC-23b open notice on an admin screen" "Gate open. Locks again at" "$TMP/dash2.body"
check_contains "AC-23b open notice has a Lock now link" "action=nova_gate_lock&amp;_wpnonce=" "$TMP/dash2.body"
settings_page "$JAR" "$TMP/page2.html"
check_contains "AC-22 status Open until" "Open until" "$TMP/page2.html"
check_contains "AC-22 last manual open shows the admin" "admin on" "$TMP/page2.html"
req dash_sub GET "$BASE/wp-admin/" -b "$SUBJAR"
check_http_not_contains "AC-23b open notice hidden from subscribers" "Gate open. Locks again at" "$TMP/dash_sub.body" "$CODE"

echo "== AC-30 open_until in the past"
wpq option update nova_gate_open_until 1 >/dev/null
mcp m30b
check "AC-30 MCP without key -> 404 after open_until passed" "404" "$CODE"
mcp m30c -H "X-Nova-Gate-Key: $KEY2"
check "AC-30 key still works after open_until passed" "200" "$CODE"

echo "== AC-9 manual lock through the double's toggle (off)"
req t2 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=on&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
mcp m9a
check "AC-9 setup: open again -> 200" "200" "$CODE"
req t3 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=off&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
check "AC-9 open_until removed after update_option(...,'0')" "no" "$(opt_exists nova_gate_open_until)"
mcp m9b
check "AC-9 MCP without key -> 404 after lock" "404" "$CODE"
check "AC-9 database 0 after lock" "0" "$(db_enabled)"

echo "== AC-9 Lock now link"
req t4 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=on&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
check "AC-9 setup: open" "yes" "$(opt_exists nova_gate_open_until)"
req l0 GET "$BASE/wp-admin/admin-post.php?action=nova_gate_lock" -b "$JAR"
check "AC-17 Lock now without nonce is refused (403)" "403" "$CODE"
check "AC-17 still open without nonce" "yes" "$(opt_exists nova_gate_open_until)"
req dash3 GET "$BASE/wp-admin/" -b "$JAR"
LOCK_URL="$(grep -o 'admin-post.php?action=nova_gate_lock&amp;_wpnonce=[A-Za-z0-9]*' "$TMP/dash3.body" | head -1 | sed 's/&amp;/\&/')"
req l1 GET "$BASE/wp-admin/$LOCK_URL" -b "$JAR" -H "Referer: $BASE/wp-admin/"
check "AC-9 Lock now link redirects" "302" "$CODE"
check "AC-9 Lock now removes open_until" "no" "$(opt_exists nova_gate_open_until)"
mcp m9c
check "AC-9 MCP -> 404 after Lock now" "404" "$CODE"
req l2 GET "$BASE/wp-admin/$LOCK_URL" -b "$SUBJAR"
check "AC-17 subscriber cannot use the lock link (403)" "403" "$CODE"

echo "== AC-10 who may open"
APP_PW="$(wpq user application-password create "$ADMIN_USER" nova-gate-test --porcelain | tr -d '\r\n')"
check "application password created inside wp-env" "yes" "$([ -n "$APP_PW" ] && echo yes || echo no)"
req ap1 POST "$BASE/wp-json/nova-gate-test/v1/open" -u "$ADMIN_USER:$APP_PW"
check "AC-10 app-password request reached the route as the admin" "$ADMIN_ID" "$(json_get "$TMP/ap1.body" user)"
check "AC-10 app-password authentication was detected" "true" "$(json_get "$TMP/ap1.body" app_password)"
check "AC-10 app-password update_option('1') does not open" "0" "$(json_get "$TMP/ap1.body" open_until)"
check "AC-10 open_until absent after app-password attempt" "no" "$(opt_exists nova_gate_open_until)"
check "AC-10 database stays 0 after app-password attempt" "0" "$(db_enabled)"
mcp m10a
check "AC-10 MCP -> 404 after app-password attempt" "404" "$CODE"
req ap2 POST "$BASE/wp-json/nova-gate-test/v1/open" -u "$ADMIN_USER:wrong-password"
check "AC-10 wrong app password -> 401" "401" "$CODE"

echo "== fx-20/21/22 credentialed MCP, an executable fixture ability, other MCP adapters"
# fx-20: a request carrying a real application password is still gated.
mcp mcred0 -u "$ADMIN_USER:$APP_PW"
check "fx-20 credentialed MCP without a key -> 404" "404" "$CODE"
check "fx-20 credentialed no-key answer is rest_no_route" "rest_no_route" "$(json_get "$TMP/mcred0.body" code)"
mcp mcredw -u "$ADMIN_USER:$APP_PW" -H "X-Test-IP: 10.0.20.1" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "fx-20 credentialed MCP with a wrong key -> 404" "404" "$CODE"
mcp mcredk -u "$ADMIN_USER:$APP_PW" -H "X-Nova-Gate-Key: $KEY2"
check "fx-20 credentialed MCP with the valid key -> the route answers" "200" "$CODE"
check "fx-20 credentialed valid-key answer comes from MCP" "mcp" "$(json_get "$TMP/mcredk.body" fixture)"

# fx-21: a locked request must not be able to EXECUTE an ability. fixture-run
# writes the marker option only when the call actually runs.
wpq option delete nova_gate_fixture_ran >/dev/null 2>&1
req fr0 POST "$BASE/wp-json/novamira/v1/fixture-run"
check "fx-21 anonymous fixture-run while locked -> 403" "403" "$CODE"
check "fx-21 the marker stays absent after an anonymous try" "no" "$(opt_exists nova_gate_fixture_ran)"
req fr1 POST "$BASE/wp-json/novamira/v1/fixture-run" -u "$ADMIN_USER:$APP_PW"
check "fx-21 credentialed fixture-run while locked -> 403" "403" "$CODE"
check "fx-21 the marker stays absent after a credentialed try" "no" "$(opt_exists nova_gate_fixture_ran)"
req fr2 POST "$BASE/wp-json/novamira/v1/fixture-run" -H "X-Nova-Gate-Key: $KEY2"
check "fx-21 fixture-run with the valid key -> 200" "200" "$CODE"
check "fx-21 the marker is written when the gate is open" "yes" "$(opt_exists nova_gate_fixture_ran)"
wpq option delete nova_gate_fixture_ran >/dev/null 2>&1
check "fx-21 the marker option is cleaned up" "no" "$(opt_exists nova_gate_fixture_ran)"

# fx-22: other plugins' MCP routes are outside Nova Gate (documented).
req omcp GET "$BASE/wp-json/other-mcp/v1/ping" -u "$ADMIN_USER:$APP_PW"
check "fx-22 other plugins' MCP routes are outside Nova Gate (documented): ping 200 while locked" "200" "$CODE"
check "fx-22 other-mcp ping body" "ok" "$(json_get "$TMP/omcp.body" other)"
req omcp_anon GET "$BASE/wp-json/other-mcp/v1/ping"
check "fx-22 other-mcp ping without credentials -> 401" "401" "$CODE"
mcp m22
check "fx-22 Novamira MCP still 404 while other-mcp answers" "404" "$CODE"
clear_tries
req so1 GET "$BASE/wp-admin/admin-post.php?action=nova_gate_test_open" -b "$SUBJAR"
check "AC-10 subscriber update_option('1') does not open" "0" "$(json_get "$TMP/so1.body" open_until)"
check "AC-10 database stays 0 after subscriber attempt" "0" "$(db_enabled)"
req so2 GET "$BASE/wp-admin/admin-post.php?action=nova_gate_test_open"
check "AC-10 logged-out update_option('1') does not open" "0" "$(json_get "$TMP/so2.body" open_until)"
check "AC-10 open_until still absent" "no" "$(opt_exists nova_gate_open_until)"
req so3 GET "$BASE/wp-admin/admin-post.php?action=nova_gate_test_open" -b "$JAR"
check "AC-10 cookie admin update_option('1') does open" "yes" "$([ "$(json_get "$TMP/so3.body" open_until)" -gt "$(date +%s)" ] && echo yes || echo no)"
wpq eval 'update_option("novamira_ai_abilities_enabled", "0");' >/dev/null
check "AC-9 WP-CLI update_option('0') locks" "no" "$(opt_exists nova_gate_open_until)"
wpq eval 'update_option("novamira_ai_abilities_enabled", "1");' >/dev/null
check "AC-10 WP-CLI update_option('1') is allowed to open" "yes" "$(opt_exists nova_gate_open_until)"
check "AC-10 database stays 0 after WP-CLI open" "0" "$(db_enabled)"
mcp m10b
check "AC-10 MCP -> 200 after WP-CLI open" "200" "$CODE"
wpq eval 'update_option("novamira_ai_abilities_enabled", "0");' >/dev/null
mcp m10c
check "AC-10 MCP -> 404 after WP-CLI lock" "404" "$CODE"

echo "== AC-11 timer end to end"
settings_page "$JAR" "$TMP/page3.html"
TNONCE="$(nonce_for timer "$TMP/page3.html")"
post_action tm1 "$JAR" timer "$TNONCE" --data-urlencode "nova_gate_minutes=500"
check "AC-11 500 is clamped to 240" "240" "$(opt nova_gate_timer_minutes)"
post_action tm2 "$JAR" timer "$TNONCE" --data-urlencode "nova_gate_minutes=abc"
check "AC-11 abc becomes 30" "30" "$(opt nova_gate_timer_minutes)"
post_action tm3 "$JAR" timer "$TNONCE" --data-urlencode "nova_gate_minutes=2"
check "AC-11 2 is clamped to 5" "5" "$(opt nova_gate_timer_minutes)"
post_action tm4 "$JAR" timer "$TNONCE" --data-urlencode "nova_gate_minutes=15"
check "AC-11 15 is stored" "15" "$(opt nova_gate_timer_minutes)"
NOW="$(date +%s)"
req t5 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=on&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
UNTIL="$(opt nova_gate_open_until | tr -d '[:space:]')"
check "AC-11 manual open uses the saved timer (15 min)" "yes" "$([ "$UNTIL" -ge $((NOW + 900 - 60)) ] && [ "$UNTIL" -le $((NOW + 900 + 60)) ] && echo yes || echo "no ($UNTIL vs $NOW)")"
req nvp GET "$BASE/wp-admin/options-general.php?page=novamira-test" -b "$JAR"
check_contains "AC-23d notice on a Novamira admin page" "Nova Gate controls this switch. Turning it on opens the gate for 15 minutes." "$TMP/nvp.body"
req t6 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=off&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
check "AC-11 cleanup: locked" "no" "$(opt_exists nova_gate_open_until)"

# ------------------------------------------------------------------------------ AC-18
echo "== AC-18 self-heal"
db_set_enabled_raw 1 >/dev/null
sql "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('novamira_ai_abilities_domain', 'localhost', 'yes') ON DUPLICATE KEY UPDATE option_value='localhost'" >/dev/null
check "AC-18 setup: raw database switch is 1" "1" "$(db_enabled)"
req home GET "$BASE/"
check "AC-18 a normal request reaches init (200)" "200" "$CODE"
check "AC-18 database switch healed back to 0" "0" "$(db_enabled)"
check "AC-18 domain option deleted" "0" "$(db_domain_rows)"
mcp m18
check "AC-18 MCP -> 404 after heal" "404" "$CODE"

# ------------------------------------------------------------------------------ AC-41 .. AC-48, AC-65 wrong-try limit
echo "== AC-41/42/43/65 wrong-try limit over HTTP (addresses through the test IP header)"
clear_tries
X="10.0.65.1"; Y="10.0.65.2"
for i in 1 2 3 4 5 6 7 8 9; do
    mcp "w65_$i" -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
done
check "AC-41 nine wrong tries recorded (n=9)" "9" "$(tries_sum_n)"
check "AC-41 not blocked after nine" "0" "$(blocked_count)"
mcp w65_ok9 -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $KEY2"
check "AC-41 the right key still opens after nine wrong tries" "200" "$CODE"
check "AC-45 a right key from a non-blocked address records nothing (n still 9)" "9" "$(tries_sum_n)"
mcp w65_10 -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "AC-41 tenth wrong try -> 404" "404" "$CODE"
check "AC-41 X is blocked after the tenth" "1" "$(blocked_count)"
tries_json > "$TMP/tries65.json"
UNTIL_X="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo (int) reset($d)["until"];' "$TMP/tries65.json")"
LAST_X="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo (int) reset($d)["last"];' "$TMP/tries65.json")"
check "AC-41 blocked for 900 s from the tenth try" "900" "$((UNTIL_X - LAST_X))"
check_not_contains "AC-48 raw address X never stored" "$X" "$TMP/tries65.json"
mcp w65_blocked_right -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $KEY2"
check "AC-42/65 right key from blocked X -> 404" "404" "$CODE"
check "AC-42 database still 0" "0" "$(db_enabled)"
status s65x -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $KEY2"
check "AC-42/52/65 status from blocked X with the right key -> blocked" "blocked" "$(json_get "$TMP/s65x.body" key_header)"
status s65x_wrong -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "AC-42 status from blocked X with a wrong key -> blocked" "blocked" "$(json_get "$TMP/s65x_wrong.body" key_header)"
status s65x_empty -H "X-Test-IP: $X" -H "X-Nova-Gate-Key;"
check "AC-42 status from blocked X with an empty header -> blocked" "blocked" "$(json_get "$TMP/s65x_empty.body" key_header)"
check "AC-42 tries during the block are not counted (n still 10)" "10" "$(tries_sum_n)"
tries_json > "$TMP/tries65b.json"
check "AC-42 the block is not extended" "$UNTIL_X" "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo (int) reset($d)["until"];' "$TMP/tries65b.json")"
status s65x_missing -H "X-Test-IP: $X"
check "AC-42 no header from blocked X -> missing (header, not address, is judged)" "missing" "$(json_get "$TMP/s65x_missing.body" key_header)"
mcp w65_y -H "X-Test-IP: $Y" -H "X-Nova-Gate-Key: $KEY2"
check "AC-43/65 the same right key from Y -> 200" "200" "$CODE"
status s65y -H "X-Test-IP: $Y" -H "X-Nova-Gate-Key: $KEY2"
check "AC-43 status from Y -> ok" "ok" "$(json_get "$TMP/s65y.body" key_header)"
mcp w65_y_wrong -H "X-Test-IP: $Y" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "AC-43 a wrong try from Y is counted on its own (two entries)" "2" "$(tries_count)"
check "AC-43 Y not blocked" "1" "$(blocked_count)"

echo "== AC-46 other doors stay open for a blocked address"
req u46 POST "$BASE/wp-json/novamira/v1/upload" -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "AC-46/65 blocked X can use the upload link route (200)" "200" "$CODE"
req a46 POST "$BASE/wp-json/novamira/v1/abilities/a/b/run" -H "X-Test-IP: $X"
check "AC-46 blocked X: ability run still 403" "403" "$CODE"
req t46 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=on&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
mcp m46 -H "X-Test-IP: $X"
check "AC-46/65 blocked X without a key -> 200 during a manual open" "200" "$CODE"
mcp m46k -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $KEY2"
check "AC-46 blocked X with the right key during a manual open -> 200 (open_manual, not open_key)" "200" "$CODE"
req t46b GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=off&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
mcp m46c -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $KEY2"
check "AC-46 after the manual open ends, blocked X is refused again" "404" "$CODE"
check "AC-46 database still 0" "0" "$(db_enabled)"

echo "== AC-51 the settings page shows the block"
settings_page "$JAR" "$TMP/page51.html"
check_contains "AC-51 blocked count shown" "Addresses blocked now: 1" "$TMP/page51.html"
check_contains "AC-51 last wrong try shown" "Last wrong try: " "$TMP/page51.html"
check_http_not_contains "AC-51 last wrong try is not none" "Last wrong try: none" "$TMP/page51.html" "$PAGE_CODE"
check_contains "AC-51 warning notice while blocked" "1 address is blocked right now" "$TMP/page51.html"
check_http_not_contains "AC-51 no raw address on the page" "$X" "$TMP/page51.html" "$PAGE_CODE"
check_http_not_contains "AC-51 no hashed address on the page" "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo (string) array_keys($d)[0];' "$TMP/tries65.json")" "$TMP/page51.html" "$PAGE_CODE"
check_http_not_contains "AC-51 no key hash on the page" "$HASH2" "$TMP/page51.html" "$PAGE_CODE"

echo "== AC-44 expiry: until moved into the past"
wpq eval '$t = get_option("nova_gate_tries", []); foreach ($t as &$e) { if ($e["until"] > 0) { $e["until"] = 1; } } unset($e); update_option("nova_gate_tries", $t, true);' >/dev/null
check "AC-44 setup: nobody blocked" "0" "$(blocked_count)"
mcp m44 -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $KEY2"
check "AC-44/65 the right key opens again from X after the block ended" "200" "$CODE"
status s44 -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $KEY2"
check "AC-44 status from X -> ok" "ok" "$(json_get "$TMP/s44.body" key_header)"
mcp m44w -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "AC-44 the next wrong try from X starts from one (expired block dropped, Y entry kept)" "2" "$(tries_sum_n)"
check "AC-44 two entries (X fresh, Y)" "2" "$(tries_count)"
# Window: a 5-try entry whose window ended is forgotten; the next wrong try starts a new window.
wpq eval '$t = get_option("nova_gate_tries", []); foreach ($t as &$e) { $e["n"] = 5; $e["first"] = time() - 601; $e["last"] = time() - 1; } unset($e); update_option("nova_gate_tries", $t, true);' >/dev/null
mcp m44w2 -H "X-Test-IP: $X" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "AC-44 tries whose window ended are forgotten (new window, n=1, stale Y dropped)" "1" "$(tries_sum_n)"
check "AC-44 only X left" "1" "$(tries_count)"
clear_tries

echo "== AC-45 / AC-61 footprint with SAVEQUERIES"
req q45a GET "$BASE/wp-json/nova-gate-test/v1/queries" -H "X-Test-IP: 10.0.45.1" -H "X-Nova-Gate-Key: $KEY2"
check "AC-45/61 right key: state open_key" "open_key" "$(json_get "$TMP/q45a.body" state)"
# v0.2.1 (fx review G2): a right key re-reads the tries row once from the database, so a block
# that the cached copy missed still refuses the key. One SELECT, no write, no lock.
check "AC-45/61 right key from a non-blocked address: one fresh tries read, no write" "[\"SELECT option_value FROM wp_options WHERE option_name = 'nova_gate_tries' LIMIT 1\"]" "$(json_get "$TMP/q45a.body" hits)"
req q45b GET "$BASE/wp-json/nova-gate-test/v1/queries" -H "X-Test-IP: 10.0.45.1"
check "AC-45/61 no header: no Nova Gate query, no write" "[]" "$(json_get "$TMP/q45b.body" hits)"
req q45c GET "$BASE/wp-json/nova-gate-test/v1/queries" -H "X-Test-IP: 10.0.45.1" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
echo "      (queries on a wrong try: $(json_get "$TMP/q45c.body" hits))"
check "AC-61 a wrong try causes exactly one option write" "1" "$(json_get "$TMP/q45c.body" writes)"
req q45d GET "$BASE/wp-json/nova-gate-test/v1/queries" -H "X-Test-IP: 10.0.45.1" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"
check "AC-61 a second wrong try causes exactly one option write (UPDATE)" "1" "$(json_get "$TMP/q45d.body" writes)"
check "AC-59 the CLI class is not loaded on a web request" "false" "$(json_get "$TMP/q45d.body" cli_loaded)"
check "AC-47 two wrong requests = two tries (status recomputed inside, counted once)" "2" "$(tries_sum_n)"
clear_tries

echo "== AC-49 constants: NOVA_GATE_MAX_TRIES and the address fallback (REMOTE_ADDR path)"
wpq config set NOVA_GATE_MAX_TRIES 3 --type=constant --raw >/dev/null
# No test header at all: the address is REMOTE_ADDR (the Docker gateway, the same for every host request).
for i in 1 2 3; do mcp "w49_$i" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"; done
check "AC-49 max 3: blocked after three wrong tries on the REMOTE_ADDR path" "1" "$(blocked_count)"
mcp w49_right -H "X-Nova-Gate-Key: $KEY2"
check "AC-49/41 REMOTE_ADDR path: the right key without a test header is refused while blocked" "404" "$CODE"
status s49 -H "X-Nova-Gate-Key: $KEY2"
check "AC-49 REMOTE_ADDR path: status blocked" "blocked" "$(json_get "$TMP/s49.body" key_header)"
mcp w49_other -H "X-Test-IP: 10.0.49.9" -H "X-Nova-Gate-Key: $KEY2"
check "AC-43 a test address is not blocked by the REMOTE_ADDR block" "200" "$CODE"
clear_tries
# A test header that is not a valid IP falls back to REMOTE_ADDR.
for i in 1 2 3; do mcp "w49b_$i" -H "X-Test-IP: not-an-ip" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"; done
mcp w49b_right -H "X-Nova-Gate-Key: $KEY2"
check "AC-49 an invalid test header falls back to REMOTE_ADDR (blocked without header)" "404" "$CODE"
clear_tries
check "AC-49 client_address with no REMOTE_ADDR at all is unknown" "unknown" "$(wpq eval 'unset($_SERVER["REMOTE_ADDR"]); echo NovaGate\client_address();' | tr -d '[:space:]')"
check "AC-49 client_address uses REMOTE_ADDR when valid" "203.0.113.7" "$(wpq eval '$_SERVER["REMOTE_ADDR"] = "203.0.113.7"; echo NovaGate\client_address();' | tr -d '[:space:]')"
check "AC-49 (fx-01) a test header from an untrusted peer is ignored" "203.0.113.7" "$(wpq eval '$_SERVER["REMOTE_ADDR"] = "203.0.113.7"; $_SERVER["HTTP_X_TEST_IP"] = "2001:db8::1"; echo NovaGate\client_address();' | tr -d '[:space:]')"
check "AC-49 client_address ignores an invalid test header" "203.0.113.7" "$(wpq eval '$_SERVER["REMOTE_ADDR"] = "203.0.113.7"; $_SERVER["HTTP_X_TEST_IP"] = "1.2.3.4, 5.6.7.8"; echo NovaGate\client_address();' | tr -d '[:space:]')"
check "AC-49 client_address with an invalid REMOTE_ADDR is unknown" "unknown" "$(wpq eval '$_SERVER["REMOTE_ADDR"] = "nope"; echo NovaGate\client_address();' | tr -d '[:space:]')"
wpq config set NOVA_GATE_MAX_TRIES 0 --type=constant --raw >/dev/null
for i in 1 2 3 4 5 6 7 8 9 10 11 12; do mcp "w49c_$i" -H "X-Test-IP: 10.0.49.1" -H "X-Nova-Gate-Key: $(wrong_key "$KEY2")"; done
check "AC-49 max 0: nothing is recorded" "no" "$(opt_exists nova_gate_tries)"
mcp w49c_right -H "X-Test-IP: 10.0.49.1" -H "X-Nova-Gate-Key: $KEY2"
check "AC-49 max 0: nobody is blocked" "200" "$CODE"
settings_page "$JAR" "$TMP/page49.html"
check_contains "AC-49 the page says the limit is off" "The wrong-try limit is turned off" "$TMP/page49.html"
wpq config set NOVA_GATE_MAX_TRIES 500 --type=constant --raw >/dev/null
check "AC-49 500 is clamped to 100" "100" "$(wpq eval 'echo NovaGate\max_tries();' | tr -d '[:space:]')"
wpq config set NOVA_GATE_MAX_TRIES '"7"' --type=constant --raw >/dev/null
check "AC-49 a string is not an integer -> 10" "10" "$(wpq eval 'echo NovaGate\max_tries();' | tr -d '[:space:]')"
wpq config delete NOVA_GATE_MAX_TRIES >/dev/null
check "AC-49 not defined -> 10" "10" "$(wpq eval 'echo NovaGate\max_tries();' | tr -d '[:space:]')"

echo "== AC-50 clear blocks (admin) and AC-58 unblock (CLI)"
ten_wrong w50_ "10.0.50.1" "$KEY2"
check "AC-50 setup: one address blocked" "1" "$(blocked_count)"
req c50a POST "$BASE/wp-admin/options-general.php?page=nova-gate" -b "$JAR" --data-urlencode "nova_gate_action=clear_blocks"
check "AC-50 Clear blocks without nonce is refused (403)" "403" "$CODE"
check "AC-50 still blocked without nonce" "1" "$(blocked_count)"
settings_page "$JAR" "$TMP/page50.html"
CNONCE="$(nonce_for clear_blocks "$TMP/page50.html")"
req c50b POST "$BASE/wp-admin/options-general.php?page=nova-gate" -b "$SUBJAR" --data-urlencode "nova_gate_action=clear_blocks" --data-urlencode "nova_gate_nonce=$CNONCE"
check "AC-50 subscriber cannot clear blocks (403)" "403" "$CODE"
check "AC-50 still blocked after subscriber attempt" "1" "$(blocked_count)"
post_action c50c "$JAR" clear_blocks "$CNONCE"
check "AC-50 Clear blocks redirects" "302" "$CODE"
check "AC-50 nova_gate_tries deleted" "no" "$(opt_exists nova_gate_tries)"
mcp m50 -H "X-Test-IP: 10.0.50.1" -H "X-Nova-Gate-Key: $KEY2"
check "AC-50 the cleared address may use the right key again" "200" "$CODE"
ten_wrong w58_ "10.0.58.1" "$KEY2"
ten_wrong w58b_ "10.0.58.2" "$KEY2"
check "AC-58 setup: two addresses blocked" "2" "$(blocked_count)"
check "AC-58 wp nova-gate unblock exits 0" "0" "$(wpx nova-gate unblock)"
check_contains "AC-58 unblock says how many were blocked" "2 address(es) were blocked" "$TMP/wpx.out"
check "AC-58 nova_gate_tries deleted" "no" "$(opt_exists nova_gate_tries)"
mcp m58 -H "X-Test-IP: 10.0.58.1" -H "X-Nova-Gate-Key: $KEY2"
check "AC-58/65 X un-blocked by the CLI -> 200" "200" "$CODE"

# ------------------------------------------------------------------------------ AC-53 .. AC-57 WP-CLI
echo "== AC-53 wp nova-gate status"
check "AC-53 status exits 0" "0" "$(wpx nova-gate status)"
check_contains "AC-53 table: state locked" "locked" "$TMP/wpx.out"
check "AC-53 status --format=json exits 0" "0" "$(wpx nova-gate status --format=json)"
cp "$TMP/wpx.out" "$TMP/st53.json"
check "AC-53 json: state" "locked" "$(json_get "$TMP/st53.json" state)"
check "AC-53 json: key source options" "options" "$(json_get "$TMP/st53.json" key_source)"
check "AC-53 json: number of keys" "1" "$(json_get "$TMP/st53.json" keys)"
check "AC-53 json: early yes" "yes" "$(json_get "$TMP/st53.json" early)"
check "AC-53 json: timer minutes" "15" "$(json_get "$TMP/st53.json" timer_minutes)"
check "AC-53 json: blocked 0" "0" "$(json_get "$TMP/st53.json" blocked)"
check_not_contains "AC-53 status never prints a hash" "$HASH2" "$TMP/st53.json"

echo "== AC-54 wp nova-gate key list"
check "AC-54 key list exits 0" "0" "$(wpx nova-gate key list)"
check_contains "AC-54 table lists the id" "laptop" "$TMP/wpx.out"
check_contains "AC-54 table lists the label" "Laptop" "$TMP/wpx.out"
check_not_contains "AC-54 never a hash" "$HASH2" "$TMP/wpx.out"
check "AC-54 key list --format=json exits 0" "0" "$(wpx nova-gate key list --format=json)"
check "AC-54 json has one row with id, label, created" "id,label,created" "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) && count($d) === 1 ? implode(",", array_keys($d[0])) : "<bad>";' "$TMP/wpx.out")"
check "AC-54 key list --format=csv exits 0" "0" "$(wpx nova-gate key list --format=csv)"
check_contains "AC-54 csv header" "id,label,created" "$TMP/wpx.out"

echo "== AC-55 wp nova-gate key add"
check "AC-55 key add ci --porcelain exits 0" "0" "$(wpx nova-gate key add ci --porcelain)"
KEY_CI="$(tr -d '[:space:]' < "$TMP/wpx.out")"
check "AC-55/65 porcelain prints only a 64-hex key" "yes" "$(printf '%s' "$KEY_CI" | grep -q '^[a-f0-9]\{64\}$' && echo yes || echo no)"
check "AC-55 label defaults to the id" "ci" "$(key_field ci label)"
check "AC-55 only the hash is stored" "$(sha256 "$KEY_CI")" "$(key_field ci hash)"
check "AC-55 key not stored in wp_options" "0" "$(sql "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%$KEY_CI%'")"
mcp m55 -H "X-Nova-Gate-Key: $KEY_CI"
check "AC-55/65 the CLI key opens the gate over HTTP" "200" "$CODE"
check "AC-55 key add with a label exits 0" "0" "$(wpx nova-gate key add hub-2 --label="Second hub")"
check_contains "AC-55 non-porcelain output shows the key once" "Copy it now" "$TMP/wpx.out"
KEY_H2="$(grep -o '^[a-f0-9]\{64\}$' "$TMP/wpx.out" | head -1)"
check "AC-55 key printed on its own line" "yes" "$([ -n "$KEY_H2" ] && echo yes || echo no)"
check "AC-55 label stored" "Second hub" "$(key_field hub-2 label)"
KEY_H3="$(php -r 'echo bin2hex(random_bytes(32));')"
check "AC-55 key add --hash exits 0" "0" "$(wpx nova-gate key add hashed --hash="$(sha256 "$KEY_H3")")"
check_not_contains "AC-55 --hash prints no key" "$KEY_H3" "$TMP/wpx.out"
check "AC-55 --hash stores that hash" "$(sha256 "$KEY_H3")" "$(key_field hashed hash)"
mcp m55b -H "X-Nova-Gate-Key: $KEY_H3"
check "AC-55 the key of the given hash opens" "200" "$CODE"
KEYS_BEFORE="$(keys_json)"
check "AC-55 existing id -> exit 1" "1" "$(wpx nova-gate key add ci)"
check_contains "AC-55 existing id message" "already exists" "$TMP/wpx.err"
check "AC-55 bad id (upper case) -> exit 1" "1" "$(wpx nova-gate key add Hub)"
check "AC-55 bad id (underscore) -> exit 1" "1" "$(wpx nova-gate key add a_b)"
check "AC-55 bad id (41 chars) -> exit 1" "1" "$(wpx nova-gate key add "$(printf 'a%.0s' $(seq 1 41))")"
check "AC-55 bad hash -> exit 1" "1" "$(wpx nova-gate key add badhash --hash=abc)"
check "AC-55 refusals change nothing" "$KEYS_BEFORE" "$(keys_json)"
check "AC-55 ids after adds" "laptop,ci,hub-2,hashed" "$(key_ids)"

echo "== AC-38 the 21st key is refused (CLI and admin)"
for i in $(seq 5 20); do wpx nova-gate key add "k$i" --hash="$(sha256 "k$i")" >/dev/null; done
check "AC-38 setup: 20 keys" "20" "$(keys_json > "$TMP/k20.json"; json_count "$TMP/k20.json")"
check "AC-38 CLI 21st key -> exit 1" "1" "$(wpx nova-gate key add k21)"
check_contains "AC-38 CLI message names the maximum" "20 keys" "$TMP/wpx.err"
add_key_ui add21 "$JAR" "Twenty one"
check "AC-38 admin 21st key refused with a message" "yes" "$(grep -qi 'nova_gate_msg=full' "$TMP/add21.head" && echo yes || echo no)"
check "AC-38 nothing changes" "20" "$(keys_json > "$TMP/k20b.json"; json_count "$TMP/k20b.json")"
req msg_full GET "$BASE/wp-admin/options-general.php?page=nova-gate&nova_gate_msg=full" -b "$JAR"
check_contains "AC-38 full message rendered" "the maximum" "$TMP/msg_full.body"
mcp m38 -H "X-Nova-Gate-Key: $KEY_CI"
check "AC-32 with 20 keys, a key in the middle still opens" "200" "$CODE"
for i in $(seq 5 20); do wpx nova-gate key remove "k$i" --yes >/dev/null; done
check "AC-38 cleanup: 4 keys" "laptop,ci,hub-2,hashed" "$(key_ids)"

echo "== AC-56 wp nova-gate key remove"
check "AC-56 unknown id -> exit 1" "1" "$(wpx nova-gate key remove nope)"
check_contains "AC-56 unknown id message" "no key with the id" "$TMP/wpx.err"
check "AC-56/65 key remove ci --yes exits 0" "0" "$(wpx nova-gate key remove ci --yes)"
mcp m56 -H "X-Test-IP: 10.0.56.1" -H "X-Nova-Gate-Key: $KEY_CI"
check "AC-56/65 the removed CLI key -> 404" "404" "$CODE"
check "AC-56 ids after remove" "laptop,hub-2,hashed" "$(key_ids)"
wpx nova-gate key remove hub-2 --yes >/dev/null
wpx nova-gate key remove hashed --yes >/dev/null
check "AC-56 one key left" "laptop" "$(key_ids)"
wpx nova-gate key remove laptop </dev/null >/dev/null
check "AC-56 removing the last key without --yes asks for confirmation" "yes" "$(grep -q 'y/n' "$TMP/wpx.out" && echo yes || echo no)"
check "AC-56 the last key is still there after the unanswered confirmation" "laptop" "$(key_ids)"

echo "== AC-57 wp nova-gate open / lock"
NOW="$(date +%s)"
check "AC-57 open --minutes=7 exits 0" "0" "$(wpx nova-gate open --minutes=7)"
UNTIL="$(opt nova_gate_open_until | tr -d '[:space:]')"
check_between "AC-57 open_until is now + 7 minutes" "$((NOW + 420 - 60))" "$((NOW + 420 + 60))" "$UNTIL"
wpq option get nova_gate_last_open --format=json > "$TMP/last_open57.json"
check "AC-57 last_open user is 0" "0" "$(json_get "$TMP/last_open57.json" user)"
mcp m57
check "AC-57 MCP without key -> 200 during the CLI open" "200" "$CODE"
check "AC-57 database still 0" "0" "$(db_enabled)"
settings_page "$JAR" "$TMP/page57.html"
check_contains "AC-57 settings page shows WP-CLI as the opener" "WP-CLI on" "$TMP/page57.html"
check "AC-53 status says open until" "0" "$(wpx nova-gate status --format=json)"
check "AC-53 json state open until" "yes" "$(grep -q '"state":"open until ' "$TMP/wpx.out" && echo yes || echo no)"
check "AC-57 lock exits 0" "0" "$(wpx nova-gate lock)"
check "AC-57 lock removes open_until" "no" "$(opt_exists nova_gate_open_until)"
mcp m57b
check "AC-57 MCP without key -> 404 after lock" "404" "$CODE"
NOW="$(date +%s)"
check "AC-57 open without --minutes exits 0" "0" "$(wpx nova-gate open)"
UNTIL="$(opt nova_gate_open_until | tr -d '[:space:]')"
check_between "AC-57 default is the saved timer (15 min)" "$((NOW + 900 - 60))" "$((NOW + 900 + 60))" "$UNTIL"
check "AC-57 open --minutes=1000 exits 0" "0" "$(wpx nova-gate open --minutes=1000)"
UNTIL="$(opt nova_gate_open_until | tr -d '[:space:]')"
check_between "AC-57 minutes clamped to 240" "$((NOW + 14400 - 60))" "$((NOW + 14400 + 60))" "$UNTIL"
wpx nova-gate lock >/dev/null

# ------------------------------------------------------------------------------ AC-33 / AC-37 v0.1.0 site
echo "== AC-33 v0.1.0 site: only nova_gate_key_hash exists"
wpx nova-gate key remove laptop --yes >/dev/null
check "AC-33 setup: no keys option" "no" "$(opt_exists nova_gate_keys)"
wpq option update nova_gate_key_hash "$HASH" >/dev/null
status s33
check "AC-33 active with only the v0.1.0 option" "true" "$(json_get "$TMP/s33.body" active)"
mcp m33 -H "X-Nova-Gate-Key: $KEY"
check "AC-33 the v0.1.0 key still opens with no action" "200" "$CODE"
check "AC-33 no fold-in on a normal request" "no" "$(opt_exists nova_gate_keys)"
check "AC-33 CLI key list exits 0" "0" "$(wpx nova-gate key list --format=json)"
check "AC-33 CLI key list shows default / Default key" "default|Default key" "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo ($d[0]["id"] ?? "?") . "|" . ($d[0]["label"] ?? "?");' "$TMP/wpx.out")"
settings_page "$JAR" "$TMP/page33.html"
check_contains "AC-33 admin table shows default" "<code>default</code>" "$TMP/page33.html"
check_contains "AC-33 admin table shows Default key" "<td>Default key</td>" "$TMP/page33.html"
check "AC-33 still no fold-in after listing" "no" "$(opt_exists nova_gate_keys)"
check "AC-53 key count with the v0.1.0 option" "1" "$(wpx nova-gate status --format=json >/dev/null; json_get "$TMP/wpx.out" keys)"

echo "== AC-37 fold-in on the first add"
add_key_ui add37 "$JAR" "New tool"
KEY37="$KEY_OUT"
check "AC-37 new key shown" "yes" "$([ -n "$KEY37" ] && echo yes || echo no)"
check "AC-37 nova_gate_key_hash deleted" "no" "$(opt_exists nova_gate_key_hash)"
check "AC-37 keys: default and new-tool" "default,new-tool" "$(key_ids)"
check "AC-37 default holds the old hash" "$HASH" "$(key_field default hash)"
check "AC-37 default label" "Default key" "$(key_field default label)"
check "AC-37 default created 0" "0" "$(key_field default created)"
mcp m37a -H "X-Nova-Gate-Key: $KEY"
check "AC-37 the old key keeps working" "200" "$CODE"
mcp m37b -H "X-Nova-Gate-Key: $KEY37"
check "AC-37 the new key works" "200" "$CODE"
remove_key_ui rm37 "$JAR" default
check "AC-37 default removed" "new-tool" "$(key_ids)"
mcp m37c -H "X-Test-IP: 10.0.37.1" -H "X-Nova-Gate-Key: $KEY"
check "AC-37 the old key is gone after removing default" "404" "$CODE"
echo "== AC-37 fold-in on a remove with only the v0.1.0 option"
wpx nova-gate key remove new-tool --yes >/dev/null
wpq option update nova_gate_key_hash "$HASH" >/dev/null
check "AC-37 CLI remove default exits 0" "0" "$(wpx nova-gate key remove default --yes)"
check "AC-36/37 nova_gate_key_hash deleted" "no" "$(opt_exists nova_gate_key_hash)"
check "AC-36/37 nova_gate_keys absent" "no" "$(opt_exists nova_gate_keys)"
status s37
check "AC-36 gate inactive" "false" "$(json_get "$TMP/s37.body" active)"
clear_tries

# ------------------------------------------------------------------------------ integrator checks
# Written by the session model after reading the build. Starts and ends with no key at all.
cli_ids() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? implode(",", array_column($d, "id")) : "<not json>";' "$TMP/wpx.out"; }
cli_key() { tr -d '[:space:]' < "$TMP/wpx.out"; }

echo "== INT all-digit key ids are normal keys (integrator)"
check "INT setup: no key before this section" "no|no" "$(opt_exists nova_gate_keys)|$(opt_exists nova_gate_key_hash)"
check "INT add an all-digit id by CLI" "0" "$(wpx nova-gate key add 2026 --porcelain)"
KEY_D="$(cli_key)"
check "INT the all-digit id is listed" "0|2026" "$(wpx nova-gate key list --format=json)|$(cli_ids)"
mcp int1 -H "X-Test-IP: 10.9.0.1" -H "X-Nova-Gate-Key: $KEY_D"
check "INT the all-digit key opens" "200" "$CODE"
add_key_ui int2 "$JAR" "2027"
KEY_D2="$KEY_OUT"
check "INT admin label 2027 gives id 2027 and keeps 2026" "2026,2027" "$(key_ids)"
settings_page "$JAR" "$TMP/int3.html"
check_contains "INT admin table shows 2026" "<code>2026</code>" "$TMP/int3.html"
check_contains "INT admin table shows 2027" "<code>2027</code>" "$TMP/int3.html"
mcp int4 -H "X-Test-IP: 10.9.0.1" -H "X-Nova-Gate-Key: $KEY_D"
check "INT the first all-digit key still opens after a second add" "200" "$CODE"
check "INT a duplicate all-digit id is refused" "1" "$(wpx nova-gate key add 2026)"
check "INT remove the all-digit id by CLI" "0" "$(wpx nova-gate key remove 2026)"
mcp int5 -H "X-Test-IP: 10.9.0.1" -H "X-Nova-Gate-Key: $KEY_D"
check "INT the removed all-digit key is locked" "404" "$CODE"
mcp int6 -H "X-Test-IP: 10.9.0.1" -H "X-Nova-Gate-Key: $KEY_D2"
check "INT the other all-digit key still opens" "200" "$CODE"
remove_key_ui int7 "$JAR" 2027
check "INT remove the last all-digit id by admin" "no" "$(opt_exists nova_gate_keys)"
clear_tries

echo "== INT a v0.1.0 hash next to the key table is listed, never hidden (integrator)"
wpx nova-gate key add hub1 --porcelain >/dev/null
KEY_T="$(cli_key)"
wpq option update nova_gate_key_hash "$HASH" >/dev/null
mcp int10 -H "X-Test-IP: 10.9.1.1" -H "X-Nova-Gate-Key: $KEY"
check "INT the v0.1.0 key opens next to the table" "200" "$CODE"
check "INT and it is listed as default" "0|hub1,default" "$(wpx nova-gate key list --format=json)|$(cli_ids)"
check "INT status counts both keys" "2" "$(wpx nova-gate status --format=json >/dev/null; json_get "$TMP/wpx.out" keys)"
settings_page "$JAR" "$TMP/int11.html"
check_contains "INT admin table shows default" "<code>default</code>" "$TMP/int11.html"
check "INT remove default" "0" "$(wpx nova-gate key remove default)"
check "INT the v0.1.0 option is deleted" "no" "$(opt_exists nova_gate_key_hash)"
mcp int12 -H "X-Test-IP: 10.9.1.1" -H "X-Nova-Gate-Key: $KEY"
check "INT the v0.1.0 key is locked after its removal" "404" "$CODE"
mcp int13 -H "X-Test-IP: 10.9.1.1" -H "X-Nova-Gate-Key: $KEY_T"
check "INT the table key still opens" "200" "$CODE"

wpx nova-gate key add default --porcelain >/dev/null
KEY_DEF="$(cli_key)"
wpq option update nova_gate_key_hash "$HASH" >/dev/null
check "INT with default taken, the v0.1.0 hash is listed as legacy" "0|hub1,default,legacy" "$(wpx nova-gate key list --format=json)|$(cli_ids)"
mcp int14 -H "X-Test-IP: 10.9.1.1" -H "X-Nova-Gate-Key: $KEY"
check "INT the legacy key opens" "200" "$CODE"
check "INT remove legacy" "0" "$(wpx nova-gate key remove legacy)"
check "INT the v0.1.0 option is deleted again" "no" "$(opt_exists nova_gate_key_hash)"
mcp int15 -H "X-Test-IP: 10.9.1.1" -H "X-Nova-Gate-Key: $KEY"
check "INT the legacy key is locked after its removal" "404" "$CODE"
mcp int16 -H "X-Test-IP: 10.9.1.1" -H "X-Nova-Gate-Key: $KEY_DEF"
check "INT the table's own default still opens" "200" "$CODE"

wpq option update nova_gate_key_hash "$(sha256 "$KEY_T")" >/dev/null
check "INT the same hash in both places is one key" "0|hub1,default" "$(wpx nova-gate key list --format=json)|$(cli_ids)"
check "INT remove hub1" "0" "$(wpx nova-gate key remove hub1)"
check "INT its v0.1.0 copy is deleted with it" "no" "$(opt_exists nova_gate_key_hash)"
mcp int17 -H "X-Test-IP: 10.9.1.1" -H "X-Nova-Gate-Key: $KEY_T"
check "INT hub1's key is locked everywhere" "404" "$CODE"
check "INT remove the last key" "0" "$(wpx nova-gate key remove default --yes)"
check "INT no key is left" "no|no" "$(opt_exists nova_gate_keys)|$(opt_exists nova_gate_key_hash)"
clear_tries

echo "== INT malformed keys, the front page and the status route all count (integrator)"
wpx nova-gate key add odd --porcelain >/dev/null
KEY_O="$(cli_key)"
for i in 1 2 3 4 5 6 7 8 9 10; do
    req "int20_$i" GET "$BASE/" -H "X-Test-IP: 10.9.2.1" -H "X-Nova-Gate-Key: x"
done
status int21 -H "X-Test-IP: 10.9.2.1" -H "X-Nova-Gate-Key: $KEY_O"
check "INT ten malformed keys on the front page block the address" "blocked" "$(json_get "$TMP/int21.body" key_header)"
for i in 1 2 3 4 5 6 7 8 9 10; do
    status "int22_$i" -H "X-Test-IP: 2001:db8::9" -H "X-Nova-Gate-Key: $(wrong_key "$KEY_O")"
done
check "INT the tenth wrong key on the status route still says wrong" "wrong" "$(json_get "$TMP/int22_10.body" key_header)"
mcp int23 -H "X-Test-IP: 2001:db8::9" -H "X-Nova-Gate-Key: $KEY_O"
check "INT ten wrong keys on the status route block an IPv6 address" "404" "$CODE"
status int24 -H "X-Test-IP: 10.9.2.4" -H "X-Nova-Gate-Key: $KEY_O"
check "INT a third address is not affected" "ok" "$(json_get "$TMP/int24.body" key_header)"
status int26 -H "X-Test-IP: 10.9.2.5" -H "X-Nova-Gate-Key: $(printf '%%%.0s' $(seq 1 200))"
check "INT a 200-character junk key is judged wrong" "wrong" "$(json_get "$TMP/int26.body" key_header)"
check "INT exactly two addresses are blocked" "2" "$(blocked_count)"
check "INT twenty-one tries are on record, none for the right key" "21" "$(tries_sum_n)"
wpq option get nova_gate_tries --format=json > "$TMP/int25.json" 2>/dev/null
check_not_contains "INT no IPv4 address in the stored table" "10.9.2.1" "$TMP/int25.json"
check_not_contains "INT no IPv6 address in the stored table" "2001:db8" "$TMP/int25.json"
wpx nova-gate key remove odd --yes >/dev/null
check "INT section ends with no key" "no|no" "$(opt_exists nova_gate_keys)|$(opt_exists nova_gate_key_hash)"
clear_tries

echo "== INT AC-35 the id is exactly sanitize_title(label) (integrator, review round 1)"
add_key_ui int30 "$JAR" "Hello_World"
check "INT a label with an underscore is refused, not renamed" "yes|no" "$(grep -qi 'nova_gate_msg=bad_id' "$TMP/int30.head" && echo yes || echo no)|$(opt_exists nova_gate_keys)"
add_key_ui int31 "$JAR" "hub-東京"
check "INT a label with non-Latin letters is refused, not shortened" "yes|no" "$(grep -qi 'nova_gate_msg=bad_id' "$TMP/int31.head" && echo yes || echo no)|$(opt_exists nova_gate_keys)"
clear_tries

# ------------------------------------------------------------------------------ AC-16 / AC-39
echo "== AC-39 wp-config constant with several hashes (changes AC-16)"
add_key_ui add39 "$JAR" "Option key"
KEY_OPT="$KEY_OUT"
KEY3="$(php -r 'echo bin2hex(random_bytes(32));')"
KEY4="$(php -r 'echo bin2hex(random_bytes(32));')"
HASH3="$(sha256 "$KEY3")"
HASH4="$(sha256 "$KEY4")"
wpq config set NOVA_GATE_KEY_HASH "$HASH3" --type=constant >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
status s16
check "AC-16 active with one constant hash" "true" "$(json_get "$TMP/s16.body" active)"
mcp m16a -H "X-Nova-Gate-Key: $KEY3"
check "AC-16 constant key -> 200" "200" "$CODE"
mcp m16b -H "X-Test-IP: 10.0.16.1" -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-16/39 option key ignored while constant is set -> 404" "404" "$CODE"
wpq config set NOVA_GATE_KEY_HASH "$HASH3, $HASH4" --type=constant >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
mcp m39a -H "X-Nova-Gate-Key: $KEY3"
check "AC-39 first hash of a comma list -> 200" "200" "$CODE"
mcp m39b -H "X-Nova-Gate-Key: $KEY4"
check "AC-39 second hash of a comma list -> 200" "200" "$CODE"
wpq config set NOVA_GATE_KEY_HASH "[ '$HASH4', '$HASH3' ]" --type=constant --raw >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
mcp m39c -H "X-Nova-Gate-Key: $KEY3"
check "AC-39 array constant: key 3 -> 200" "200" "$CODE"
mcp m39d -H "X-Nova-Gate-Key: $KEY4"
check "AC-39 array constant: key 4 -> 200" "200" "$CODE"
mcp m39e -H "X-Test-IP: 10.0.39.1" -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-39 array constant: option key ignored" "404" "$CODE"
settings_page "$JAR" "$TMP/page5.html"
check_contains "AC-16/39 page says the hashes come from wp-config.php" "wp-config.php as NOVA_GATE_KEY_HASH" "$TMP/page5.html"
check_http_not_contains "AC-16/39 no Add key form" 'value="add"' "$TMP/page5.html" "$PAGE_CODE"
check_http_not_contains "AC-16/39 no Remove button" 'value="remove"' "$TMP/page5.html" "$PAGE_CODE"
check_http_not_contains "AC-24 constant hash not in page source" "$HASH3" "$TMP/page5.html" "$PAGE_CODE"
check "AC-39 CLI key add refuses with the constant (exit 1)" "1" "$(wpx nova-gate key add viaconst)"
check_contains "AC-39 CLI message names wp-config" "wp-config.php" "$TMP/wpx.err"
check "AC-39 CLI key remove refuses with the constant (exit 1)" "1" "$(wpx nova-gate key remove option-key --yes)"
check "AC-39 option key untouched" "option-key" "$(key_ids)"
check "AC-53 status key source wp-config" "wp-config" "$(wpx nova-gate status --format=json >/dev/null; json_get "$TMP/wpx.out" key_source)"
check "AC-53 status counts the constant hashes" "2" "$(json_get "$TMP/wpx.out" keys)"
wpq config set NOVA_GATE_KEY_HASH "not a hash, zzz" --type=constant >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
status s39junk
check "AC-39 junk constant: gate active" "true" "$(json_get "$TMP/s39junk.body" active)"
mcp m39junk -H "X-Test-IP: 10.0.39.2" -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-39 junk constant: option key does not match" "404" "$CODE"
mcp m39junk2 -H "X-Test-IP: 10.0.39.2" -H "X-Nova-Gate-Key: $KEY3"
check "AC-39 junk constant: no key matches" "404" "$CODE"
wpq config set NOVA_GATE_KEY_HASH "''" --type=constant --raw >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
status s39empty
check "AC-39 empty constant: gate active" "true" "$(json_get "$TMP/s39empty.body" active)"
mcp m39empty -H "X-Test-IP: 10.0.39.3" -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-39 empty constant: no key matches" "404" "$CODE"
wpq config delete NOVA_GATE_KEY_HASH >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
status s16b
check "AC-16 constant removed, option keys active again" "true" "$(json_get "$TMP/s16b.body" active)"
mcp m16c -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-16 option key works again" "200" "$CODE"
clear_tries

# ------------------------------------------------------------------------------ AC-40
echo "== AC-40 broken storage stays locked"
wpq option update nova_gate_keys "oops" >/dev/null
status s40a
check "AC-40 keys option is a string: gate active" "true" "$(json_get "$TMP/s40a.body" active)"
mcp m40a -H "X-Test-IP: 10.0.40.1" -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-40 keys option is a string: right key -> 404" "404" "$CODE"
wpq eval 'update_option("nova_gate_keys", [], true);' >/dev/null
status s40b
check "AC-40 empty array: gate active" "true" "$(json_get "$TMP/s40b.body" active)"
mcp m40b -H "X-Test-IP: 10.0.40.1" -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-40 empty array: right key -> 404" "404" "$CODE"
wpq eval "update_option('nova_gate_keys', ['a' => ['label' => 'A', 'hash' => 'nothex', 'created' => 1], 'b' => ['label' => 'B', 'hash' => strtoupper('$(sha256 "$KEY_OPT")'), 'created' => 1], 'c' => 'junk'], true);" >/dev/null
status s40c
check "AC-40 entries without a valid hash: gate active" "true" "$(json_get "$TMP/s40c.body" active)"
mcp m40c -H "X-Test-IP: 10.0.40.1" -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-40 upper-case digest never matches -> 404" "404" "$CODE"
mcp m40d -H "X-Test-IP: 10.0.40.1" -H "X-Nova-Gate-Key: nothex"
check "AC-40 the junk 'hash' never matches as a key -> 404" "404" "$CODE"
check "AC-40 database still 0" "0" "$(db_enabled)"
wpq eval "update_option('nova_gate_keys', ['option-key' => ['label' => 'Option key', 'hash' => '$(sha256 "$KEY_OPT")', 'created' => 1]], true);" >/dev/null
mcp m40e -H "X-Nova-Gate-Key: $KEY_OPT"
check "AC-40 restored: right key -> 200" "200" "$CODE"
clear_tries

# ------------------------------------------------------------------------------ AC-19
echo "== AC-19 late load (double loaded from an mu-plugin, before Nova Gate)"
wpq option update nova_gate_test_late 1 >/dev/null
status s19
check "AC-19 early is false" "false" "$(json_get "$TMP/s19.body" early)"
check "AC-19 active still true" "true" "$(json_get "$TMP/s19.body" active)"
req u19 POST "$BASE/wp-json/novamira/v1/upload"
check "AC-19 late boot (fx-02): link route refused 404" "404" "$CODE"
req a19 POST "$BASE/wp-json/novamira/v1/abilities/a/b/run"
check "AC-19 late boot (fx-02): ability run refused 404" "404" "$CODE"
settings_page "$JAR" "$TMP/page6.html"
check_contains "AC-19 late-load warning for admins" "Nova Gate loaded after Novamira" "$TMP/page6.html"
check_contains "AC-19 warning explains the fix" "must-use plugin loader" "$TMP/page6.html"
check "AC-53 status early no" "no" "$(wpx nova-gate status --format=json >/dev/null; json_get "$TMP/wpx.out" early)"
wpq option delete nova_gate_test_late >/dev/null
status s19b
check "AC-19 early true again" "true" "$(json_get "$TMP/s19b.body" early)"

# ------------------------------------------------------------------------------ AC-26 / AC-61
echo "== AC-26/61 footprint"
check "AC-26 no outgoing HTTP calls in plugin code" "0" "$(grep -rEl 'wp_remote_|curl_init|curl_exec|fsockopen|file_get_contents\(\s*.http' nova-gate.php uninstall.php includes/ | wc -l | tr -d ' ')"
check "AC-26 no runtime Composer autoload" "0" "$(grep -rl 'vendor/autoload' nova-gate.php uninstall.php includes/ | wc -l | tr -d ' ')"
check "AC-59 class-cli.php is required only inside the WP_CLI guard" "1" "$(grep -c "defined('WP_CLI') && WP_CLI" nova-gate.php | tr -d ' ')"
check "AC-59 class-cli.php exits without WP_CLI" "1" "$(grep -c "!defined('WP_CLI') || !WP_CLI" includes/class-cli.php | tr -d ' ')"
check "AC-61 the early decision never calls wp_salt or the current user" "0" "$(grep -cE 'wp_salt\(|wp_get_current_user\(' includes/class-limiter.php includes/class-gate.php | awk -F: '{s+=$2} END {print s+0}')"
req qs GET "$BASE/wp-json/nova-gate-test/v1/queries"
echo "      (queries touching nova_gate/novamira_ai on a normal request, gate locked: $(json_get "$TMP/qs.body" hits))"
check "AC-26 no Nova Gate query beyond autoloaded options on a locked request" "[]" "$(json_get "$TMP/qs.body" hits)"

# ------------------------------------------------------------------------------ AC-36 (ported AC-15)
echo "== AC-36 remove the last key (ported AC-15)"
req t7 GET "$BASE/wp-admin/admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=on&_wpnonce=$TOGGLE_NONCE" -b "$JAR"
check "AC-36 setup: open" "yes" "$(opt_exists nova_gate_open_until)"
remove_key_ui rem1 "$JAR" option-key
check "AC-36 remove redirects" "302" "$CODE"
check "AC-36 message: last key removed" "yes" "$(grep -qi 'nova_gate_msg=removed_last' "$TMP/rem1.head" && echo yes || echo no)"
check "AC-36 nova_gate_keys deleted" "no" "$(opt_exists nova_gate_keys)"
check "AC-36 nova_gate_key_hash absent" "no" "$(opt_exists nova_gate_key_hash)"
check "AC-36 open_until deleted" "no" "$(opt_exists nova_gate_open_until)"
status s15
check "AC-36 gate inactive" "false" "$(json_get "$TMP/s15.body" active)"
check "AC-36 database switch still 0" "0" "$(db_enabled)"
mcp m15
check "AC-36 MCP -> 404 (switch off in the database)" "404" "$CODE"
check "AC-57 open with the gate inactive -> exit 1" "1" "$(wpx nova-gate open)"
check "AC-57 nothing opened" "no" "$(opt_exists nova_gate_open_until)"
check "AC-53 status with no key: inactive / none / 0" "inactive|none|0" "$(wpx nova-gate status --format=json >/dev/null; echo "$(json_get "$TMP/wpx.out" state)|$(json_get "$TMP/wpx.out" key_source)|$(json_get "$TMP/wpx.out" keys)")"
req msg_rl GET "$BASE/wp-admin/options-general.php?page=nova-gate&nova_gate_msg=removed_last" -b "$JAR"
check_contains "AC-36 message rendered" "Last key removed. The gate is inactive." "$TMP/msg_rl.body"

# ------------------------------------------------------------------------------ AC-20 / AC-25 / AC-31 / AC-60
echo "== AC-60 deactivation and uninstall (changes AC-25); AC-20/AC-31 removal is safe"
wpq option update nova_gate_key_hash "$HASH2" >/dev/null
wpq eval "update_option('nova_gate_keys', ['laptop' => ['label' => 'Laptop', 'hash' => '$HASH2', 'created' => 1]], true);" >/dev/null
wpq eval 'update_option("nova_gate_tries", ["0123456789abcdef" => ["n" => 10, "first" => time(), "last" => time(), "until" => time() + 900]], true);' >/dev/null
wpq option update nova_gate_open_until 4102444800 >/dev/null
wpq option update nova_gate_timer_minutes 42 >/dev/null
wpq eval 'update_option("nova_gate_last_open", ["user" => 1, "time" => 1700000000]);' >/dev/null
mcp m25a
check "AC-25 setup: manually open -> 200" "200" "$CODE"
wpq plugin deactivate nova-gate >/dev/null
check "AC-25 deactivation deletes open_until" "no" "$(opt_exists nova_gate_open_until)"
check "AC-25 deactivation keeps key_hash" "$HASH2" "$(opt nova_gate_key_hash)"
check "AC-60 deactivation keeps keys" "yes" "$(opt_exists nova_gate_keys)"
check "AC-60 deactivation keeps tries" "yes" "$(opt_exists nova_gate_tries)"
check "AC-25 deactivation keeps timer" "42" "$(opt nova_gate_timer_minutes)"
check "AC-25 deactivation keeps last_open" "yes" "$(opt_exists nova_gate_last_open)"
mcp m31
check "AC-31/AC-20 MCP -> 404 with Nova Gate deactivated" "404" "$CODE"
check "AC-31/AC-20 database switch is 0" "0" "$(db_enabled)"
status s31
check "AC-31 status route gone while deactivated" "404" "$CODE"
wpq plugin activate nova-gate >/dev/null
status s31b
check "AC-31 reactivated" "true" "$(json_get "$TMP/s31b.body" active)"
wpq option update nova_gate_open_until 4102444800 >/dev/null
db_set_enabled_raw 0 >/dev/null
sql "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('novamira_ai_abilities_domain', 'keep-me', 'yes') ON DUPLICATE KEY UPDATE option_value='keep-me'" >/dev/null
wpq option add nova_gate_salt test-salt-for-uninstall >/dev/null 2>&1 # NONCE_SALT exists here, so the row is made by hand
check "AC-60 setup: nova_gate_salt exists before uninstall" "yes" "$(opt_exists nova_gate_salt)"
wpq eval 'define("WP_UNINSTALL_PLUGIN", true); include WP_PLUGIN_DIR . "/nova-gate/uninstall.php"; echo "uninstalled";' >/dev/null
for o in nova_gate_key_hash nova_gate_keys nova_gate_tries nova_gate_open_until nova_gate_timer_minutes nova_gate_last_open nova_gate_salt; do
    check "AC-60 uninstall deletes $o" "no" "$(opt_exists "$o")"
done
check "AC-25 uninstall leaves novamira_ai_abilities_enabled" "0" "$(db_enabled)"
check "AC-25 uninstall leaves novamira_ai_abilities_domain" "keep-me" "$(sql "SELECT option_value FROM wp_options WHERE option_name='novamira_ai_abilities_domain'")"
sql "DELETE FROM wp_options WHERE option_name='novamira_ai_abilities_domain'" >/dev/null

# ------------------------------------------------------------------------------ AC-63 version and docs
echo "== AC-63 version and docs"
check "AC-63 plugin header Version 0.2.2" "1" "$(grep -c '^ \* Version: 0.2.2$' nova-gate.php | tr -d ' ')"
check "AC-63 NOVA_GATE_VERSION 0.2.2" "1" "$(grep -c "define('NOVA_GATE_VERSION', '0.2.2')" nova-gate.php | tr -d ' ')"
check "AC-63 readme.txt stable tag 0.2.2" "1" "$(grep -c '^Stable tag: 0.2.2$' readme.txt | tr -d ' ')"
check "AC-63 readme.txt changelog entry" "1" "$(grep -c '^= 0.2.2 =$' readme.txt | tr -d ' ')"
for f in README.md readme.txt; do
    check_contains "AC-63 $f: several keys" "20" "$f"
    check_contains "AC-63 $f: limit numbers" "10 wrong keys within 10 minutes" "$f"
    check_contains "AC-63 $f: blocked for 15 minutes" "15 minutes" "$f"
    check_contains "AC-63 $f: right key is also refused" "also" "$f"
    check_contains "AC-63 $f: how to clear" "unblock" "$f"
    check_contains "AC-63 $f: NOVA_GATE_MAX_TRIES" "NOVA_GATE_MAX_TRIES" "$f"
    check_contains "AC-63 $f: NOVA_GATE_IP_HEADER" "NOVA_GATE_IP_HEADER" "$f"
    check_contains "AC-63 $f: proxy warning" "reverse proxy" "$f"
    check_contains "AC-63 $f: HTTP_CF_CONNECTING_IP" "HTTP_CF_CONNECTING_IP" "$f"
    for cmd in "nova-gate status" "nova-gate key list" "nova-gate key add" "nova-gate key remove" "nova-gate open" "nova-gate lock" "nova-gate unblock"; do
        check_contains "AC-63 $f: wp $cmd" "$cmd" "$f"
    done
    check_not_contains "AC-63 $f: no 'More than one key' not-supported line" "More than one key per site" "$f"
    check_not_contains "AC-63 $f: no 'WP-CLI commands' not-supported line" "WP-CLI commands. Rate limiting" "$f"
done

# ------------------------------------------------------------------------------ v0.2.1 check files
# Each tests/integration/checks-*.sh file is sourced here, in name order. They share this file's
# helpers (check, wpq, sql, the HTTP helpers) and add their own sections.
for f in tests/integration/checks-*.sh; do
    [ -f "$f" ] || continue
    # shellcheck source=/dev/null
    . "$f"
done

# ------------------------------------------------------------------------------ AC-24 log hygiene
echo "== AC-24 nothing secret in debug.log"
wpq eval 'echo file_exists(WP_CONTENT_DIR . "/debug.log") ? file_get_contents(WP_CONTENT_DIR . "/debug.log") : "";' > "$TMP/debug.log"
for secret in "$KEY" "$HASH" "$KEY2" "$HASH2" "$KEY3" "$HASH3" "$KEY4" "$HASH4" "$KEY_CI" "$KEY_H2" "$KEY_H3" "$KEY_OPT" "$KEY37"; do
    [ -n "$secret" ] && check_not_contains "AC-24 debug.log free of secret ${secret:0:6}…" "$secret" "$TMP/debug.log"
done
check_not_contains "AC-48 debug.log free of the test address" "10.0.65.1" "$TMP/debug.log"
check "AC-24 debug.log has no Nova Gate PHP errors" "0" "$(grep -ci 'nova-gate' "$TMP/debug.log" | tr -d ' ')"

# ------------------------------------------------------------------------------ cleanup
echo "== Cleanup"
remove_helper >/dev/null
wpq user application-password delete "$ADMIN_USER" --all >/dev/null 2>&1
wpq user delete nova_sub --yes >/dev/null 2>&1
wpq option delete nova_gate_test_late >/dev/null 2>&1
wpq option delete nova_gate_fixture_ran >/dev/null 2>&1
wpq config delete NOVA_GATE_MAX_TRIES >/dev/null 2>&1
clear_tries

echo
echo "RESULT: $PASSED passed, $FAILED failed"
exit $FAIL
