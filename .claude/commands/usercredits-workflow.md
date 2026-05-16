# Credits Workflow

When adding new `UserCredit` credit/reward types, update these five places (see `docs/CreditSystem.md` for full details):

1. Code defaults in `src/UserCredit.php` (`getCreditsConfig()` → `$defaults` array)
2. `bbs.json.example` credits section
3. Admin twig template: `templates/admin/bbs_settings.twig` (form field + `loadBbsSettings()` + `saveBbsCredits()`)
4. Admin API endpoint: POST `/admin/api/bbs-settings` in `routes/admin-routes.php`
5. `README.md` credits documentation

Configuration priority: `data/bbs.json` > `bbs.json.example` > code defaults in `src/UserCredit.php`.
