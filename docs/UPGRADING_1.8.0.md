# UPGRADING_1.8.0

This upgrade note covers changes introduced in version 1.8.0:

 * Conversion to UTC - the database now uses TIMESTAMPTZ and a default connection timezone of UTC for recording time stamps
 * "Bottom" kludge lines (PATH, SEEN-BY, VIA) are now stored separately in the bottom_kludges table of echomail and netmail
 * Via is now placed in the bottom kludge line block during outbound message transmission.
 * Netmail now supports 'd' to download the currently displayed message
 * The new echo list (forum style) page now allows filtering the list by subscribed areas only, as well as areas with unread messages
 * Pipecode color support converts pipecodes to ANSI sequences for fuller color coding support in messages  
 * Fixed node list handling of entries with a custom bink port.  Now, the right menu pop up will use standard https/telnet/ssh ports instead of providing the custom bink port
 * IPv6 addresses in the node list should parse properly
 * Added Webdoor SDK.  Doors like Blackjack and CWN should now update the credit balance displayed in the top menu automatically
 * Added loading indicators to dashboard netmail and echomail stats
 * The BBS ad generator now supports gradient borders
 * Users are now given their own referral link and can earn system credit when a referred user is approved
 * Corrected an issue with incoming insecure binkp sessions that would result in a failed session
 * Insecure netmails are now displayed with a warning that the message was received in an insecure fashion
 * Revert change in 1.7.9 where CRYPT-MD5 would be enforced even when plaintext was specified in configuration.  Plaintext sessions should work again now
 * Add MSGID checks when parsing incoming echomail to prevent duplicate entries (necessary for %rescan)
 * Changed the default echomail landing page to the "forum style" echo list.  Sysops can now set the system wide setting to either the reader interface or echo list interface, and users can (re)set their own personal preference
 * DOS door integration via DOSBox-X and a multiplexing WebSocket bridge, allowing classic BBS door games to be played in the browser
 * DOS door sessions are now scoped per-door, so multiple doors can be open in separate tabs simultaneously
 * Blackjack leaderboard now tracks credits won from hands only (losses do not subtract); score is independent of BBS credits earned elsewhere; leaderboard shows current calendar month
 * Miscellaneous fixes and improvements

# Conversion to UTC

This release makes normalizes timestamps in the postgres database as "UTC".  Previously the system would use either the Postgres or PHP default time zone when inserting or updating data.  Now, the system will use UTC for dates and times.

# BEFORE UPGRADING

Make sure you've made a backup of your database and files prior to upgrading.  

# Upgrading from Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh # or however you manage your daemons
```

# Upgrading using installer

```bash
# Download the installer
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar

# Run the installer
php binkterm-installer.phar
scripts/restart_daemons.sh # or however you manage your daemons
```
