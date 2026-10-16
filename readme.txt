=== Nova Gate – Access Lock for Novamira ===
Contributors: monkeywebs
Tags: novamira, mcp, security, access control, ai
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: novamira
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps Novamira's AI abilities off for everyone. A request that carries the right secret key gets in; an admin can also open the gate for a set time.

== Description ==

Novamira's AI abilities are a site-wide switch. When it is on, anyone who can authenticate to the REST API – for example with a stolen application password – can reach the MCP endpoint at `/wp-json/mcp/novamira`.

Nova Gate adds a second lock:

* The real database switch stays **off**.
* An accepted `X-Nova-Gate-Key` header makes the filtered switch read **on** for that request. Nova Gate still keeps the stored switch off and may save its limiter salt.
* Settings and WP-CLI can manage up to 20 named stored keys, each removable on its own. `NOVA_GATE_KEY_HASH` has no enforced entry limit.
* An address that sends 10 wrong keys within 10 minutes is blocked for 15 minutes, also for a right key.
* Novamira's own one-time links (upload and admin-access) keep working without the key.
* An administrator can still turn Novamira's switch on by hand in wp-admin. Nova Gate turns that into a timed open (5–240 minutes, default 30) and locks again afterwards. While it is open this way, every authenticated caller gets in – also one with only an application password.
* A public status route, `/wp-json/nova-gate/v1/status`, tells your client whether the gate is active and whether its key is accepted (`missing`, `wrong`, `ok` or `blocked`). It never reveals a key or its hash.
* WP-CLI commands under `wp nova-gate`.

Every unclear case ends locked.

**Independent add-on. Not made, approved or supported by Novamira, Dynamic.ooo or Ovation S.r.l.**

Made by [Monkey Webs](https://monkeywebs.com/).

== Installation ==

1. Install and activate Novamira.
2. Upload the `nova-gate` folder to `/wp-content/plugins/` and activate Nova Gate. Keep the folder name `nova-gate`: WordPress loads plugins in folder order, and Nova Gate must load before Novamira.
3. Go to **Settings → Nova Gate**, give the key a label and click **Add key**. Copy the key: it is shown only once and is not stored anywhere. Only its SHA-256 hash is saved.
4. Add the header `X-Nova-Gate-Key: <your key>` to your MCP client. The settings page shows copy-paste examples for Claude Code, generic JSON clients, `@automattic/mcp-wordpress-remote` and curl. The header is also read when Apache passes it as `REDIRECT_HTTP_X_NOVA_GATE_KEY`.

Optional: define `NOVA_GATE_KEY_HASH` in `wp-config.php` with the SHA-256 hex digest of your key, or several digests separated by commas, or an array. The constant wins over the options, and the settings page then offers no key actions.

== Frequently Asked Questions ==

= Can I have more than one key? =

Yes. Settings and WP-CLI can manage up to 20 stored keys. `NOVA_GATE_KEY_HASH` has no enforced entry limit. Each key has an id and a label. Add one per tool, so you can remove a single key without touching the others. Remove the last key and the gate becomes inactive. There is no replace button: add a new key, then remove the old one. A site that still has the single key of v0.1.0 keeps working; it shows up as the key `default`. If that old option is empty, it is listed as `default` with the label "Empty legacy key (cannot match; add a key)": it opens nothing but keeps the gate locked – add a key, then remove `default`.

Adding and removing keys takes a short database lock (MySQL/MariaDB `GET_LOCK`). Clearing blocks takes the tries lock. A change that waits more than 5 seconds fails with "Another change is in progress. Nothing was changed; try again in a moment." Lock errors and failed fresh reads also refuse the change. Only a database without `GET_LOCK` (for example SQLite) runs without the lock.

Stored key configurations keep the gate active even when their rows are not autoloaded. An empty or invalid configuration stays active but opens nothing.

= What is the wrong-try limit? =

An address that sends 10 wrong keys within 10 minutes is blocked for 15 minutes. While blocked, every key header from that address is ignored, **also a right key**, and the status route answers `"key_header": "blocked"`. Novamira's one-time links and a manual open still work for a blocked address. Addresses are never stored in clear text; only a salted hash is kept. Clear every block with the **Clear blocks** button on the settings page or with `wp nova-gate unblock`. Clearing uses the tries lock and counts blocked addresses from a fresh read. On `busy`, the settings page shows an error and CLI exits 1. Nothing is changed; try again in a moment.

With the limiter enabled, a matching key must pass a fresh database block check before it is accepted. This check takes no lock. A failed read refuses the key and writes nothing.

The table holds at most 200 entries, and a new address is always counted: to make room, the oldest still-counting entry goes first, and only if every other entry is an active block does the block that ends soonest go. An address with no entry is never blocked, so a flood from many addresses cannot lock your hub out. IPv6 addresses are counted per /64 network. An attacker who keeps changing address can restart their count. Nova Gate generates keys from 256 random bits; imported keys are only as strong as their source.

Three constants in `wp-config.php` tune it: `NOVA_GATE_MAX_TRIES` (3–100; `0` turns it off), `NOVA_GATE_IP_HEADER` (the `$_SERVER` key that holds the real address) and `NOVA_GATE_TRUSTED_PROXIES` (the proxies that may supply it – see the next answer).

= I am behind a CDN or a reverse proxy. Anything to know? =

Yes. Behind a CDN or reverse proxy, `REMOTE_ADDR` is the proxy's address. `NOVA_GATE_IP_HEADER` is used only when the request comes from a proxy you listed in `NOVA_GATE_TRUSTED_PROXIES` – a comma list of IPv4/IPv6 addresses and CIDRs of your own proxies, matched against `REMOTE_ADDR`. Without it, or when it has no valid entry, the header is ignored, `REMOTE_ADDR` is used, and the settings page shows a warning.

For a list header like `X-Forwarded-For`, Nova Gate reads from the right and takes the first address that is not a trusted proxy; text to the left of it is ignored, and if every entry is a trusted proxy it takes the left-most. IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) match IPv4 ranges, and entries with a port (`1.2.3.4:5678`, `[2001:db8::1]:443`) work. If an entry inspected while walking the trusted suffix is malformed, Nova Gate uses `REMOTE_ADDR`. Invalid text to the left of the first untrusted address is ignored.

Two examples: for Cloudflare, set `NOVA_GATE_IP_HEADER` to `HTTP_CF_CONNECTING_IP` and `NOVA_GATE_TRUSTED_PROXIES` to Cloudflare's published ranges. For an nginx reverse proxy on the same server, use `HTTP_X_FORWARDED_FOR` and `127.0.0.1`.

Warning: never trust a shared CDN range unless the CDN overwrites the header – other customers of the same CDN could send any address.

Upgrading from v0.2.0? If your `wp-config.php` sets `NOVA_GATE_IP_HEADER`, add `NOVA_GATE_TRUSTED_PROXIES`, or the header is now ignored.

= Which WP-CLI commands are there? =

* `wp nova-gate status [--format=<table|json>]` – state, key source, number of keys, early, timer minutes, addresses blocked now.
* `wp nova-gate key list [--format=<table|json|csv>]` – id, label and creation time of every key. Never a hash, never a key. While `NOVA_GATE_KEY_HASH` is set it prints a warning and lists the constant's entries as `const-1`, `const-2`, … (label "from wp-config.php", created "-"); the stored keys are not listed – the gate ignores them while the constant is set. An entry that is not a valid SHA-256 hash is listed with the label "from wp-config.php (not a valid SHA-256 hash; matches no key)".
* `wp nova-gate key add <id> [--label=<text>] [--hash=<sha256>] [--porcelain]` – adds a key and prints it once; with `--porcelain` the key is the only output; with `--hash` the given hash is stored and only `Success: Key "<id>" stored from its hash.` is printed (with `--porcelain` nothing is printed; exit 0 on success, 1 with the error on stderr).
* `wp nova-gate key remove <id> [--yes]` – removes a key; the last key asks for confirmation unless `--yes`.
* `wp nova-gate open [--minutes=<n>]` – opens the gate by hand for n minutes (5–240; default: the saved timer).
* `wp nova-gate lock` – removes the manual open.
* `wp nova-gate unblock` – forgets every wrong try and block.

= Does Nova Gate change Novamira's code? =

No. It filters the two options Novamira reads (`novamira_ai_abilities_enabled` and `novamira_ai_abilities_domain`) with standard WordPress hooks. When the first key is added it also writes once: it sets the switch off and deletes the domain option. Uninstalling does not restore it; Novamira sets it again when you turn it on.

= Does Nova Gate lock other plugins' MCP routes? =

With an early boot, Nova Gate filters Novamira's switches and adds no REST dispatch guard. Other plugins' routes retain their own authentication and permission checks. On a late boot, the guard applies to every matching path described in the late-boot rules, regardless of which plugin registered it.

= What if other code writes Novamira's switch? =

It must check its own nonce. Nova Gate turns such a write by a logged-in admin into a timed manual open, and ignores it from application-password or logged-out requests – the stored value stays off either way.

= What happens when I deactivate or delete Nova Gate? =

If a key was set while Nova Gate was active, the database switch is off and stays off: Novamira's MCP endpoint stays unavailable until someone turns the switch on again in Novamira (which also sets the domain option again). If no key was ever set, Nova Gate never touched Novamira's options, so the switch is whatever it was. Deleting Nova Gate removes only its own options.

= I see "Nova Gate loaded after Novamira". What now? =

If Nova Gate loads after Novamira, its late-boot guard applies only while the gate is active. Without an accepted key or a manual open, it immediately checks and forces an enabled database switch off and returns 404 for these REST paths and their subroutes: `/novamira`, `/mcp/novamira*`, `/mcp/mcp-adapter-default-server`, and `/wp-abilities/v1/abilities/novamira`. Matching is case-insensitive. Here `*` means any remaining characters in that path segment. An accepted key or manual open bypasses the guard, but cannot undo Novamira's earlier switch read. One-time links alone do not bypass it. Every active request also checks the stored switch at `init`. The settings page warns when Nova Gate loads late; the status route reports `"early": false`. Load Nova Gate before Novamira.

= Does Novamira's OAuth connection still work? =

Newer Novamira versions add a second endpoint, `/wp-json/mcp/novamira-oauth`, for clients that sign in with OAuth and cannot send a custom header. Novamira registers it only while its switch is on, so under Nova Gate it exists only during a manual open (or for requests that carry the key). Open the gate from wp-admin first; the connection stops when the timer ends.

= Is multisite supported? =

Not yet.

== Changelog ==

= 0.2.1 =
* `NOVA_GATE_IP_HEADER` now counts only when the request comes from a proxy listed in the new `NOVA_GATE_TRUSTED_PROXIES` constant (a comma list of your own proxy IPs/CIDRs). Sites that set `NOVA_GATE_IP_HEADER` under v0.2.0 must add it, or the header is ignored – the settings page warns.
* Late boot: while the gate is active, requests without an accepted key or manual open trigger an immediate switch check and receive 404 for the guarded routes described above. Inactive gates leave routes alone.
* The first key added now also deletes Novamira's domain option (`novamira_ai_abilities_domain`); Novamira sets it again when you turn it on.
* The key header is also read when Apache passes it as `REDIRECT_HTTP_X_NOVA_GATE_KEY`.
* Adding and removing keys takes a short database lock. Counting wrong tries and clearing blocks share a separate tries lock. A key change or unblock that waits more than 5 seconds fails cleanly ("Another change is in progress. Nothing was changed; try again in a moment."). Lock errors and failed fresh reads also refuse the change. Only a database without `GET_LOCK` runs without the lock.
* The wrong-try table holds at most 200 entries and a new address is always counted, so a flood from many addresses cannot crowd your hub out. IPv6 addresses count per /64 network.
* Address ids use a nonempty string `NONCE_SALT`, otherwise an existing or newly saved random `nova_gate_salt`, otherwise a stable site-derived value if saving a salt fails. Uninstall removes `nova_gate_salt`.
* An empty v0.1.0 key option is listed as `default`, "Empty legacy key (cannot match; add a key)".
* A `rest_route` value counts only on the front controller, never on admin-ajax.php, wp-admin or xmlrpc.php.
* `wp nova-gate key list` lists `NOVA_GATE_KEY_HASH` entries as `const-1`, `const-2`, …; `key add --hash` prints no key, only a Success line (nothing with `--porcelain`).
* A manual open also works on sites that turn autoload off for new options.
* With an early boot, other plugins' routes retain their own authentication and permission checks. On a late boot, the guard applies to every matching path, regardless of which plugin registered it.
* Stored key configurations keep the gate active even when their rows are not autoloaded.
* With the limiter enabled, matching keys pass a fresh block read without a lock. A failed read refuses the key and writes nothing.

= 0.2.0 =
* Several named keys per site (up to 20), each removable on its own. The single key of v0.1.0 keeps working as the key `default`.
* Wrong-try limit: 10 wrong keys within 10 minutes block the address for 15 minutes, also for a right key. Addresses are stored hashed. New constants `NOVA_GATE_MAX_TRIES` and `NOVA_GATE_IP_HEADER`. "Clear blocks" on the settings page.
* The status route can answer `"key_header": "blocked"`.
* WP-CLI: `wp nova-gate status | key list | key add | key remove | open | lock | unblock`.
* `NOVA_GATE_KEY_HASH` may hold several hashes.
* Uninstall also removes `nova_gate_keys` and `nova_gate_tries`.

= 0.1.0 =
* First release.
