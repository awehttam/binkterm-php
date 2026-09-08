# Security Policy

## Supported Versions

BinktermPHP is developed as a rolling release. Security fixes are applied to
the latest released version and the `claudesbbs` staging branch. There is no
long-term support for older tagged releases — please keep your installation
up to date with the most recent release.

| Version | Supported |
| ------- | --------- |
| Latest release | :white_check_mark: |
| `claudesbbs` (staging) | :white_check_mark: |
| Older releases | :x: |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues,
pull requests, discussions, or the support BBS.**

Instead, use one of the following private channels:

1. **GitHub Private Vulnerability Reporting** (preferred) — open a report via
   the **Security** tab of the repository
   (<https://github.com/awehttam/binkterm-php/security/advisories/new>).
2. **Email** — <awehttam@gmail.com>.

Please include as much of the following as you can:

- The affected component (web UI, API, BinkP mailer, Telnet/SSH daemon,
  door framework, admin daemon, etc.) and file paths if known.
- BinktermPHP version or commit hash, PHP version, and deployment type
  (bare metal, Docker).
- A description of the issue and its impact (e.g. authentication bypass,
  RCE, SQL injection, XSS, SSRF, privilege escalation, information
  disclosure).
- Step-by-step reproduction instructions or a proof of concept.
- Any suggested remediation.

## Scope

In scope:

- The BinktermPHP application code in this repository (`src/`, `routes/`,
  `public_html/`, `templates/`, `scripts/`, `telnet/`, `ssh/`,
  `mcp-server/`, `dosbox-bridge/`, `tools/`).
- Default configuration shipped in the repository.

Out of scope:

- Third-party libraries in `vendor/` and `node_modules/` — report those to
  their respective maintainers (but let us know so we can bump the
  dependency).
- Vulnerabilities requiring physical access to the server or a
  pre-compromised host.
- Findings that depend on insecure sysop configuration contrary to the
  documented guidance in `docs/`.
- Denial of service through sheer traffic volume, social engineering of
  sysops or users, and self-XSS.


