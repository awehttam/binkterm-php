# Upgrading to 1.9.10

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Admin Menu Navigation](#admin-menu-navigation)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Admin Menu Navigation

- Nested items under **Admin** in the top navigation (for example **Area Management → AreaFix**) could become unreachable on narrow browser windows and touch devices. Clicking or tapping a nested submenu heading could silently fail to open it, and once open, the menu could get stuck without letting you scroll down to reach items further down the list. This has been fixed; nested Admin submenus now open reliably and scroll independently of the page.

---

## Admin Menu Navigation

On viewports narrower than the desktop breakpoint (roughly tablet width and below, including phones and narrowed desktop browser windows), the collapsed hamburger menu could make items nested under **Admin** — such as **Area Management → AreaFix**, **Community → Chat**, or **Help → Developer** — difficult or impossible to reach. Three separate issues combined to cause this:

- Clicking or tapping a nested submenu heading (e.g. **Area Management**) could appear to do nothing. A mouse hovering over the heading, or a touch tap's built-in hover simulation, would already visually open the submenu before the click was handled, so the click handler mistook it for already being open and immediately closed it again.
- Selecting a nested submenu heading could close the entire **Admin** menu outright, because the browser's dropdown behavior treated clicking any item inside the menu as "an item was selected."
- On narrow viewports, the expanded **Admin** menu could grow taller than the visible page area. Because the menu bar stays pinned to the top of the screen while you scroll, any items below the visible area were unreachable — scrolling moved the rest of the page instead of the menu.

All three issues are fixed. Submenus under **Admin** now open reliably on the first click or tap, stay open until you close them, and the menu now scrolls within itself so every nested item can be reached regardless of window size or device.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
