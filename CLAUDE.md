# Project: binktest

## Project Description

A modern web interface and mailer tool that receives and sends Fidonet message packets using the binkd Fidonet mailer.  The project
provides users with a delighftful, modern web experience that allows them to send and receive netmail (private messages) and echomail (forums) with the help of the binkd daemon.

## Tech Stack

 - Frontend: jQuery, Bootstrap 5
 - Backend: PHP, SimpleRouter request library, Twig templates
 - Database: SQLite
 

## Code Conventions

 - camelCase for variables and functions
 - PascalCase for components and classes
 - 4 space indents

## Project Structure

 - src/ - main source code
 - templates/ - html templates
 - public_html/ - the web site files, static assets
 - tests/ - test scripts used in debugging and trouble shooting

## Important Notes
 - User authentication is simple username and password with long lived cookie
 - The web interface should use ajax requests by api for queries
 - This is for FTN style networks and forums.  
 - Always write out schema changes. A database will need to be created from scratch and schema/migrations are how it needs to be done..
 
 
## Known Issues
 - Regular users have too much permission.  For example, a regular user should not be able to poll the uplinks.
 - Some technical information on the protocols used by 'binkp' are old and may be difficult to find
 - For some reason new echoareas are created if a parsing error occurs on incoming packets.  
 
## Future Plans
 - Using this as a library in future applications
 - Adding QWK support
 - Adding support for the user settings: Dark Mode, Messages per page, Timezone.
 - Working echomail
 - Less bugs
 - Add support for zipped bundles of packets
  
  
