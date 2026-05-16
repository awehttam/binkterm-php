# WebDoors

WebDoors are HTML5/JavaScript games embedded in the BBS. See `docs/WebDoors.md` for the full specification.

- Each WebDoor must include a valid `webdoor.json` manifest.
- WebDoors must include the SDK as their first require: `require_once __DIR__ . '/../_doorsdk/php/helpers.php';` — do NOT require `vendor/autoload.php` directly.
- **API Independence**: Each WebDoor implements its own API routes. Do NOT add WebDoor functionality to `routes/api-routes.php` or `routes/web-routes.php` unless explicitly instructed. WebDoor APIs belong in their own route files (e.g. `routes/webdoor-netrealm-routes.php`) or `WebDoorController`.
- When changing the WebDoor system (not individual games), update `docs/WebDoors.md`.
