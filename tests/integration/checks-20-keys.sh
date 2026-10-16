# shellcheck shell=bash
# Nova Gate v0.2.1 integration checks: key table locking, the empty v0.1.0 hash, and
# `wp nova-gate key list / add` details (fx-05b, fx-10, fx-14, fx-15).
#
# Sourced by tests/integration/run.sh near its end; uses its helpers (check, wpq, wpx, …).
# Starts and ends with no key, no NOVA_GATE_KEY_HASH constant and an empty tries table.

fx_cli_ids() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? implode(",", array_column($d, "id")) : "<not json>";' "$TMP/wpx.out"; }
fx_cli_field() { # row-id field  (from the JSON key list in $TMP/wpx.out)
    php -r '$d = json_decode((string) file_get_contents($argv[1]), true); foreach ((array) $d as $r) { if (($r["id"] ?? null) === $argv[2]) { echo (string) ($r[$argv[3]] ?? "<absent>"); exit; } } echo "<no row>";' "$TMP/wpx.out" "$1" "$2"
}
fx_no_keys() {
    wpq config delete NOVA_GATE_KEY_HASH >/dev/null 2>&1
    sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
    wpq option delete nova_gate_keys >/dev/null 2>&1
    wpq option delete nova_gate_key_hash >/dev/null 2>&1
    wpq option delete nova_gate_open_until >/dev/null 2>&1
    clear_tries
}

fx_no_keys
FX_JAR="$TMP/fx20.cookies"
login "$ADMIN_USER" "$ADMIN_PASS" "$FX_JAR"

# ------------------------------------------------------------------------------ fx-05b
echo "== FX-05b key add and remove running at the same time never bring a removed key back"
wpx nova-gate key add fx-base --porcelain >/dev/null
FX_KEY_BASE="$(tr -d '[:space:]' < "$TMP/wpx.out")"
wpx nova-gate key add fx-victim --porcelain >/dev/null
FX_KEY_VICTIM="$(tr -d '[:space:]' < "$TMP/wpx.out")"
check "FX-05b setup: two keys" "fx-base,fx-victim" "$(key_ids)"

# Every process waits for the same start time. Each add sleeps 0.4 s between reading the key
# table and writing it (a pre_update filter), and the remove starts 0.15 s after the adds, so
# without a lock every add writes back a snapshot that still holds fx-victim.
FX_T0="$(php -r 'printf("%.3F", microtime(true) + 25);')" # room for 7 staggered CLI starts
fx_add_job() { # n
    wpq eval "add_filter('pre_update_option_nova_gate_keys', static function (\$v) { usleep(400000); return \$v; });
        while (microtime(true) < $FX_T0) { usleep(2000); }
        \$r = NovaGate\\add_key('fx-c$1', 'fx-c$1');
        echo is_wp_error(\$r) ? 'ERR:' . \$r->get_error_code() : 'OK';" > "$TMP/fx05-add$1.out" 2>"$TMP/fx05-add$1.err"
}
fx_remove_job() {
    wpq eval "while (microtime(true) < $FX_T0 + 0.15) { usleep(2000); }
        \$r = NovaGate\\remove_key('fx-victim');
        echo is_wp_error(\$r) ? 'ERR:' . \$r->get_error_code() : 'OK';" > "$TMP/fx05-rm.out" 2>&1
}
# Start the CLI processes 1 s apart: seven wp-env starts at the same instant made one fail to start
# (empty output, nothing to do with the lock). They still all meet at FX_T0.
for n in 1 2 3 4 5 6; do fx_add_job "$n" & sleep 1; done
fx_remove_job &
wait
check "FX-05b the remove reported success" "OK" "$(tr -d '[:space:]' < "$TMP/fx05-rm.out")"
FX_ADDS=""
for n in 1 2 3 4 5 6; do
    FX_ADDS="$FX_ADDS c$n=$(tr -d '[:space:]' < "$TMP/fx05-add$n.out" | tail -c 40)"
done
check "FX-05b all six adds reported success" " c1=OK c2=OK c3=OK c4=OK c5=OK c6=OK" "$FX_ADDS"
FX_IDS="$(key_ids)"
check "FX-05b the removed key is absent after the parallel adds" "no" "$(printf '%s' ",$FX_IDS," | grep -q ',fx-victim,' && echo yes || echo no)"
FX_MISSING=""
for n in 1 2 3 4 5 6; do
    printf '%s' ",$FX_IDS," | grep -q ",fx-c$n," || FX_MISSING="$FX_MISSING fx-c$n"
done
check "FX-05b no add was lost (missing:$FX_MISSING)" "" "$FX_MISSING"
check "FX-05b the untouched key is still there" "yes" "$(printf '%s' ",$FX_IDS," | grep -q ',fx-base,' && echo yes || echo no)"
mcp fx05a -H "X-Test-IP: 10.20.5.1" -H "X-Nova-Gate-Key: $FX_KEY_VICTIM"
check "FX-05b the removed key is locked" "404" "$CODE"
mcp fx05b -H "X-Test-IP: 10.20.5.1" -H "X-Nova-Gate-Key: $FX_KEY_BASE"
check "FX-05b the untouched key still opens" "200" "$CODE"
check "FX-05b busy is reported as a WP_Error, not a crash" "ERR:busy" "$(wpq eval "
    global \$wpdb;
    \$lock = 'nova_gate_keys_' . get_current_blog_id();
    \$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    \$other->get_var(\$other->prepare('SELECT GET_LOCK(%s, 0)', \$lock));
    \$r = NovaGate\\add_key('fx-busy', 'fx-busy');
    \$other->query(\$other->prepare('SELECT RELEASE_LOCK(%s)', \$lock));
    echo is_wp_error(\$r) ? 'ERR:' . \$r->get_error_code() : 'OK';" | tr -d '[:space:]')"
check "FX-05b nothing was written while busy" "no" "$(printf '%s' ",$(key_ids)," | grep -q ',fx-busy,' && echo yes || echo no)"
fx_no_keys

# ------------------------------------------------------------------------------ fx-10
echo "== FX-10 an empty v0.1.0 hash is listed, and still matches nothing"
wpq eval 'update_option("nova_gate_key_hash", "", true);' >/dev/null
check "FX-10 setup: the v0.1.0 option exists and is empty" "yes|" "$(opt_exists nova_gate_key_hash)|$(opt nova_gate_key_hash)"
status fx10s
check "FX-10 the gate is active" "true" "$(json_get "$TMP/fx10s.body" active)"
check "FX-10 key list exits 0" "0" "$(wpx nova-gate key list --format=json)"
check "FX-10 key list shows the empty legacy key as default" "default" "$(fx_cli_ids)"
check "FX-10 its label says it cannot match" "Empty legacy key (cannot match; add a key)" "$(fx_cli_field default label)"
settings_page "$FX_JAR" "$TMP/fx10.html"
check_contains "FX-10 settings page lists default" "<code>default</code>" "$TMP/fx10.html"
check_contains "FX-10 settings page shows the label" "Empty legacy key (cannot match; add a key)" "$TMP/fx10.html"
mcp fx10a -H "X-Test-IP: 10.20.10.1" -H "X-Nova-Gate-Key: $(php -r 'echo bin2hex(random_bytes(32));')"
check "FX-10 a random key is locked" "404" "$CODE"
mcp fx10b -H "X-Test-IP: 10.20.10.1"
check "FX-10 no key is locked" "404" "$CODE"
check "FX-10 adding a key next to it works" "0" "$(wpx nova-gate key add fx-real --porcelain)"
FX_KEY_REAL="$(tr -d '[:space:]' < "$TMP/wpx.out")"
mcp fx10c -H "X-Test-IP: 10.20.10.1" -H "X-Nova-Gate-Key: $FX_KEY_REAL"
check "FX-10 the new key opens" "200" "$CODE"
check "FX-10 removing the empty legacy key exits 0" "0" "$(wpx nova-gate key remove default)"
check "FX-10 only the real key is left" "fx-real" "$(key_ids)"
check "FX-10 the v0.1.0 option is gone" "no" "$(opt_exists nova_gate_key_hash)"
fx_no_keys

# ------------------------------------------------------------------------------ fx-14
echo "== FX-14 key list in constant mode lists the constant's hashes, never the option keys"
wpx nova-gate key add fx-optkey --porcelain >/dev/null
FX_KEY_A="$(php -r 'echo bin2hex(random_bytes(32));')"
FX_KEY_B="$(php -r 'echo bin2hex(random_bytes(32));')"
FX_HASH_A="$(sha256 "$FX_KEY_A")"
FX_HASH_B="$(sha256 "$FX_KEY_B")"
wpq config set NOVA_GATE_KEY_HASH "$FX_HASH_A, $FX_HASH_B" --type=constant >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
check "FX-14 key list exits 0" "0" "$(wpx nova-gate key list --format=json)"
check "FX-14 the rows are the constant's hashes" "const-1,const-2" "$(fx_cli_ids)"
check "FX-14 label says wp-config.php" "from wp-config.php" "$(fx_cli_field const-1 label)"
check "FX-14 created is -" "-" "$(fx_cli_field const-2 created)"
check_contains "FX-14 a warning says the constant is in use" "NOVA_GATE_KEY_HASH" "$TMP/wpx.err"
check_not_contains "FX-14 the option key is not listed" "fx-optkey" "$TMP/wpx.out"
check_not_contains "FX-14 no hash on stdout (A)" "$FX_HASH_A" "$TMP/wpx.out"
check_not_contains "FX-14 no hash on stdout (B)" "$FX_HASH_B" "$TMP/wpx.out"
check_not_contains "FX-14 no hash on stderr (A)" "$FX_HASH_A" "$TMP/wpx.err"
check_not_contains "FX-14 no hash on stderr (B)" "$FX_HASH_B" "$TMP/wpx.err"
check "FX-14 table format exits 0" "0" "$(wpx nova-gate key list)"
check_contains "FX-14 table format lists const-1" "const-1" "$TMP/wpx.out"
check_not_contains "FX-14 table format hides the option key" "fx-optkey" "$TMP/wpx.out"
wpq config set NOVA_GATE_KEY_HASH "$FX_HASH_A,zzjunkzz" --type=constant >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
check "FX-14 junk constant: key list exits 0" "0" "$(wpx nova-gate key list --format=json)"
check "FX-14 junk constant: both entries are listed" "const-1,const-2" "$(fx_cli_ids)"
check "FX-14 junk constant: the valid hash is labelled as usual" "from wp-config.php" "$(fx_cli_field const-1 label)"
check "FX-14 junk constant: the junk entry says it matches no key" "from wp-config.php (not a valid SHA-256 hash; matches no key)" "$(fx_cli_field const-2 label)"
check_not_contains "FX-14 junk constant: the junk value is not printed" "zzjunkzz" "$TMP/wpx.out"
check_not_contains "FX-14 junk constant: the hash is not printed" "$FX_HASH_A" "$TMP/wpx.out"
wpq config delete NOVA_GATE_KEY_HASH >/dev/null
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
check "FX-14 constant removed: the option key is listed again" "0|fx-optkey" "$(wpx nova-gate key list --format=json)|$(fx_cli_ids)"
fx_no_keys

# ------------------------------------------------------------------------------ fx-15
echo "== FX-15 key add --hash --porcelain prints nothing"
FX_HASH_P="$(sha256 "$(php -r 'echo bin2hex(random_bytes(32));')")"
check "FX-15 --hash --porcelain exits 0" "0" "$(wpx nova-gate key add fx-quiet --hash="$FX_HASH_P" --porcelain)"
check "FX-15 --hash --porcelain prints nothing on stdout" "" "$(cat "$TMP/wpx.out")"
check "FX-15 --hash --porcelain stored the hash" "$FX_HASH_P" "$(key_field fx-quiet hash)"
check "FX-15 --hash without --porcelain exits 0" "0" "$(wpx nova-gate key add fx-loud --hash="$(sha256 fx-loud)")"
check_contains "FX-15 --hash without --porcelain prints the Success line" 'Success: Key "fx-loud" stored from its hash.' "$TMP/wpx.out"
fx_no_keys
check "FX section ends with no key" "no|no" "$(opt_exists nova_gate_keys)|$(opt_exists nova_gate_key_hash)"
