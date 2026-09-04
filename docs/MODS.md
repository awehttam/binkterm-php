# BinktermPHP Mods

A community-curated list of third-party mods, extensions, and tweaks for **BinktermPHP**.

## Submitting a mod

Have a mod you'd like listed here? Open a pull request against the `claudesbbs` branch
that adds an entry to the appropriate section below. Please follow the existing entry
format: a short title, a one- or two-sentence description of what the mod does, the
author (with BBS name and FTN address if applicable), the repo URL, and the hook or
integration point the mod uses.

## Disclaimer

Mods listed here are contributed and maintained by third parties. They **may not have
been tested or vetted by the BinktermPHP developers**. Review the source code of any
mod before installing it on your system. You install and run these mods at your own
risk.

## Mods

### Door Button Filter Mod

Adds a category filter bar to the Doors page (`/games`) — filter door games by type
(RLOGIN, WEB, NATIVE, DOS, JS-DOS, ALL) with live badge counts and instant client-side
filtering. RLOGIN is selected by default. Supports URL hash deep-linking
(`/games#rlogin`) with browser back/forward support.

- **Author:** TheWebExpert (The Adventure BBS, 227:1/22)
- **Repo:** https://github.com/thewebexpert/binkterm-php-doorButtonMod
- **Hook used:** `templates/custom/header.insert.twig`

### Echo Area Button Mod

Adds a quick network-filter and quick-action button bar to the Echo List page
(`/echolist`). Auto-discovers connected FTN networks (e.g. LOVLYNET, FIDONET, FSXNET)
with live area counts, plus one-click toggles for subscribed-only, unread-only, new
post, and manage subscriptions.

- **Author:** TheWebExpert (The Adventure BBS, 227:1/22)
- **Repo:** https://github.com/thewebexpert/binkterm-php-echoButtonMod
- **Hook used:** `templates/custom/header.insert.twig`
