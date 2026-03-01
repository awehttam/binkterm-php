# Upgrading to 1.8.4

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**Security Fixes**
- Username and real name are now cross-checked for uniqueness at the
  database level to prevent misrouting of netmail

**MRC Improvements**
- Added `/register`, `/identify`, `/update`, `/motd`, `/help`, and `/msg` commands
- Trust commands, `/motd`, and `/help` can now be used before joining a room
- Unknown `/commands` are passed through to the server instead of showing an error
- MOTD now displays inline in the chat area instead of a popup modal
- Sent private messages are echoed locally so you can see what you sent
- Input history: use up/down arrow keys to scroll through previously sent messages
- Removed "MRC Under Development" warning modal
- Fixed: black text on dark background when using the default theme
- Fixed: initial room list not populated on daemon connect
- Fixed: LIST response was misrouted as a private message, preventing room list population

**Bug Fixes**
- Compose: sidebar panel can now be collapsed sideways to give the editor more width, with state persisted across page loads
- Echo list: areas can now be opened in a new tab via right-click

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

```bash
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar
php binkterm-installer.phar
scripts/restart_daemons.sh
```
