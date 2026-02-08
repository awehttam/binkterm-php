# ANSI Ad Generator

The ANSI ad generator builds ads from the current system configuration (system name, sysop, location, networks, enabled webdoors, and site URL). It is intended for quick creation of `bbs_ads/*.ans` files.

## Usage

```bash
php scripts/generate_ad.php [options]
```

### Common options

- `--stdout` (default): Print the ad to stdout.
- `--output=PATH`: Write the ad to a specific file path.
- `--variant=N`: Select a layout variant (1-12).
- `--seed=SEED`: Make output deterministic for testing.
- `--extra=TEXT`: Extra line(s) used in some variants.
- `--tagline=TEXT`: Tagline line (used by variant 7).
- `--title-font=STYLE`: Title font style: `block`, `outline`, `slant`, `banner`.
- `--border-accent=LEVEL`: Border accent level: `none`, `rare`, `subtle`, `noticeable`.

## Examples

Print to stdout (default):

```bash
php scripts/generate_ad.php
```

Write to a file:

```bash
php scripts/generate_ad.php --output=bbs_ads/my_ad.ans
```

Variant 3 (starfield) with deterministic output:

```bash
php scripts/generate_ad.php --variant=3 --seed=12345
```

Showcase layout (variant 7) with tagline, blurb, and banner font:

```bash
php scripts/generate_ad.php --variant=7 \
  --title-font=banner \
  --tagline="A Social Media Alternative" \
  --extra="We are a work in progress. Your feedback is always welcome."
```

Matrix-style layout (variant 8):

```bash
php scripts/generate_ad.php --variant=8 --extra="SYSTEM ONLINE"
```

## Notes

- Networks are pulled from enabled uplinks in `config/binkp.json`.
- WebDoors are pulled from enabled entries in `config/webdoors.json`.
- Site URL uses `Config::getSiteUrl()` (respects `SITE_URL` when configured).
