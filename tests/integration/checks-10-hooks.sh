#!/usr/bin/env bash
# Sourced by run.sh near its end: checks for the v0.2.1 request-path fixes (real WordPress).
echo '== fx bundle A request hooks'

# The main suite uninstalled its keys before sourcing check files. Own our setup.
wpq config delete NOVA_GATE_KEY_HASH >/dev/null 2>&1
sleep 3 # PHP opcache re-reads a changed wp-config.php only after ~2 s (revalidate_freq)
wpq config delete NOVA_GATE_MAX_TRIES >/dev/null 2>&1
wpq option delete nova_gate_test_late >/dev/null 2>&1
wpq option delete nova_gate_open_until >/dev/null 2>&1
HOOK_KEY="$(printf 'a%.0s' {1..64})"
wpq option update nova_gate_key_hash "$(sha256 "$HOOK_KEY")" --autoload=yes >/dev/null
clear_tries

# Save constant presence as well as value; restore exactly after all trust cases.
HOOK_TRUST_SET=no
HOOK_TRUST=''
if HOOK_TRUST="$(wpq config get NOVA_GATE_TRUSTED_PROXIES 2>/dev/null)"; then HOOK_TRUST_SET=yes; fi
for HOOK_MODE in untrusted undefined invalid; do
    if [ "$HOOK_MODE" = untrusted ]; then
        wpq config set NOVA_GATE_TRUSTED_PROXIES '192.0.2.0/24' >/dev/null
    elif [ "$HOOK_MODE" = invalid ]; then
        wpq config set NOVA_GATE_TRUSTED_PROXIES 'junk,192.0.2.0/33,::/129' >/dev/null
    else
        wpq config delete NOVA_GATE_TRUSTED_PROXIES >/dev/null 2>&1
    fi
    clear_tries
    for HOOK_I in {1..10}; do
        status "hooks-ip-$HOOK_MODE-$HOOK_I" -H "X-Test-IP: 10.91.1.$HOOK_I" -H 'X-Nova-Gate-Key: wrong'
    done
    status "hooks-ip-$HOOK_MODE-final" -H 'X-Test-IP: 10.91.2.1' -H "X-Nova-Gate-Key: $HOOK_KEY"
    check "fx-01 $HOOK_MODE proxy: rotating headers still blocks the real peer" blocked "$(json_get "$TMP/hooks-ip-$HOOK_MODE-final.body" key_header)"
    check "fx-01 $HOOK_MODE proxy: exactly one stored address id" 1 "$(tries_count)"
    if [ "$HOOK_MODE" != untrusted ]; then
        settings_page "$JAR" "$TMP/hooks-proxy-notice.html"
        check_contains "fx-01 $HOOK_MODE trusted proxies notice" 'NOVA_GATE_IP_HEADER is ignored' "$TMP/hooks-proxy-notice.html"
    fi
done
if [ "$HOOK_TRUST_SET" = yes ]; then
    wpq config set NOVA_GATE_TRUSTED_PROXIES "$HOOK_TRUST" >/dev/null
else
    wpq config delete NOVA_GATE_TRUSTED_PROXIES >/dev/null 2>&1
fi
clear_tries

# fx-02/fx-03: seed a real enabled-at-load route, without a CLI boot healing the
# switch between the raw write and HTTP. Novamira also requires the domain row.
wpq option update nova_gate_test_late 1 >/dev/null
sql "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('novamira_ai_abilities_domain', 'localhost', 'yes') ON DUPLICATE KEY UPDATE option_value='localhost'" >/dev/null
db_set_enabled_raw 1 >/dev/null
mcp hooks-late-control -H "X-Nova-Gate-Key: $HOOK_KEY"
check 'fx-03 positive control: raw switch 1 registers MCP before late gate' 200 "$CODE"
for HOOK_SWITCH in 1 0; do
    for HOOK_AUTH in anonymous application-password; do
        sql "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('novamira_ai_abilities_domain', 'localhost', 'yes') ON DUPLICATE KEY UPDATE option_value='localhost'" >/dev/null
        db_set_enabled_raw "$HOOK_SWITCH" >/dev/null
        if [ "$HOOK_AUTH" = anonymous ]; then
            mcp "hooks-late-$HOOK_SWITCH-$HOOK_AUTH"
        else
            mcp "hooks-late-$HOOK_SWITCH-$HOOK_AUTH" -u "$ADMIN_USER:$APP_PW"
        fi
        check "fx-03 late load switch=$HOOK_SWITCH $HOOK_AUTH MCP refused" 404 "$CODE"
        check "fx-03 late load switch=$HOOK_SWITCH $HOOK_AUTH rest_no_route" rest_no_route "$(json_get "$TMP/hooks-late-$HOOK_SWITCH-$HOOK_AUTH.body" code)"
        # Boot timing is covered separately by the isolated request-glue test.
        check "fx-03 late load switch=$HOOK_SWITCH $HOOK_AUTH stored switch healed" 0 "$(db_enabled)"
    done
done
req hooks-late-link POST "$BASE/wp-json/novamira/v1/upload"
check 'fx-02 late load one-time links fail closed' 404 "$CODE"
wpq option delete nova_gate_test_late >/dev/null
db_set_enabled_raw 0 >/dev/null

# fx-05a: stress the real record/write path with parallel wrong keys. A passing run is
# evidence, not proof: a race may simply not happen on a given run.
clear_tries
HOOK_PIDS=()
for HOOK_I in {1..12}; do
    curl -s --max-time 30 -o "$TMP/hooks-parallel-$HOOK_I.body" \
        "$BASE/wp-json/nova-gate/v1/status" -H 'X-Test-IP: 10.91.5.1' \
        -H 'X-Nova-Gate-Key: wrong' &
    HOOK_PIDS+=("$!")
done
for HOOK_PID in "${HOOK_PIDS[@]}"; do
    if ! wait "$HOOK_PID"; then fail 'fx-05a parallel request completed' 'curl failed'; fi
done
status hooks-parallel-final -H 'X-Test-IP: 10.91.5.1' -H "X-Nova-Gate-Key: $HOOK_KEY"
check 'fx-05a parallel wrong keys produce a block' blocked "$(json_get "$TMP/hooks-parallel-final.body" key_header)"
check 'fx-05a stored counter reaches the maximum' 10 "$(tries_sum_n)"
clear_tries

# fx-08: an add must be corrected in that same WP-CLI process, before next init.
wpq option delete novamira_ai_abilities_enabled >/dev/null
wpq eval 'add_option("novamira_ai_abilities_enabled", "1"); global $wpdb; echo $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = '\''novamira_ai_abilities_enabled'\''");' > "$TMP/hooks-added-switch"
check 'fx-08 newly added switch is immediately zero' 0 "$(tail -1 "$TMP/hooks-added-switch")"
# Also cover the requested public CLI command, checking after it finishes.
wpq option delete novamira_ai_abilities_enabled >/dev/null
wpq option add novamira_ai_abilities_enabled 1 >/dev/null
check 'fx-08 wp option add persists zero' 0 "$(db_enabled)"

# fx-09: Nova Gate's persisted fallback when NONCE_SALT is absent. Save and restore
# the constant without printing salts.
HOOK_SALT_SET=no
HOOK_SALT=''
if HOOK_SALT="$(wpq config get NONCE_SALT 2>/dev/null)"; then HOOK_SALT_SET=yes; fi
wpq config delete NONCE_SALT >/dev/null 2>&1
clear_tries
status hooks-salt -H 'X-Test-IP: 10.91.9.1' -H 'X-Nova-Gate-Key: wrong'
check 'fx-09 missing NONCE_SALT: request does not fatal at early boot' 200 "$CODE"
check 'fx-09 missing NONCE_SALT: uses nova_gate_salt' yes "$(wpq eval '$salt = get_option("nova_gate_salt"); $id = NovaGate\Limiter::address_id("10.91.9.1", (string) $salt); echo is_string($salt) && preg_match("/^[a-f0-9]{64}$/D", $salt) && isset(((array) get_option("nova_gate_tries", []))[$id]) ? "yes" : "no";' | tail -1)"
HOOK_AUTO="$(opt_autoload nova_gate_salt)"
check 'fx-09 nova_gate_salt is autoloaded' yes "$([ "$HOOK_AUTO" = yes ] || [ "$HOOK_AUTO" = on ] && echo yes || echo no)"
# C3: an autoload cleaner must not crash even the valid hub key at load time.
HOOK_SALT_AUTO="$(opt_autoload nova_gate_salt)"
sql "UPDATE wp_options SET autoload='no' WHERE option_name='nova_gate_salt'" >/dev/null
clear_tries
status hooks-salt-no-autoload-valid -H 'X-Test-IP: 10.91.9.1' -H "X-Nova-Gate-Key: $HOOK_KEY"
check 'C3 non-autoloaded salt: valid key request does not fatal' 200 "$CODE"
check 'C3 non-autoloaded salt: valid key is accepted' ok "$(json_get "$TMP/hooks-salt-no-autoload-valid.body" key_header)"
status hooks-salt-no-autoload-wrong -H 'X-Test-IP: 10.91.9.1' -H 'X-Nova-Gate-Key: wrong'
check 'C3 non-autoloaded salt: wrong key request does not fatal' 200 "$CODE"
check 'C3 non-autoloaded salt: stored salt still determines limiter id' yes "$(wpq eval '$salt = get_option("nova_gate_salt"); $id = NovaGate\Limiter::address_id("10.91.9.1", (string) $salt); echo isset(((array) get_option("nova_gate_tries", []))[$id]) ? "yes" : "no";' | tail -1)"
sql "UPDATE wp_options SET autoload='$HOOK_SALT_AUTO' WHERE option_name='nova_gate_salt'" >/dev/null
if [ "$HOOK_SALT_SET" = yes ]; then
    wpq config set NONCE_SALT "$HOOK_SALT" >/dev/null
else
    wpq config delete NONCE_SALT >/dev/null 2>&1
fi
unset HOOK_SALT
clear_tries

# fx-10: legacy empty string stays active and explains its unusable key.
wpq option update nova_gate_key_hash '' >/dev/null
settings_page "$JAR" "$TMP/hooks-empty-legacy.html"
check_contains 'fx-10 empty legacy key is explained' 'legacy key is empty and cannot unlock' "$TMP/hooks-empty-legacy.html"
status hooks-empty-legacy
check 'fx-10 empty legacy key keeps gate active' true "$(json_get "$TMP/hooks-empty-legacy.body" active)"
wpq option update nova_gate_key_hash "$(sha256 "$HOOK_KEY")" >/dev/null

# fx-11: seed both as non-autoloaded, including an existing manual-open deadline.
wpq option update nova_gate_open_until 1 >/dev/null
wpq option update nova_gate_last_open '{"user":0,"time":1}' --format=json >/dev/null
sql "UPDATE wp_options SET autoload='no' WHERE option_name IN ('nova_gate_open_until','nova_gate_last_open')" >/dev/null
wpq nova-gate open >/dev/null
check 'fx-11 state is open after CLI open' open_manual "$(wpq eval 'echo NovaGate\state();' | tail -1)"
for HOOK_OPTION in nova_gate_open_until nova_gate_last_open; do
    HOOK_AUTO="$(opt_autoload "$HOOK_OPTION")"
    check "fx-11 $HOOK_OPTION becomes autoloaded" yes "$([ "$HOOK_AUTO" = yes ] || [ "$HOOK_AUTO" = on ] && echo yes || echo no)"
done
wpq nova-gate lock >/dev/null
wpq option delete nova_gate_key_hash >/dev/null
wpq option delete nova_gate_last_open >/dev/null
clear_tries
db_set_enabled_raw 0 >/dev/null
