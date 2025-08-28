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

   
 - 
## Known Issues
 - Some technical information on the protocols used by 'binkp' are old and may be difficult to find
 - The date/time is wrong for messages.  I suspect there is timezone conversion going on causing skew.  Most likely related to the sort order of the Received messages (by Received date) is wrong - likely due to the time skew issue reported previously.
 - We strip the domain (ie: @fidonet) from the remote host presented during a bink poll.  At some point we should add @domain support.

## Future Plans
 - Using the binkp library in other applications
 - Less bugs
  
  
