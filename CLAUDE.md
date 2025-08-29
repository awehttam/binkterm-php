# Project: binkterm-php

## Project Description

A modern web interface and mailer tool that receives and sends Fidonet message packets using its own binkp Fidonet mailer.  The project
provides users with a delighftful, modern web experience that allows them to send and receive netmail (private messages) and echomail (forums) with the help of binkp.

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
 - templates/ - html templates
 - public_html/ - the web site files, static assets
 - tests/ - test scripts used in debugging and trouble shooting
 - vendor/ - 3rd party libraries managed by composer and should not be touched by Claude.
 - 
## Important Notes
 - User authentication is simple username and password with long lived cookie
 - The web interface should use ajax requests by api for queries
 - This is for FTN style networks and forums.  
 - Always write out schema changes. A database will need to be created from scratch and schema/migrations are how it needs to be done. Migration scripts follow the naming convention v<VERSION>_<description>.sql, eg: v1.1.0_description.sql
 - When adding features to netmail and echomail, keep in mind feature parity.  Ask for clarification about whether a feature is appropriate to both. 
 - Leave the vendor directory alone. It's managed by composer only.

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

   
 - 
## Recent Features Added

### Webshare Feature (v1.4.1)
- **Message Sharing**: Users can share echomail messages via secure web links
- **Privacy Controls**: Public (anonymous access) vs private (login required) sharing options
- **Expiration Settings**: Links can expire after 1 hour, 24 hours, 1 week, 30 days, or never
- **Access Management**: Share revocation, access statistics, and usage tracking
- **Mobile Responsive**: Clean shared message view works on all devices
- **Security**: Unique 32-character share keys, user permission validation, rate limiting

### Character Encoding Improvements
- **Enhanced CP437 Support**: Added iconv fallback for better DOS codepage handling
- **Graceful Degradation**: Multiple encoding conversion strategies with error handling
- **FidoNet Compatibility**: Improved handling of legacy character encodings in message packets

### Date Parsing Fixes
- **Regex Pattern Improvements**: Fixed date parsing for FidoNet timestamps
- **Format Handling**: Better support for "DD MMM YY HH:MM:SS" format messages
- **Timezone Processing**: Enhanced TZUTC offset calculations

## Known Issues
 - Some technical information on the protocols used by 'binkp' are old and may be difficult to find
 - Date parsing occasionally has edge cases with malformed timestamps from various FTN software
 - We strip the domain (ie: @fidonet) from the remote host presented during a bink poll.  At some point we should add @domain support.
 - Postgres is picky about boolean values.  Ensure they are properly cast
 
## Future Plans
 - Using the binkp library in other applications
 - Less bugs
 - Multiple network support (see Multiple Network Support Plan below)
  
## Multiple Network Support Plan

### Current Limitations
- System strips @domain suffixes from FTN addresses (e.g., `1:234/567@fidonet` becomes `1:234/567`)
- Each echoarea has only a single `uplink_address` field
- No way to distinguish between different networks (FidoNet, DoveNet, etc.)
- All echomail treated as belonging to one network

### Proposed Implementation

#### 1. Database Schema Changes
- **networks table**: `id`, `domain`, `name`, `description`, `is_active`, `created_at`
- **echoareas table**: Add `network_id` field referencing networks table
- **uplinks table**: Network-specific uplink configuration

#### 2. Code Changes Required
- **BinkpSession.php**: Stop stripping domains, preserve for routing
- **BinkdProcessor.php**: Route messages based on network domains
- **MessageHandler.php**: Network-aware message handling
- **AdminController.php**: Network management interface

#### 3. Configuration Updates
- **binkp.json**: Network-specific uplink configuration
- Support multiple uplinks per network
- Domain-based routing rules

#### 4. Migration Strategy
- Create networks table with default "fidonet" network
- Migrate existing echoareas to default network
- Update existing uplinks to use network domains
- Preserve backward compatibility

### Benefits
- Support multiple FTN networks simultaneously
- Proper message routing based on @domain
- Network isolation and management
- Scalable architecture for adding new networks

## Version Management

### Overview
BinktermPHP uses a centralized version management system that ensures consistent version numbers across:
- Message tearlines in FidoNet packets
- Web interface footer display
- Package metadata (composer.json)
- API responses and system information

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
- Database migrations have their own versioning system separate from application version

  
