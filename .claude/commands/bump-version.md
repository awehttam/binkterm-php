# Bump Version

Steps to perform a version bump and create a release UPGRADING doc.

## 1. Update the Version Constant

Edit `src/Version.php` and change the `VERSION` constant:
```php
private const VERSION = '1.4.3';  // Update this line
```
Everything else (tearlines, footer, API responses, Twig templates) picks it up automatically.

## 2. Update composer.json

```json
{
    "name": "binkterm-php/fidonet-web",
    "version": "1.4.3",
    ...
}
```

## 3. Commit — do NOT create a tag

```bash
git add src/Version.php composer.json
git commit -m "Bump version to 1.4.3"
git push origin main
```

## 4. Create UPGRADING doc

Create `docs/UPGRADING_X.Y.Z.md` using `docs/UPGRADING_TEMPLATE.md` as the basis. Replace the placeholder feature-area sections with a placeholder summary and standard upgrade instructions. Do NOT populate it with content from git history — commits that exist at the time of the version bump belong to the previous release, not this one. Content is added to the doc incrementally as changes are made during the new release cycle.

Link the new doc from `README.md` and the Upgrading section of `docs/index.md` (newest-first).

## UPGRADING Doc Rules

- **TOC required**: Always add or update the table of contents so headings remain navigable and in sync with the document.
- **Format**: Start with a table of contents. The first entry must be a summary of changes section grouped by major feature area. After the summary, include fuller descriptions also grouped by feature area.
- **Voice**: Write as if the reader has no prior exposure to the development work, branch discussions, or the problems being fixed. Every change must be self-contained — no phrases like "the previous issue with X", "as discussed", or "the fix for the problem where...". State what changed, why it matters, and what action the upgrader needs to take.
- **No redundant setup.php reminders**: Do not add "Run `php scripts/setup.php`" sentences within individual feature sections. The Upgrade Instructions section at the bottom already covers this for all changes. Only mention setup.php within a feature section if there is something genuinely unusual about how that feature's migration must be run.
- **Composer dependencies**: When adding a new required package to composer.json, the UPGRADING doc for that version MUST include instructions to run `composer update` before `php scripts/setup.php`. Without this, the upgrade will fail because `vendor/autoload.php` is loaded before setup.php runs. Always use `composer update`, not `composer install` — running `composer install` on a deployment without an existing `composer.lock` or with a mismatched lock file produces the error "This usually happens when composer files are incorrectly merged or the composer.json file is manually edited."
