# Telnet Daemon

- `telnet/telnet_daemon.php` and `ssh/ssh_daemon.php` manually `require_once` telnet-side classes from `telnet/src/`. New classes under `telnet/src/` are **not** Composer-autoloaded for those daemons. When adding a class there, update the `require_once` lists in both daemon entrypoints as needed.
- Keep the SSH daemon include list in sync with the telnet daemon include list for shared terminal-side classes. If `telnet/telnet_daemon.php` gains a new `telnet/src/` include that SSH sessions also use, add the same include to `ssh/ssh_daemon.php`.
