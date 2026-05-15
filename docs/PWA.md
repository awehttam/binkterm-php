# PWA and Service Worker Asset Caching

BinktermPHP ships as an installable Progressive Web App (PWA) and registers a service worker that caches static assets for fast repeat loads on slow or intermittent connections.

## Table of Contents

- [Installing the PWA](#installing-the-pwa)
- [What the Service Worker Caches](#what-the-service-worker-caches)
- [What Is Not Cached](#what-is-not-cached)
- [Cache Invalidation](#cache-invalidation)
- [Hard Refresh Bypass](#hard-refresh-bypass)
- [App Shortcuts](#app-shortcuts)
- [Customizing the App Icon](#customizing-the-app-icon)
- [Developer Notes](#developer-notes)

---

## Installing the PWA

BinktermPHP can be installed as a standalone app on desktop and mobile. When installed it launches without browser chrome (address bar, tabs) and appears in the OS app launcher like a native application.

**Desktop (Chrome / Edge)**

Click the install icon in the browser's address bar, or open the browser menu and choose **Install BinktermPHP** (or similar). The app name shown is taken from the system name in your BinkP configuration.

**Android**

Open the site in Chrome and tap the three-dot menu → **Add to Home screen**. The app installs to the home screen and app drawer.

**iOS / Safari**

Tap the Share button → **Add to Home Screen**. The app appears on the home screen with the system icon.

---

## What the Service Worker Caches

The service worker (`public_html/sw.js`) precaches a fixed list of static assets when it installs. Assets are fetched fresh and stored; subsequent page loads serve them from the local cache with no network round-trip.

Cached asset categories:

| Category | Examples |
|---|---|
| Core JS | `app.js`, `echomail.js`, `netmail.js`, `binkstream-client.js` |
| Core CSS | `style.css`, `amber.css`, `dark.css`, `greenterm.css`, `cyberpunk.css` |
| Vendor libraries | Bootstrap, jQuery, Font Awesome, Toast UI Editor, Plyr, Marked |
| Fonts | Font Awesome `.woff2` files |
| Notification sounds | `notify1.mp3` … `notify5.mp3` |
| i18n catalog | `GET /api/i18n/catalog` (locale strings) |

Assets not in the precache list are cached on first fetch when they match the pattern for CSS, JS, fonts (`woff2`, `ttf`, `eot`), SVG, or audio files.

---

## What Is Not Cached

- **HTML pages** — always fetched from the network so users always see current page content.
- **API responses** — requests under `/api/` are not cached, with the sole exception of `/api/i18n/catalog`.
- **Admin-only JS** — `admin-terminal.js`, `xterm.js`, `xterm-addon-fit.js`, and `xterm.css` are excluded because they change frequently during development and are loaded only by admins.
- **Cross-origin requests** — the service worker only intercepts same-origin fetches.
- **Partial responses (HTTP 206)** — audio range requests return 206 and are intentionally not cached; the Cache API rejects partial responses.

---

## Cache Invalidation

The cache is versioned by the `CACHE_NAME` constant at the top of `public_html/sw.js`:

```js
const CACHE_NAME = 'binkcache-v877';
```

When a new service worker installs:

1. The existing cache for `CACHE_NAME` is deleted and rebuilt from scratch.
2. All old caches with different names are deleted.
3. `skipWaiting()` is called so the new worker activates immediately without waiting for all tabs to close.
4. `clients.claim()` takes control of all open pages.

**Bumping the cache version** is required whenever CSS, JS, or i18n catalog files change so that users who have the old service worker pick up the new assets. Increment the numeric suffix in `CACHE_NAME` (e.g. `binkcache-v877` → `binkcache-v878`). See the developer notes below for when this is required.

The service worker also polls for updates every 60 seconds in the background.

---

## Hard Refresh Bypass

A hard refresh (**Ctrl+Shift+R** on desktop, or clearing the browser cache) sets the fetch cache mode to `reload`. The service worker detects this and passes the request directly to the network, bypassing its own cache. This lets developers and sysops force fresh assets without needing to bump the cache version.

---

## App Shortcuts

The PWA manifest exposes shortcuts that appear when long-pressing the app icon on Android or right-clicking it on desktop:

| Shortcut | URL |
|---|---|
| Compose Netmail | `/compose/netmail` |
| Netmail | `/netmail` |
| Echomail | `/echomail` |
| Nodelist | `/nodelist` |
| Doors | `/games` |
| Files | `/files` |

---

## Customizing the App Icon

The icon shown when the PWA is installed comes from the favicon. By default it uses `/favicon.svg`. To use a different image, set `FAVICONSVG` in `.env`:

```
FAVICONSVG=/images/my-custom-icon.svg
```

The manifest is generated dynamically at `/appmanifestjson` and picks up the change immediately — no service worker bump needed for icon changes.

The app name shown in the OS app launcher is the system name from the BinkP configuration (`config/binkp.json` → `system.name`).

---

## Developer Notes

**Always bump `CACHE_NAME` when changing cached files.** The service worker uses a cache-first strategy: once an asset is cached it is served from cache until the cache version changes. If you modify a CSS or JS file, or update locale strings in `config/i18n/`, without bumping `CACHE_NAME`, users with an active service worker will continue to receive stale assets.

The rule from `CLAUDE.md`:

> When making changes to CSS or JavaScript files, or when updating i18n language strings in `config/i18n/`, increment the `CACHE_NAME` version in `public_html/sw.js` (e.g., `'binkcache-v2'` to `'binkcache-v3'`) to force clients to download fresh copies.

**Edge cache lock.** The `getCache()` helper in `sw.js` races the `caches.open()` call against a 3-second timeout. This works around a known issue in Microsoft Edge where holding the PWA and a browser window open simultaneously can cause the Cache Storage API to deadlock. On timeout the service worker falls back to the network so pages continue to load rather than hanging.
