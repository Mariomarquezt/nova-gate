# Nova Gate – Access Lock for Novamira

Nova Gate keeps Novamira's AI abilities **off for everyone**, and opens them for a request that
carries the right secret key. An admin can also open the gate by hand for a set time (5–240
minutes); while it is open this way, **every** authenticated caller gets in, also one with only an
application password. Outside an open window, a stolen WordPress application password alone can no
longer reach Novamira's MCP endpoint.

- Plugin: `nova-gate` · Version 0.2.1 · GPL-2.0-or-later
- Requires WordPress 6.9+, PHP 8.0+, and the Novamira plugin
- Made by [Monkey Webs](https://monkeywebs.com/)

> **Independent add-on.** Not made, approved or supported by Novamira, Dynamic.ooo or Ovation S.r.l.

## How it works

Novamira stores its AI switch in two options and reads them once while its main file loads. Nova Gate
loads first (the folder `nova-gate` sorts before `novamira`) and filters those reads:

| Request | What Novamira sees | Database value |
|---|---|---|
| No key configuration stored or defined | the real database value | unchanged |
| Right `X-Nova-Gate-Key` header (any of the site's keys) | **on**, for this request only | `0` |
| Novamira's own one-time link routes (`/novamira/v1/upload`, `/novamira/v1/admin-access`) | **on** | `0` |
| A manual open is running (admin or WP-CLI, 5–240 minutes, default 30) | **on**, for every request | `0` |
| Anything else, including a right key from a blocked address | **off** | `0` |

While a manual open runs, every authenticated caller gets in — also one with only an application
password. Keys are made in **Settings → Nova Gate** (or with WP-CLI), shown once, and stored
nowhere. Only their SHA-256 hashes are saved. Comparison uses `hash_equals()` against every key,
with no early exit. Every unclear case ends locked.

## Install

1. Install and activate Novamira.
2. Copy this folder to `wp-content/plugins/nova-gate` and activate **Nova Gate**. Keep the folder name.
3. **Settings → Nova Gate → Add key.** Give it a label (the name of the tool that will use it). Copy the
   key now; it is shown only this once.
4. Send the key as the header `X-Nova-Gate-Key` on every request to `/wp-json/mcp/novamira`.

No build step, no Composer or npm dependency at runtime.

## Several keys

Settings and WP-CLI can manage up to 20 stored keys, each with an id and a label.
`NOVA_GATE_KEY_HASH` has no enforced entry limit. Add one key per tool.
Any accepted key opens the gate. Remove one key and the others keep working.
Remove the last key and the gate becomes inactive (Novamira's database switch stays off until
someone turns it on).

Adding the first key writes to the database once: it sets Novamira's switch off and deletes
Novamira's domain option (`novamira_ai_abilities_domain`). Uninstalling Nova Gate does not restore
it; Novamira sets it again itself when you turn the switch on.

- The id is made from the label (`sanitize_title`, cut to 40 characters) and must be unique.
- There is no "replace" button: add a new key, then remove the old one.
- A site that still has the single key of v0.1.0 keeps working. Its key shows up as id `default`,
  label `Default key`, and is moved into the new key table the first time a key is added or removed.
  If that old option is empty it is listed as `default`, "Empty legacy key (cannot match; add a
  key)": it opens nothing but keeps the gate locked — add a key, then remove `default`.
- Adding and removing keys takes a short database lock (MySQL/MariaDB `GET_LOCK`). Clearing blocks
  takes the tries lock. A change that waits more than 5 seconds fails with "Another change is in
  progress. Nothing was changed; try again in a moment." Lock errors and failed fresh reads also
  refuse the change. Only a database without `GET_LOCK` (for example SQLite) runs without the lock.
- Stored key configurations keep the gate active even when their rows are not autoloaded.
  An empty or invalid configuration stays active but opens nothing.

## Wrong-try limit

An address that sends **10 wrong keys within 10 minutes is blocked for 15 minutes**. While blocked, every
`X-Nova-Gate-Key` header from that address is ignored, **also a right key**: the status route answers
`"key_header": "blocked"` and the MCP endpoint is not there. Novamira's one-time links and a manual
open from wp-admin still work for a blocked address. Requests without a key header are never counted.

- Addresses are not stored in clear text. `nova_gate_tries` stores the first 16 hex characters of
  a salted SHA-256 hash. Salt selection uses a nonempty string `NONCE_SALT` first, then an existing
  or newly saved random `nova_gate_salt`. If no usable salt can be saved, it uses a stable value
  derived from the site path and any configured `AUTH_KEY` and `DB_NAME`. Changing the selected
  salt changes which stored entries apply. Ordinary IPv6 addresses count per /64 network.
  IPv4-mapped IPv6 addresses count as IPv4. Uninstall deletes `nova_gate_salt`.
- The table holds at most 200 entries, and a new address is always counted: to make room, the
  oldest still-counting entry goes first, and only if every other entry is an active block does the
  block that ends soonest go. An address with no entry is never blocked, so a flood from many
  addresses cannot lock your hub out.
- Counting a wrong try takes a separate tries lock. A try that cannot get the lock is not counted
  and cannot open the gate by key. A failed fresh read also refuses the key and writes nothing.
- With the limiter enabled, a matching key must pass a fresh database block check before it is
  accepted. This check takes no lock. A failed read refuses the key and writes nothing.
- Clear every block from **Settings → Nova Gate → Clear blocks**, or with `wp nova-gate unblock`.
  Clearing uses the tries lock and counts blocked addresses from a fresh read. On `busy`, the
  settings page shows an error and CLI exits 1. Nothing is changed; try again in a moment.
- The settings page shows how many addresses are blocked now and the time of the last wrong try.

Three constants in `wp-config.php` tune it:

```php
define( 'NOVA_GATE_MAX_TRIES', 10 );                          // 3–100; 0 turns the limit off
define( 'NOVA_GATE_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );     // $_SERVER key with the real address
define( 'NOVA_GATE_TRUSTED_PROXIES', '203.0.113.10, 2001:db8::/32' ); // your proxies
```

**Trusted proxies.** `NOVA_GATE_IP_HEADER` is used only when the request comes from a proxy you
listed: `REMOTE_ADDR` must match an entry of `NOVA_GATE_TRUSTED_PROXIES` (a comma list of IPv4/IPv6
addresses and CIDRs of *your* proxies). Without it — or when it has no valid entry — the header is
ignored, `REMOTE_ADDR` is used, and the settings page shows a warning. For a list header like
`X-Forwarded-For`, Nova Gate reads from the right and takes the first address that is not a trusted
proxy; text to the left of it is ignored, and if every entry is a trusted proxy it takes the
left-most. `::ffff:a.b.c.d` matches IPv4 ranges, and entries with a port (`1.2.3.4:5678`,
`[2001:db8::1]:443`) work. If an entry inspected while walking the trusted suffix is malformed,
Nova Gate uses `REMOTE_ADDR`. Invalid text to the left of the first untrusted address is ignored.

Two examples:

- **Cloudflare:** `NOVA_GATE_IP_HEADER` = `HTTP_CF_CONNECTING_IP`, `NOVA_GATE_TRUSTED_PROXIES` =
  Cloudflare's published ranges.
- **An nginx reverse proxy on the same server:** `NOVA_GATE_IP_HEADER` = `HTTP_X_FORWARDED_FOR`,
  `NOVA_GATE_TRUSTED_PROXIES` = `127.0.0.1`.

**Warning:** never trust a shared CDN range unless the CDN overwrites the header — other customers
of the same CDN could send any address.

**Upgrading from v0.2.0?** If your `wp-config.php` sets `NOVA_GATE_IP_HEADER`, add
`NOVA_GATE_TRUSTED_PROXIES`, or the header is now ignored.

**Known limit.** An attacker who keeps changing address can restart their count.
Nova Gate generates keys from 256 random bits. Imported keys are only as strong as their source.

## Send the key

The settings page shows these examples with your site's real URL. Replace `YOUR_KEY`.

**Claude Code CLI**

```sh
claude mcp add --transport http novamira https://example.com/wp-json/mcp/novamira --header "X-Nova-Gate-Key: YOUR_KEY"
```

**Generic MCP client (JSON)**

```json
{
  "mcpServers": {
    "novamira": {
      "type": "http",
      "url": "https://example.com/wp-json/mcp/novamira",
      "headers": { "X-Nova-Gate-Key": "YOUR_KEY" }
    }
  }
}
```

**@automattic/mcp-wordpress-remote**

```json
{
  "mcpServers": {
    "novamira": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/novamira",
        "CUSTOM_HEADERS": "{\"X-Nova-Gate-Key\":\"YOUR_KEY\"}"
      }
    }
  }
}
```

**curl**

```sh
curl -X POST https://example.com/wp-json/mcp/novamira \
  -H "X-Nova-Gate-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

The header is also read when Apache passes it as `REDIRECT_HTTP_X_NOVA_GATE_KEY`.

A `rest_route` value counts only on the front controller (the home URL, `/index.php`, or a path
under the REST prefix) — never on `admin-ajax.php`, inside `wp-admin`, or on `xmlrpc.php`. When the
server sets `PATH_INFO`, it may include the home path on a sub-folder install.

Novamira's own login and permission checks still apply after the gate. The key replaces nothing; it is
an extra lock.

## WP-CLI

```sh
wp nova-gate status [--format=<table|json>]      # state, key source, number of keys, early, timer, blocked addresses
wp nova-gate key list [--format=<table|json|csv>] # id, label, creation time of every key (never a hash or key)
wp nova-gate key add <id> [--label=<text>] [--hash=<sha256>] [--porcelain]
wp nova-gate key remove <id> [--yes]              # the last key asks for confirmation unless --yes
wp nova-gate open [--minutes=<n>]                 # manual open for n minutes (5–240; default: the saved timer)
wp nova-gate lock                                 # remove the manual open
wp nova-gate unblock                              # forget every wrong try and block
```

- `<id>` must match `[a-z0-9][a-z0-9-]{0,39}`. The label defaults to the id.
- `key add` prints the new key once. With `--porcelain` the key is the only output, so
  `KEY=$(wp nova-gate key add ci --porcelain)` works. With `--hash=<64 lowercase hex>` that hash is
  stored and no key is printed — only `Success: Key "<id>" stored from its hash.` (with
  `--porcelain` nothing is printed; exit 0 on success, 1 with the error on stderr).
- `key list` while `NOVA_GATE_KEY_HASH` is defined prints a warning and lists the constant's
  entries as `const-1`, `const-2`, … (label "from wp-config.php", created "-"); the stored keys are
  not listed — the gate ignores them while the constant is set. An entry that is not a valid
  SHA-256 hash is listed with the label "from wp-config.php (not a valid SHA-256 hash; matches no
  key)". Hashes are never printed.
- The key commands that write refuse when `NOVA_GATE_KEY_HASH` is defined.
- A manual open from WP-CLI records the current WordPress user id, or `0` when no user is selected.
  The settings page displays user `0` as `WP-CLI`.

## Status route

`GET /wp-json/nova-gate/v1/status` needs no login and answers:

```json
{ "nova_gate": "0.2.1", "active": true, "early": true, "key_header": "missing" }
```

- `active` – a key configuration is present, even if empty or invalid. This does not mean any key
  can match. It is false only when no configuration is present. If the key rows cannot be read
  (a database error), the gate also stays active and matches nothing.
- `early` – Nova Gate loaded before Novamira (it must).
- `key_header` – `missing`, `wrong`, `ok` or `blocked` for the header you sent.

It never returns a hash, a key, how many keys exist, the timer or any user data, and it sends
`Cache-Control: no-store`. A wrong key sent to the status route counts as a wrong try.

## Manual open from wp-admin

When an administrator turns Novamira's switch on (Novamira's own settings or admin-bar toggle), Nova
Gate keeps the database value at `0` and opens the gate for N minutes (5–240, default 30, set on the
Nova Gate page). While it is open this way, every authenticated caller gets in — also one with only
an application password. A notice on every admin screen shows when it locks again and offers
**Lock now**. Turning the switch off locks at once.

A logged-in user with `manage_options` can open this way unless WordPress authenticated the request
with an application password. Nova Gate does not explicitly require cookie authentication.
WP-CLI can (`wp nova-gate open`). The manual open also works on
sites that turn autoload off for new options.

**Security note for other code.** Code that writes Novamira's switch must check its own nonce. Nova
Gate turns such a write by a logged-in admin into a timed manual open, and ignores it from
application-password or logged-out requests — the stored value stays `0` either way.

## Novamira's OAuth connection (newer Novamira versions)

Newer Novamira versions add a second endpoint, `/wp-json/mcp/novamira-oauth`, for clients that sign in
with OAuth and cannot send a custom header. Novamira registers it only while its switch is on. Under
Nova Gate it therefore exists only for requests that carry the key, or during a manual open. To use an
OAuth client, open the gate from wp-admin first; the connection stops when the timer ends.

## Keys in wp-config.php

Instead of the options you can define the hashes in `wp-config.php`: one hash, several hashes in one
string separated by commas or whitespace, or an array of strings.

```php
define( 'NOVA_GATE_KEY_HASH', '<sha256 hex of key 1>, <sha256 hex of key 2>' );
```

Make a key and its hash on your machine:

```sh
KEY=$(php -r 'echo bin2hex(random_bytes(32));'); echo "key:  $KEY"; printf '%s' "$KEY" | sha256sum
```

The constant wins over the options, the settings page then offers no key actions, and the WP-CLI key
commands that write refuse. `wp nova-gate key list` then lists the constant's entries as `const-1`,
`const-2`, …. A constant with no valid hash keeps the gate active and lets no key match.

## Route coverage

With an early boot, Nova Gate filters Novamira's switches and adds no REST dispatch guard.
Other plugins' routes retain their own authentication and permission checks. On a late boot,
the guard applies to every matching path described in the late-boot rules, regardless of which
plugin registered it.

## Load before Novamira (must-use loader)

Nova Gate must run before Novamira's main file. Normal activation does that, because WordPress loads
active plugins in folder order and `nova-gate` sorts before `novamira`. If your setup loads Novamira
earlier (for example as a must-use plugin), add a loader file `wp-content/mu-plugins/00-nova-gate.php`:

```php
<?php
/**
 * Plugin Name: Nova Gate loader (must-use)
 */
if ( is_file( WP_PLUGIN_DIR . '/nova-gate/nova-gate.php' ) ) {
    require_once WP_PLUGIN_DIR . '/nova-gate/nova-gate.php';
}
```

Then deactivate the regular Nova Gate plugin so it does not load twice.

If Nova Gate loads after Novamira, its late-boot guard applies only while the gate is active.
Without an accepted key or a manual open, it immediately checks and forces an enabled database
switch off and returns 404 for these REST paths and their subroutes: `/novamira`, `/mcp/novamira*`,
`/mcp/mcp-adapter-default-server`, and `/wp-abilities/v1/abilities/novamira`. Matching is
case-insensitive. Here `*` means any remaining characters in that path segment. An accepted key or
manual open bypasses the guard, but cannot undo Novamira's earlier switch read. One-time links alone
do not bypass it. Every active request also checks the stored switch at `init`. The settings page
warns when Nova Gate loads late; the status route reports `"early": false`. Load Nova Gate before
Novamira.

## Removal is safe

While Nova Gate is active and a key is set, the database switch stays `0` — Nova Gate set it off
when the first key was added, and deleted Novamira's domain option at the same time. Deactivating or
deleting Nova Gate therefore leaves Novamira's MCP endpoint unavailable until someone turns the
switch on again in Novamira (Novamira then sets the domain option again). If no key was ever set,
Nova Gate never touched Novamira's options, so the switch is whatever it was.

Deactivation deletes only `nova_gate_open_until`. Uninstall deletes Nova Gate's seven options
(`nova_gate_key_hash`, `nova_gate_keys`, `nova_gate_tries`, `nova_gate_salt`,
`nova_gate_open_until`, `nova_gate_timer_minutes`, `nova_gate_last_open`) and touches no Novamira
option — it does not restore `novamira_ai_abilities_domain`.

## Not supported yet

Multisite networks. IP allow-lists, an audit log, a settings field for the limit numbers.

## Limits

- No test runs with a persistent object cache.
- The integration suite uses a frozen Novamira test double, checked by hand against real Novamira
  1.8–1.12.
- The database lock's concurrency is reasoned, not proven by a race test in every case.

## Development

The plugin runs on PHP 8.0+. The test tools (PHPUnit 11.5) need PHP 8.2+.

```sh
composer install              # dev tools only, no WordPress needed
vendor/bin/phpunit            # unit tests for the pure decision core and the limiter
tests/integration/run.sh      # real WordPress in wp-env (Docker) + the frozen Novamira test double
npx @wordpress/env stop
```

The integration suite uses ports 8931/8932 by default; set `WP_ENV_PORT` and `WP_ENV_TESTS_PORT` to
pick other ports.

`tests/fixtures/novamira/` is a frozen test double that copies only how Novamira reads its switch.
It is not Novamira and contains none of its code.

## License

Nova Gate is free software, released under the GNU General Public License, version 2 or (at your
option) any later version (GPL-2.0-or-later). See LICENSE. Copyright (C) 2026 Monkey Webs.
