# Security Policy

Nova Gate is a security plugin, so we take reports about it seriously.

## Supported versions

| Version | Supported |
|---|---|
| 0.2.x (latest: 0.2.2) | Yes |
| 0.1.x | No — update to 0.2.2 |

Only the latest release gets security fixes.

## Report a vulnerability

**Do not open a public issue, pull request or discussion for a security problem.**

Report it privately on GitHub:

1. Go to the [Security tab](https://github.com/Mariomarquezt/nova-gate/security) of this repository.
2. Click **Report a vulnerability**.
3. Fill in the form.

Only the maintainers can see the report. We reply in the same private thread.

Please include:

- The Nova Gate version, the WordPress version and the Novamira version.
- Whether Nova Gate loads early (before Novamira) or late. The status route
  `/wp-json/nova-gate/v1/status` shows this as `"early"`.
- The steps or the request that shows the problem.
- What happened, and what you expected to happen.

Never send a real key, a real application password or other live credentials in a report. Use a
test site.

## What is in scope

- A request without an accepted key that reaches Novamira's abilities while no manual open runs.
- A way to learn a key or its hash from outside the site.
- A way to get around the wrong-try limit, or to lock out a caller that has the right key.
- A change to the stored keys or to Novamira's switch without the right permission.

## What is not in scope

- Bugs in Novamira itself. Report those to the Novamira team.
- Behaviour that the README describes. Examples: every authenticated caller gets in while a manual
  open runs, and `NOVA_GATE_IP_HEADER` is ignored without `NOVA_GATE_TRUSTED_PROXIES`.
- Problems that need admin access, or write access to `wp-config.php` or the plugin files.

## After a fix

We publish a new release and a GitHub security advisory. If you want, we credit you in the advisory.
