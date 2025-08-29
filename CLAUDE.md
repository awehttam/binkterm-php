# Project: binkterm-php

## Project Description

A modern web interface and mailer tool that receives and sends Fidonet message packets using the binkd Fidonet mailer.  The project
provides users with a delighftful, modern web experience that allows them to send and receive netmail (private messages) and echomail (forums) with the help of the binkd daemon.

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

## Future Plans
 - Using the binkp library in other applications
 - Less bugs
  
  
