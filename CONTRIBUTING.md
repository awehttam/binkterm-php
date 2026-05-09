# Contributing to BinktermPHP

Thank you for your interest in contributing to BinktermPHP! This document provides guidelines and instructions for contributing to the project.

## Table of Contents

- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Code Conventions](#code-conventions)
- [Making Changes](#making-changes)
- [Database Migrations](#database-migrations)
- [Version Management](#version-management)
- [Testing](#testing)
- [Submitting Changes](#submitting-changes)

## Looking for Something to Work On?

If you're considering getting involved, check out **[HELP_WANTED.md](HELP_WANTED.md)** for an overview of the areas where contribution would have the most impact — from FTN protocol work and DOS door integration to WebDoors game development and UI themes.

## Getting Started

BinktermPHP is a PHP/PostgreSQL BBS and FTN mail system that combines a modern web interface, terminal access, and a built-in binkp mailer. It lets users read and write netmail and echomail, exchange packets with FidoNet-style networks, and run BBS features such as file areas, doors, chat, and web-based games. We suggest familiarizing yourself with:

- FidoNet Technology Network (FTN) basics
- The binkp protocol
- PHP development best practices
- PostgreSQL database operations

Also read [CLAUDE.md](CLAUDE.md) before starting development. It contains additional project-specific notes, conventions, and operational gotchas that contributors should follow.

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- PostgreSQL database
- Composer for dependency management
- Git for version control

### Initial Setup

1. **Fork the repository** on GitHub by clicking the "Fork" button on the [project page](https://github.com/awehttam/binkterm-php). This creates your own copy of the repo under your GitHub account.

2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/binkterm-php.git
   cd binkterm-php
   ```

3. **Add the upstream remote** so you can pull in future changes:
   ```bash
   git remote add upstream https://github.com/awehttam/binkterm-php.git
   ```

4. Install dependencies:
   ```bash
   composer install
   ```

5. Set up your database and configuration files. For a full local install, follow the installation and configuration steps in [README.md](README.md). In most development environments you will create a PostgreSQL database, configure `.env`, then run one of:
   ```bash
   php scripts/install.php
   php scripts/setup.php
   ```

6. Check out the `claudesbbs` branch, update it from upstream, and create your feature branch from there:
   ```bash
   git fetch upstream
   git checkout claudesbbs
   git merge --ff-only upstream/claudesbbs
   git checkout -b feature/your-feature-name
   ```

**Important**: Never push directly to `main` or `claudesbbs`. All changes must go through pull requests for review.

## Code Conventions

### Naming Conventions

- **Variables and functions**: camelCase (e.g., `$userName`, `getUserData()`)
- **Classes and components**: PascalCase (e.g., `MessageHandler`, `BinkpConfig`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `MAX_CONNECTIONS`)

### Indentation

- Use **4 spaces** for indentation (no tabs)
- Be consistent with existing code formatting

### File Organization

- `src/` - Main source code
- `templates/` - Twig HTML templates
- `public_html/` - Web site files and static assets
- `tests/` - Test and debugging scripts
- `config/` - Configuration files
- `database/migrations/` - Database migration scripts
- `vendor/` - Third-party libraries (managed by Composer, do not modify)

### Important Guidelines

- **Never modify the vendor directory** - it's managed by Composer
- Use AJAX requests for web interface queries
- Keep feature parity between netmail and echomail when appropriate
- Use `Config::env('VAR_NAME', 'default')` for environment variables instead of `getenv()` or `$_ENV`
- Use `BinktermPHP\Binkp\Logger` or the shared logger helpers for application logging; do not add new `error_log()` calls
- Use `UserStorage` in `public_html/js/user-storage.js` instead of direct `localStorage` access
- Use the admin daemon when web code needs to save settings or write project files. The web server process does not always run with the same filesystem permissions as the BBS daemons, so routes and controllers should not assume they can write configuration or runtime files directly.
- Store database timestamps in UTC unless a schema or protocol field explicitly requires otherwise. For absolute event times, prefer PostgreSQL `TIMESTAMPTZ` columns with defaults such as `created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()`; PostgreSQL stores these as absolute instants independent of the server's local time zone. If you must write to an existing UTC `TIMESTAMP WITHOUT TIME ZONE` column, use expressions such as `NOW() AT TIME ZONE 'UTC'` so stored values do not depend on the database server's time zone. Use plain `TIMESTAMP WITHOUT TIME ZONE` only for intentional local wall-clock values. Convert timestamps to the user's time zone only when presenting them in the UI or terminal output.
- Write secure code - avoid SQL injection, XSS, command injection, and other OWASP Top 10 vulnerabilities

## Making Changes

### Before You Code

1. Check existing issues and pull requests to avoid duplicate work
2. For new features, consider opening an issue first to discuss the approach
3. Understand the existing architecture and patterns in the codebase

### While Coding

1. Follow the existing code style and conventions
2. Write clear, self-documenting code with meaningful variable names
3. Add comments only where the logic isn't self-evident
4. Keep functions focused and reasonably sized
5. Avoid premature optimization - prioritize clarity

### Project-Specific Checks

- If you add or change user-facing text in Twig, JavaScript, or API errors, update every locale under `config/i18n/` and run:
  ```bash
  php scripts/check_i18n_hardcoded_strings.php
  php scripts/check_i18n_error_keys.php
  ```
- If you change CSS, JavaScript, or i18n catalogs, increment the service worker cache version in `public_html/sw.js` so browsers fetch the new assets.
- If you change `public_html/js/binkstream-worker-v2.js`, increment `WORKER_BUILD` in `public_html/js/binkstream-client.js`.
- If you update `public_html/css/style.css`, update the theme stylesheets as needed: `amber.css`, `dark.css`, `greenterm.css`, and `cyberpunk.css`.
- If you add documentation under `docs/` outside `docs/proposals/`, update `docs/index.md`.
- If a web route or controller needs to write project configuration files, use the admin daemon path instead of writing files directly from the web process.

### Security Considerations

Always validate and sanitize:
- User input
- External API data
- Database queries (use prepared statements)
- File paths and operations

Never:
- Trust user input without validation
- Expose sensitive configuration data
- Use dynamic SQL queries without parameterization
- Store passwords in plain text

## Database Migrations

### Creating Migrations

All database schema changes must be done through migration scripts.

1. Create a timestamped migration file in `database/migrations/` following the naming convention:
   ```
   vYYYYMMDDHHMMSS_<description>.sql
   ```
   Example: `v20260503143000_add_user_preferences.sql`

   Prefer the helper command so timestamps are generated consistently in UTC:
   ```bash
   php scripts/migration.php create "add user preferences"
   php scripts/migration.php create "backfill user data" php
   ```

2. Use SQL or PHP migrations as appropriate. See [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md) for PHP migration patterns.

3. Write idempotent migrations when possible (safe to run multiple times).

4. Test migrations through the setup flow:
   ```bash
   php scripts/setup.php
   ```

Do not update `src/Version.php` or `composer.json` just because you added a migration. Application version bumps are handled separately during release preparation unless a maintainer explicitly asks you to do the version bump as part of your change.

### Migration Best Practices

- Use transactions where appropriate
- Include rollback procedures in comments
- Test with realistic data volumes
- Document any manual steps required
- Do not add a separate non-unique index for a column that already has a `UNIQUE` constraint; PostgreSQL creates an index for unique constraints automatically

## Version Management

BinktermPHP uses semantic versioning (MAJOR.MINOR.PATCH):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes, backwards compatible

Contributors typically do not need to update `src/Version.php`, `composer.json`, or create release upgrade documents. Maintainers handle version bumps during release preparation unless they explicitly request otherwise.

When a change adds a required Composer package, document the upgrade requirement in the relevant `docs/UPGRADING_x.x.x.md` file if one already exists for the active release cycle. The upgrade instructions must tell operators to run `composer update` before `php scripts/setup.php`.

## Testing

### Manual Testing

1. Test your changes in a development environment
2. Verify both success and error cases
3. Test edge cases and boundary conditions
4. Check for regressions in existing functionality
5. Run any relevant validation scripts for the area you changed

### Test Scripts

Use the scripts in the `tests/` directory for debugging and troubleshooting:
- Test message handling
- Verify packet processing
- Validate kludge line generation
- Check address parsing

### Areas to Test

- **Netmail**: Message composition, replies, addressing
- **Echomail**: Area subscriptions, message posting, threading
- **Binkp**: Connection handling, polling, packet transfer
- **Web Interface**: Form submissions, AJAX requests, user interactions
- **Character Encoding**: CP437 and UTF-8 handling
- **Date Parsing**: FidoNet timestamp formats

## Submitting Changes

### Commit Messages

Write clear, descriptive commit messages:

```
Fix parameter order in system config save handler

Corrected the parameter mapping in BinkpController::updateConfig()
to properly align form data with the setSystemConfig() method
signature. Previously, fields were shifted causing address to save
as name, sysop as address, etc.
```

Format:
- First line: Brief summary (50-72 characters)
- Blank line
- Detailed description if needed
- Reference related issues: `Fixes #123` or `Related to #456`

### Pull Request Process

**Important**: All changes must be submitted via pull request targeting the `claudesbbs` branch. Do not push directly to `main` or `claudesbbs`.

The `claudesbbs` branch is a staging branch deployed to [Claude's BBS](https://claudes.lovelybits.org), where changes are tested in a live environment before being merged into `main` for release.

1. **Sync your fork** with the latest upstream changes, then rebase your feature branch:
   ```bash
   git fetch upstream
   git checkout claudesbbs
   git merge --ff-only upstream/claudesbbs
   git checkout your-feature-branch
   git rebase claudesbbs
   ```

2. **Push your feature branch** to your fork:
   ```bash
   git push origin your-feature-branch
   ```

3. **Submit a Pull Request** on GitHub:
   - Navigate to your fork on GitHub
   - Click "Pull requests" → "New pull request"
   - Set the **base repository** to `awehttam/binkterm-php` and **base branch** to `claudesbbs`
   - Set the **head repository** to your fork and **compare branch** to your feature branch
   - Use a clear, descriptive title
   - Describe what changes you made and why
   - Reference any related issues (e.g., "Fixes #123")
   - Include screenshots for UI changes
   - List any breaking changes

4. **Wait for review**:
   - Maintainers will review your pull request
   - Be open to suggestions and constructive criticism
   - Make requested changes in new commits
   - Push updates to your feature branch (they'll appear in the PR automatically)

5. **After approval**:
   - Maintainers will merge your PR into `claudesbbs`
   - You can then delete your feature branch

### PR Checklist

Before submitting, ensure:
- [ ] Code follows project conventions
- [ ] No sensitive data or credentials committed
- [ ] Changes tested locally
- [ ] Relevant validation scripts run, especially i18n checks for UI/API text changes
- [ ] Documentation updated if needed
- [ ] Database migrations created if schema changed
- [ ] `php scripts/setup.php` run if migrations or setup-managed files changed
- [ ] Service worker cache version bumped if CSS, JavaScript, or i18n catalogs changed
- [ ] No new security vulnerabilities introduced
- [ ] Code is properly formatted and commented

## Questions or Need Help?

- Open an issue for bugs or feature requests
- Join discussions in existing issues
- Check the project wiki for additional documentation
- Review existing code for examples and patterns

## Code of Conduct

- Be respectful and constructive
- Welcome newcomers and help them learn
- Focus on what's best for the project
- Accept constructive criticism gracefully

## License

By contributing to BinktermPHP, you agree that your contributions will be licensed under the same license as the project.

---

Thank you for contributing to BinktermPHP! Your efforts help keep FidoNet alive and thriving in the modern era.
