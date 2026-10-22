# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.1.x   | ✅ Security fixes |
| 1.0.x   | ❌ Please upgrade (run `database/migrations/2026-10-guvenlik.sql`) |

Only the latest release receives fixes. Version 1.0 shipped with a known default admin password hash and without CSRF protection on admin forms; do not run it on a public server.

## Reporting a Vulnerability

Please **do not open a public issue** for security problems.

Report privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** (private vulnerability reporting). Only the maintainers can see the report.

Please include:

- the affected version or commit,
- the component (chat API, admin panel page, module, BERT service ...),
- steps to reproduce or a proof of concept,
- the impact you expect (data exposure, code execution, account takeover ...).

What to expect:

- an acknowledgement within 7 days,
- an assessment and a plan, or questions, within 14 days,
- a fix in a new release, credited to you in `CHANGELOG.md` unless you prefer otherwise.

Please give us reasonable time to release a fix before any public disclosure, and only test against your own installation.

## Scope

In scope: the code in this repository (PHP engine, admin panel, modules, `bert-model/` scripts).

Out of scope: problems that need an already compromised server or `config/db.php`, settings documented as unsafe for production (for example `$ADMIN_KOD_EDITORU = true`, which deliberately gives full admins PHP and SQL access), and third-party services such as Groq.

## Hardening Checklist for Operators

- Set a long random `$IP_TUZU` and keep `config/db.php` outside the web root or protected.
- Keep `$ADMIN_KOD_EDITORU = false` in production.
- Serve the site over HTTPS so session cookies get the `Secure` flag.
- List reverse proxies in `$GUVENILIR_VEKILLER` only if you actually use one.
- Keep the BERT service on `127.0.0.1`, or set `BERT_API_KEY` if it must be reachable from elsewhere.
- Restrict `$SCRAPER_IZINLI_HOSTLAR` to the hosts you need.
