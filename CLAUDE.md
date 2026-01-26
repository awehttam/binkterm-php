# Project: binkterm-php

## Project Description

A modern web interface and mailer tool that receives and sends Fidonet message packets using its own binkp Fidonet mailer. The project provides users with a delightful, modern web experience that allows them to send and receive netmail (private messages) and echomail (forums) with the help of binkp.

## Tech Stack

 - Frontend: jQuery, Bootstrap 5
 - Backend: PHP, SimpleRouter request library, Twig templates
 - Database: Postgres
 

## Code Conventions

 - camelCase for variables and functions
 - PascalCase for components and classes
 - 4 space indents

## Project Structure

 - src/ - main source code
 - scripts/ - CLI tools (binkp_server, binkp_poll, maintenance scripts, etc.)
 - templates/ - html templates
 - public_html/ - the web site files, static assets
 - tests/ - test scripts used in debugging and troubleshooting
 - vendor/ - 3rd party libraries managed by composer and should not be touched by Claude
 - data/ - runtime data (binkp.json, nodelists.json, logs, inbound/outbound packets)

## Important Notes
 - User authentication is simple username and password with long lived cookie
 - Both usernames and Real Names are considered unique. Two users cannot have the same username or real name
 - The web interface should use ajax requests by api for queries
 - This is for FTN style networks and forums
 - Always write out schema changes. A database will need to be created from scratch and schema/migrations are how it needs to be done. Migration scripts follow the naming convention v<VERSION>_<description>.sql, eg: v1.7.5_description.sql
 - When adding features to netmail and echomail, keep in mind feature parity. Ask for clarification about whether a feature is appropriate to both
 - Leave the vendor directory alone. It's managed by composer only
 - When updating style.css, also update the theme stylesheets: dark.css, greenterm.css, and cyberpunk.css
 - Database migrations are handled through scripts/setup.php (first time) or scripts/upgrade.php (upgrade)
 - See FAQ.md for common questions and troubleshooting

## URL Construction
When constructing full URLs for the application (e.g., share links, reset password links, meta tags), **always** follow this pattern:

1. **Use SITE_URL environment variable first**: Check `Config::env('SITE_URL')` before falling back to `$_SERVER` variables
2. **Fallback to protocol detection**: Only use `$_SERVER['HTTPS']` and `$_SERVER['HTTP_HOST']` if SITE_URL is not configured

### Why SITE_URL is Important
- The application may be behind an HTTPS proxy/load balancer
- In this scenario, `$_SERVER['HTTPS']` may not be set even though the public-facing URL uses HTTPS
- The SITE_URL environment variable ensures correct URL generation regardless of proxy configuration

### Example Pattern
```php
// Build URL using SITE_URL first
$siteUrl = \BinktermPHP\Config::env('SITE_URL');

if ($siteUrl) {
    // Use configured SITE_URL (handles proxies correctly)
    $url = rtrim($siteUrl, '/') . '/path/to/resource';
} else {
    // Fallback to protocol detection method if SITE_URL not configured
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url = $protocol . '://' . $host . '/path/to/resource';
}
```

### Examples in Codebase
- `MessageHandler::buildShareUrl()` - share link generation
- `routes/web-routes.php:210-222` - shared message page meta tags
- `PasswordResetController` - password reset emails

## Changelog Workflow
 - **IMPORTANT**: When completing significant features, bug fixes, or improvements, ALWAYS update the changelog at `templates/recent_updates.twig`
 - Add entries to the top of the file with the current date
 - Use this format:
   ```html
   <div class="update-item mb-3" data-version="1.4.1" data-date="2025-08-29">
       <div class="d-flex justify-content-between align-items-start">
           <div>
               <h6 class="mb-1"><span class="badge bg-primary me-2">Feature</span>Webshare Functionality</h6>
               <p class="mb-1 text-muted">Users can now share echomail messages via secure web links with privacy controls and expiration settings.</p>
           </div>
           <small class="text-muted">v1.4.1</small>
       </div>
   </div>
   ```
 - Badge types: `bg-primary` (Feature), `bg-success` (Improvement), `bg-warning` (Fix), `bg-info` (Update)
 - Keep entries concise but descriptive
 - Limit to 10-15 most recent entries (remove older ones)
 - This file is automatically displayed on the admin dashboard's "Recent Updates" section

## Recent Features Added

### Webdoors
- **Webdoors**: An API documented in docs/WebDoor_Proposal.md allows drop in games to interface with the BBS

### Multi-Network Support
- **Multiple Networks**: The system supports multiple FTN networks through individual uplinks with domain-based routing
- **Local Echo Areas**: `is_local` flag identifies echoareas for local use only (messages not transmitted to uplinks)
- **ANSI Decoder**: Javascript renderer for ANSI art in echomail messages
- **Hyperlink Detection**: Automatic URL detection and linking in message display

### Gateway Tokens
- **External Authentication**: Gateway tokens provide temporary SSO-like authentication for external services
- **One-Time Use**: Tokens are single-use with configurable expiration (default 5 minutes)
- **API Verification**: External services can verify tokens via API with X-API-Key authentication

### User Management Improvements
- **Unique Real Names**: Case-insensitive unique constraint on real names prevents impersonation
- **Registration Validation**: Duplicate checking for both username and real name during registration

### Packet Logging
- **Dedicated Log File**: Packet processing now logs to `data/logs/packets.log` instead of PHP error log

### Webshare Feature
- **Message Sharing**: Users can share echomail messages via secure web links
- **Privacy Controls**: Public (anonymous access) vs private (login required) sharing options
- **Expiration Settings**: Links can expire after 1 hour, 24 hours, 1 week, 30 days, or never
- **Access Management**: Share revocation, access statistics, and usage tracking

### Character Encoding Improvements
- **Enhanced CP437 Support**: Added iconv fallback for better DOS codepage handling
- **FidoNet Compatibility**: Improved handling of legacy character encodings in message packets

### Date Parsing Fixes
- **Regex Pattern Improvements**: Fixed date parsing for FidoNet timestamps
- **Timezone Processing**: Enhanced TZUTC offset calculations

## Known Issues
 - Some technical information on the protocols used by 'binkp' are old and may be difficult to find
 - Date parsing occasionally has edge cases with malformed timestamps from various FTN software
 - PostgreSQL is picky about boolean values - ensure they are properly cast

## Future Plans
 - More BBS-like features such as multi-user interaction, messaging, games, etc.
 - Continued bug fixes and stability improvements

## Version Management

### Overview
BinktermPHP uses a centralized application version management system that ensures consistent version numbers across:
- Message tearlines in FidoNet packets
- Web interface footer display
- Package metadata (composer.json)
- API responses and system information

Database version is seperate from application version.  Database versions are reflected in the database schema migration files.

### How to Update the Version

When releasing a new version of BinktermPHP, follow these steps:

#### 1. Update the Version Constant
Edit `src/Version.php` and change the `VERSION` constant:

```php
private const VERSION = '1.4.3';  // Update this line
```

#### 2. Update composer.json (Optional but Recommended)
Edit `composer.json` to match the new version:

```json
{
    "name": "binkterm-php/fidonet-web",
    "version": "1.4.3",  // Update this line
    ...
}
```

#### 3. Update Recent Updates Template
Add an entry to `templates/recent_updates.twig` documenting the changes in this version:

```html
<div class="update-item mb-3" data-version="1.4.3" data-date="2025-08-29">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h6 class="mb-1"><span class="badge bg-primary me-2">Feature</span>Version Management System</h6>
            <p class="mb-1 text-muted">Added centralized version management with consistent tearlines and web interface display.</p>
        </div>
        <small class="text-muted">v1.4.3</small>
    </div>
</div>
```

#### 4. Commit and Tag
Commit your changes and create a git tag:

```bash
git add src/Version.php composer.json templates/recent_updates.twig
git commit -m "Bump version to 1.4.3"
git tag -a v1.4.3 -m "Release version 1.4.3"
git push origin main --tags
```

#### 5. Update UPGRADING_x.x.x.md documentation

For new releases we create a document named UPGRADING_x.x.x.md (eg: UPGRADING_1.6.7.md) with a summary of changes and important upgrade instructions

### What Updates Automatically

Once you change the version in `src/Version.php`, the following will automatically use the new version:

- **Message Tearlines**: All outbound FidoNet messages will include `--- BinktermPHP v1.4.3`
- **Web Interface Footer**: All web pages will show "BinktermPHP v1.4.3 on Github"
- **API Responses**: Any code using `Version::getVersionInfo()` will return current version
- **Template Variables**: All Twig templates have access to `{{ app_version }}`, `{{ app_full_version }}`, etc.

### Version Format

BinktermPHP follows semantic versioning (semver):
- **MAJOR.MINOR.PATCH** format (e.g., 1.4.2)
- **Major**: Breaking changes
- **Minor**: New features, backwards compatible
- **Patch**: Bug fixes, backwards compatible

### Version Class Methods

The `Version` class provides several methods for different use cases:

```php
Version::getVersion()        // "1.4.2"
Version::getAppName()        // "BinktermPHP"  
Version::getFullVersion()    // "BinktermPHP v1.4.2"
Version::getTearline()       // "--- BinktermPHP v1.4.2"
Version::getVersionInfo()    // Complete array with all info
Version::compareVersion('1.4.1')  // Version comparison
```

### Notes
- Only edit the version in `src/Version.php` - all other locations will update automatically
- The tearline format follows FidoNet standards (starts with "---" and under 79 characters)
- Version is displayed to users, so keep it professional and consistent
- Database migrations have their own versioning system (v1.7.x_description.sql) separate from application version
- Application version and database migration version are independent and do not need to match