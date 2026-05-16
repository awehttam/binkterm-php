# CLI Scripts

- All PHP scripts in this directory must include the shebang line `#!/usr/bin/env php` at the top.
- CLI scripts must include `src/functions.php` after autoload to access global functions like `generateTzutc()`: `require_once __DIR__ . '/../src/functions.php';`
- Do not use `PHP_BINARY` from web requests to launch CLI scripts. Under php-fpm it points at the FPM SAPI, not the CLI interpreter. Invoke executable scripts directly via their shebang, or use a real CLI `php` path when you explicitly need the interpreter.
- Scripts must be made executable with `chmod +x` and marked as executable in git with `git update-index --chmod=+x scripts/filename.php`.
- When adding or removing a script in `scripts/`, update `docs/CLI.md`.
