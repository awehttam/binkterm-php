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
- [Changelog Updates](#changelog-updates)

## Looking for Something to Work On?

If you're considering getting involved, check out **[HELP_WANTED.md](HELP_WANTED.md)** for an overview of the areas where contribution would have the most impact — from FTN protocol work and DOS door integration to WebDoors game development and UI themes.

## Getting Started

BinktermPHP is a modern web interface and mailer tool for FidoNet message packets using the binkp protocol. Before contributing, please familiarize yourself with:

- FidoNet Technology Network (FTN) basics
- The binkp protocol
- PHP development best practices
- PostgreSQL database operations

## Development Setup

### Prerequisites

- PHP 7.4 or higher
- PostgreSQL database
- Composer for dependency management
- Git for version control

### Initial Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/awehttam/binkterm-php.git
   cd binkterm-php
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Set up your database and configuration files

4. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

**Important**: Never push directly to the `main` branch. All changes must go through pull requests for review.

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
- `schema/` - Database migration scripts
- `vendor/` - Third-party libraries (managed by Composer, do not modify)

### Important Guidelines

- **Never modify the vendor directory** - it's managed by Composer
- Use AJAX requests for web interface queries
- Keep feature parity between netmail and echomail when appropriate
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

All database schema changes must be done through migration scripts:

1. Create a new migration file in `schema/` following the naming convention:
   ```
   v<VERSION>_<description>.sql
   ```
   Example: `v1.5.0_add_user_preferences.sql`

2. Write idempotent migrations when possible (safe to run multiple times)

3. Test migrations on a clean database to ensure they work from scratch

4. **Version Bump Required**: When changing the database version through a migration, you must update:
   - `src/Version.php` - Update the VERSION constant
   - `composer.json` - Update the version field

### Migration Best Practices

- Use transactions where appropriate
- Include rollback procedures in comments
- Test with realistic data volumes
- Document any manual steps required

## Version Management

BinktermPHP uses semantic versioning (MAJOR.MINOR.PATCH):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes, backwards compatible

### Updating the Version

When releasing a new version:

1. Update `src/Version.php`:
   ```php
   private const VERSION = '1.4.3';
   ```

2. Update `composer.json`:
   ```json
   "version": "1.4.3"
   ```

3. Commit and tag:
   ```bash
   git add src/Version.php composer.json
   git commit -m "Bump version to 1.4.3"
   git tag -a v1.4.3 -m "Release version 1.4.3"
   ```

Note: Contributors typically don't need to worry about version bumps - maintainers handle this during release preparation.

## Testing

### Manual Testing

1. Test your changes in a development environment
2. Verify both success and error cases
3. Test edge cases and boundary conditions
4. Check for regressions in existing functionality

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

**Important**: All changes must be submitted via pull request. Do not push directly to the `main` branch.

1. **Update your branch** with the latest main:
   ```bash
   git checkout main
   git pull
   git checkout your-feature-branch
   git rebase main
   ```

2. **Push your feature branch** to the repository:
   ```bash
   git push origin your-feature-branch
   ```

3. **Submit a Pull Request** on GitHub:
   - Navigate to the repository on GitHub
   - Click "Pull requests" → "New pull request"
   - Select your feature branch
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
   - Maintainers will merge your PR into main
   - You can then delete your feature branch

### PR Checklist

Before submitting, ensure:
- [ ] Code follows project conventions
- [ ] No sensitive data or credentials committed
- [ ] Changes tested locally
- [ ] Documentation updated if needed
- [ ] Database migrations created if schema changed
- [ ] Changelog updated for significant changes
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
