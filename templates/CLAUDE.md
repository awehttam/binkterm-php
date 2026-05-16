# Templates

- Template resolution order is `templates/custom/` → `templates/shells/<activeShell>/` → `templates/`. The active shell (`web` or `bbs-menu`) has its own `base.twig` at `templates/shells/web/base.twig` and `templates/shells/bbs-menu/base.twig` which take priority over `templates/base.twig`.
- When adding nav links or modifying shared layout, you must update **both** `templates/base.twig` AND `templates/shells/web/base.twig` (and `bbs-menu` if applicable).
