# Upgrading to 1.7.1

This release adds web based configuration editing to the Admin section; for BinkP, system configuration, features, and Webdoors.  

The Configuration tab has also been removed from the Binkp management page, which has also been renamed to "BinkP Status". 


## Before You Upgrade
- Make a backup of your `config/` directory, just to be safe.

## After you upgrade
- Restart the system daemons including admin_daemon, otherwise functionality may be adversely affected.

## What's New
- Online editing for BinkP configuration.
- Online editing for system configuration including available features (ie: webdoors, voting booth, shoutbox, advertisements, etc.)
- Online editing for Webdoors configuration.
- Removed unused settings from binkp.json.example 

## Important Note About the Admin Daemon
After updating configuration from the admin interface, restart the system daemons (notably the binkp daemons) to ensure the new settings are applied.

