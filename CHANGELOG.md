# Changelog

All notable changes to Nova Gate are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.1] - 2026-10-15

### Added

- New constant `NOVA_GATE_TRUSTED_PROXIES`: a comma list of the IPv4/IPv6 addresses and CIDRs of
  your own proxies.
- The key header is also read when Apache passes it as `REDIRECT_HTTP_X_NOVA_GATE_KEY`.
- `wp nova-gate key list` shows the entries of `NOVA_GATE_KEY_HASH` as `const-1`, `const-2`, …
  (label "from wp-config.php", created "-").
- Without a usable `NONCE_SALT`, address ids use an existing or newly saved random
  `nova_gate_salt`. If saving fails and no usable stored salt exists, they use a stable site-derived
  value. Uninstall removes `nova_gate_salt`.

### Changed

- `NOVA_GATE_IP_HEADER` is now honoured only when the request comes from a trusted proxy
  (`REMOTE_ADDR` in `NOVA_GATE_TRUSTED_PROXIES`). Sites that set `NOVA_GATE_IP_HEADER` under
  v0.2.0 must add the new constant, or the header is ignored and the settings page warns.
- The wrong-try table holds at most 200 entries, and a new address is always counted: the oldest
  still-counting entry makes room first, and only if every other entry is an active block does the
  block that ends soonest go. A flood from many addresses can no longer lock your hub out.
- IPv6 addresses are now counted per /64 network.
- Adding the first key now also deletes Novamira's domain option
  (`novamira_ai_abilities_domain`). Novamira sets it again when you turn it on.
- A `rest_route` value counts only on the front controller (the home URL, `/index.php`, or the
  REST prefix), never on `admin-ajax.php`, `wp-admin` or `xmlrpc.php`.
- `wp nova-gate key add --hash=<sha256>` prints no key, only `Success: Key "<id>" stored from its
  hash.` (nothing at all with `--porcelain`).
- With an early boot, Nova Gate filters Novamira's switches and adds no REST dispatch guard.
  Other plugins' routes retain their own authentication and permission checks. On a late boot,
  the guard applies to every matching path described below, regardless of which plugin registered
  it. This includes the shared `/wp-json/mcp/mcp-adapter-default-server` route.

### Fixed

- Late boot: while the gate is active, requests without an accepted key or manual open trigger
  an immediate check that forces an enabled database switch off. The guard returns 404 for
  `/novamira`, `/mcp/novamira*`, `/mcp/mcp-adapter-default-server`,
  `/wp-abilities/v1/abilities/novamira`, and their subroutes, case-insensitively.
  Here `*` means any remaining characters in that path segment. Inactive gates leave routes alone.
  Every active request also checks the stored switch at `init`.
- Adding and removing keys now take a short database lock (MySQL/MariaDB `GET_LOCK`).
  Counting wrong tries and clearing blocks share a separate tries lock. A key change or unblock
  that waits more than 5 seconds fails with "Another change is in progress. Nothing was changed;
  try again in a moment." Lock errors and failed fresh reads also refuse the change.
  Only a database without `GET_LOCK` runs without the lock.
- Stored key configurations keep the gate active even when their rows are not autoloaded. A failed
  read of those rows also keeps the gate active and matches no key; it never turns the gate off.
- With the limiter enabled, matching keys pass a fresh block read without a lock. A failed read
  refuses the key and writes nothing.
- An empty v0.1.0 key option is now listed as `default`, "Empty legacy key (cannot match; add a
  key)", so it can be removed after adding a real key.
- A manual open now also works on sites that turn autoload off for new options.

## [0.2.0] - 2026-10-03

### Added

- Several named keys per site (up to 20), each removable on its own. The single key of v0.1.0
  keeps working as the key `default`.
- Wrong-try limit: 10 wrong keys within 10 minutes block the address for 15 minutes, also for a
  right key. Addresses are stored hashed. New constants `NOVA_GATE_MAX_TRIES` and
  `NOVA_GATE_IP_HEADER`. "Clear blocks" on the settings page.
- The status route can answer `"key_header": "blocked"`.
- WP-CLI: `wp nova-gate status | key list | key add | key remove | open | lock | unblock`.
- `NOVA_GATE_KEY_HASH` may hold several hashes.
- Uninstall also removes `nova_gate_keys` and `nova_gate_tries`.

## [0.1.0] - 2026-10-02

- First release.
