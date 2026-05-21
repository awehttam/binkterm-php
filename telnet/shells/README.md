# Terminal Shell Plugins

Custom terminal shells can be added by dropping PHP definition files into this
directory.

A minimal working example is included:

- `ExampleGreenScreenShell.plugin.php`
- `ExampleGreenScreenShellClass.php`

## Plugin File Contract

Each `*.plugin.php` file in `telnet/shells/` is loaded by
`BinktermPHP\TerminalShellRegistry` and must return either:

- one shell definition array
- or an array of shell definition arrays

Minimum definition:

```php
<?php

require_once __DIR__ . '/RetroGlassShellClass.php';

return [
    'id' => 'retroglass',
    'class' => \Custom\Binkterm\RetroGlassShell::class,
    'label' => 'Retro Glass',
];
```

Optional fields:

- `admin_label`
- `settings_label`
- `admin_label_key`
- `settings_label_key`

If only `label` is provided, it is used in both the admin selector and the
terminal user settings selector.

## Class Requirements

- The shell class must implement `BinktermPHP\TelnetServer\TerminalShellInterface`
  directly or extend an existing shell class such as `TuiShell` or `LineShell`.
- The constructor must accept `BinktermPHP\TelnetServer\BbsSession`:

```php
public function __construct(BbsSession $server)
```

## Activation

Add the shell ID to the `.env` variable `TERMSERVER_ALLOWEDSHELLS` to make it
selectable:

```text
TERMSERVER_ALLOWEDSHELLS=tui retroglass
```

If a shell ID is not listed there, it will not appear in user settings or admin
shell selectors.
