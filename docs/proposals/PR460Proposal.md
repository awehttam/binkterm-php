# AreaFix Structural Parser: Hardening & Preview Confirmation

> **Draft Notice:** This proposal is a draft, generated with AI assistance, and may not have been reviewed for accuracy. It is intended as a starting point for discussion and implementation planning.

---

## Table of Contents

1. [Problem Statement](#problem-statement)
2. [Background: What PR 460 Changed](#background-what-pr-460-changed)
3. [Gaps Identified in PR 460](#gaps-identified-in-pr-460)
4. [Proposed Improvement 1: Layered Fallback Parsing](#proposed-improvement-1-layered-fallback-parsing)
5. [Proposed Improvement 2: Fix the Stale Actionable-Reply Threshold](#proposed-improvement-2-fix-the-stale-actionable-reply-threshold)
6. [Proposed Improvement 3: Row-Level Status for %QUERY/%LINKED Blocks](#proposed-improvement-3-row-level-status-for-querylinked-blocks)
7. [Proposed Improvement 4: Preview Screen Before Applying Changes](#proposed-improvement-4-preview-screen-before-applying-changes)
8. [Proposed Improvement 5: Data-Driven Grammar Definitions](#proposed-improvement-5-data-driven-grammar-definitions)
9. [Proposed Improvement 6: Per-Uplink Format Memory](#proposed-improvement-6-per-uplink-format-memory)
10. [Out of Scope](#out-of-scope)
11. [Open Questions](#open-questions)

## Problem Statement

PR 460 (`refactor(areafix): structural response parser and multi-command
support`) replaced the old regex/blocklist-based AreaFix reply parser with a
structural parser (`src/AreaFix/AreaFixParser.php`) that recognizes three
concrete grammars: Mystic/MBSE Command/Result blocks, delimited (`:`/`|`)
tables, and columnar/dotted-leader tables. This is a meaningful improvement in
precision — it eliminates false positives caused by the old English-word
blocklist (e.g. real echoareas named `LINUX`, `BASE`, `WINDOWS` no longer
collide with blocklisted words) and adds real subscribe/unsubscribe/available
semantics that the old parser lacked entirely.

However, the new parser is a closed world: if a hub's reply doesn't match one
of the three recognized grammars, `parse()` returns an empty array with no
error, no log entry, and no fallback. Combined with the fact that AreaFix sync
results are applied directly to the `echoareas`/`file_areas` tables
(activating and deactivating rows), a sysop currently has no way to see what a
sync operation is about to do before it happens. This proposal builds on top
of PR 460's structural parser rather than replacing it, and adds the missing
safety net and visibility.

## Background: What PR 460 Changed

- Added `src/AreaFix/AreaFixParser.php` with three grammar-specific parsers
  (`parseMysticBlocks`, `parseDelimitedTable`, `parseColumnarTable`), tried in
  order, first non-empty result wins.
- Added `AreaFixParser::isValidTag()` for strict syntactic tag validation
  (letters/digits/underscore/hyphen/period, 2-60 chars, at least one letter,
  not a reserved structural keyword) in place of the old blocklist.
- Added action semantics: `ACTION_SUBSCRIBE`, `ACTION_UNSUBSCRIBE`,
  `ACTION_AVAILABLE`, each driving different behavior in
  `AreaFixManager::syncSubscribedAreas()`.
- Added `tests/test_structural_areafix_parser.php` and
  `tests/test_areafix_response_guard.php` covering the three recognized
  grammars.
- Updated `docs/AreaFix.md` to document the new parser architecture.

## Gaps Identified in PR 460

1. **No fallback for unrecognized formats.** The old parser had a freeform
   fallback (any bare `TAG   description` line, filtered by a blocklist) that
   is not present in the new parser. A hub mailer whose reply doesn't match
   one of the three recognized headers/banners now silently produces zero
   areas, and auto-sync for that uplink appears to just stop working with no
   visible error.
2. **Stale `count($areas) >= 2` threshold.** `routes/admin-routes.php`
   (`/api/admin/areafix/sync-latest`) still requires at least two parsed areas
   before treating a reply as actionable, even though PR 460 removed that
   same threshold from the parser itself and added a test asserting that a
   single `+TAG` confirmation is a valid actionable reply. This makes the
   admin "sync latest" action reject exactly the single-area confirmations
   the new parser was built to accept.
3. **`%QUERY`/`%LINKED` blocks don't check row-level status.** Every row
   found inside a `%QUERY`/`%LINKED`/`%LINK` result block is marked
   `ACTION_SUBSCRIBE` purely because of which command produced the block, not
   because of anything in the row itself. If a hub's `%QUERY` response ever
   lists both linked and unlinked areas together, every row — including ones
   the sysop is not actually subscribed to — is synced as subscribed.
4. **No confirmation step before changes are applied.** Whether a sync comes
   from an automatic scheduled poll or a manual admin action, parsed areas
   are synced directly into `echoareas`/`file_areas` with no intermediate
   step where a human can see what will be created, activated, or
   deactivated.

## Proposed Improvement 1: Layered Fallback Parsing

> **Status: Implemented.** See `src/AreaFix/AreaFixParser.php` and
> `tests/test_areafix_real_world_samples.php`. What actually landed turned out
> to be broader than originally scoped here — see **Implementation Notes**
> below for what was added beyond the freeform fallback tier.

Keep the three structural grammars in `AreaFixParser` as the primary,
preferred path — they should continue to be tried first since they carry
correct action semantics and delimiter-safe description slicing that a
freeform parser cannot replicate. Add a fourth, last-resort tier:

- A conservative freeform line parser, gated by the same strict
  `AreaFixParser::isValidTag()` check used by the structural parsers (never
  the old English-word blocklist).
- Rows matched only by the fallback tier default to `ACTION_AVAILABLE` and
  `is_subscribed = false` — never `ACTION_SUBSCRIBE` — so an unrecognized
  format cannot silently auto-activate an area.
- Every time the fallback tier is the one that produced a non-empty result,
  log it via `BinktermPHP\Binkp\Logger` (uplink address, message subject,
  first N characters of the body) so an unrecognized hub format becomes
  visible for follow-up instead of vanishing.

This preserves PR 460's precision gains for the mailers it already
recognizes, while turning "silently returns zero areas" into "parses
conservatively and leaves a log trail for adding a proper grammar later."

### Implementation Notes

Testing this against real hub reply samples (`tests/test_areafix_real_world_samples.php`)
showed that a single freeform tier wasn't enough on its own — two of the three
originally-failing samples turned out to have real, recognizable structure
that a proper grammar could handle more precisely than a blind line-by-line
fallback ever could:

- **AreaMgr-style `Con / Message area / Description` tables.** This was not a
  headerless format at all — it has a header and a dashed separator, just
  like the existing HPT/Husky columnar grammar, except the tag column isn't
  literally named `Area` (it's `Message area`, with a `Con` flags column
  first). Fixed by broadening `parseColumnarTable()`'s header regex from
  requiring the literal prefix `Area\s{3,}(Status|Description|...)` to
  matching `area` as a whole word anywhere in the header line, still gated by
  requiring an immediately-following dashed separator line (so this doesn't
  loosen into matching arbitrary prose). This one didn't need the fallback
  tier at all — it's now handled with full precision as a fourth case of the
  existing structural grammar.
- **BBBS/Li6 `+TAG (address) "description"` lists** (with wrapped multi-line
  descriptions and a single reply combining both an echo-area and file-area
  section). This is also a real, well-defined structural grammar, not
  freeform prose, so it was added as its own tier —
  `parseQuotedAddressList()` — ahead of the freeform fallback rather than
  folded into it. It handles the multi-line description wrapping by
  continuing to consume lines until a closing quote appears, with guards
  against ever swallowing the next entry, a blank line, or a banner line.
- **SBBSecho bare `TAG   Description` lists** (no header, no delimiter, no
  banner — just a plain two-column list terminated by a `--- <mailer>`
  tearline) is the genuine freeform case the fallback tier was designed for,
  implemented as `parseFreeformList()`. Gated by `isValidTag()` and a
  required 2+ space (or tab) gap between tag and description; always returns
  `ACTION_AVAILABLE`, never `ACTION_SUBSCRIBE`; logs via
  `BinktermPHP\Binkp\Logger` to `server.log` whenever it's the tier that
  produced results.

**Known, accepted limitations** (verified against the real samples, not
theoretical):
- A tag whose name is long enough to leave only a single space before its
  description (e.g. `CHEESE_HUMANSONLY` in the SBBSecho sample) is not
  captured by the freeform tier. Loosening the gap requirement to 1+ space
  would make it match ordinary two-word prose sentences — confirmed against
  `tests/test_areafix_response_guard.php`'s existing prose-rejection test,
  which relies on single-spaced English sentences never being mistaken for
  area rows. This tradeoff was chosen deliberately: coverage loss on a rare
  column-alignment edge case is preferable to false positives on ordinary
  text.
- Tags containing characters outside `isValidTag()`'s allowed set (letters,
  digits, `_`, `-`, `.`) are not captured by any grammar, including the new
  ones — e.g. `WHAT'S_HOT!` and `WILDCAT!_SUPPORT` in the BBBS sample. Widening
  `isValidTag()`'s character class was considered out of scope here since it's
  shared by every grammar and widening it for two rare tag names isn't worth
  the added false-positive surface elsewhere.
- When the same tag legitimately appears in two different sections of one
  reply with two different descriptions (e.g. `ECHOLIST` as both an echo area
  and a file area in the BBBS sample), `deduplicateAreas()` collapses them to
  one entry, keeping the first description seen. The parser has no concept of
  "echo area" vs. "file area" — that distinction is applied later by the
  caller (`robot` parameter in `AreaFixManager::syncSubscribedAreas()`), so
  this is a pre-existing architectural limitation, not something Improvement
  1 introduced or was expected to fix.

## Proposed Improvement 2: Fix the Stale Actionable-Reply Threshold

> **Status: Implemented.** Landed as part of building Improvement 4 (both the
> preview and apply endpoints needed to agree on what "actionable" means).

Remove the `count($areas) >= 2` check in `routes/admin-routes.php`'s
`/api/admin/areafix/sync-latest` handler (near the `isAreaListResponse()`
call) and rely solely on `AreaFixParser`/`AreaFixManager`'s own notion of
"actionable" (non-empty parsed area list), matching the behavior PR 460
already established and tested in `AreaFixManager` itself. This is a small,
isolated fix that removes an inconsistency introduced by PR 460 rather than a
new feature.

## Proposed Improvement 3: Row-Level Status for %QUERY/%LINKED Blocks

> **Status: Implemented.** See `src/AreaFix/AreaFixParser.php`
> (`parseMysticBlocks()`) and Test 8 in
> `tests/test_structural_areafix_parser.php`.

Extend `parseMysticBlocks()` so that indented rows under a `%QUERY`/`%LINKED`/
`%LINK`/`%UNLINKED`/`%LIST`/`%AVAIL` result block are classified the same way
the delimited-table and columnar-table parsers already do: by inspecting each
row's own status text (a leading marker, or a trailing word like
"unsubscribed") rather than assuming every row shares the same action because
of which command produced the block. Where a hub's block format genuinely
carries no per-row status (true today for the `%LINKED`-only case, where every
row *is* linked by definition), the current command-based classification is
correct and should stay — the change is only needed for blocks whose rows
can mix linked and unlinked entries (`%QUERY`).

### Implementation Notes

No captured real-world sample of a hub mixing linked and unlinked areas in a
single `%QUERY` block was available, so the row-level signal implemented is a
conservative, explicit annotation: a row ending in `(linked)`, `(unlinked)`,
or `(not linked)` is classified by that annotation (with the annotation
stripped from the stored description), overriding the command-level default.
A row with no such annotation still falls back to the command-level default
exactly as before, so this is purely additive — no existing passing test
(Mystic `%LIST`/`%LINKED` samples with no per-row markers) changed behavior.
If a real hub reply using a different per-row marker convention for mixed
`%QUERY` listings turns up, `parseMysticBlocks()`'s row-classification block is
the place to extend.

## Proposed Improvement 4: Preview Screen Before Applying Changes

> **Status: Implemented**, including the per-message extension described in
> the Open Questions resolution below (a sync button on each incoming
> Message History row, not just the "latest reply" panel, via an optional
> `message_id` on both endpoints).

This is the centerpiece of the proposal. Whether a sync is triggered
automatically (scheduled poll processing an incoming AreaFix reply) or
manually (an admin clicking "sync latest" in Admin → Networks), the parsed
result should be shown to a human before any row in `echoareas`/`file_areas`
is created, activated, or deactivated.

### Flow

1. `AreaFixParser::parse()` runs as it does today, producing the list of
   `{name, description, action, is_subscribed}` items (now including any
   fallback-tier items from Improvement 1, clearly flagged as such).
2. Instead of calling `AreaFixManager::syncSubscribedAreas()` immediately,
   the result is diffed against the current `echoareas`/`file_areas` rows for
   that uplink+domain to classify each parsed area as one of:
   - **New** — will be created and activated.
   - **Reactivate** — exists but currently `is_active = false`, will be
     turned on.
   - **Deactivate** — currently active but missing from the parsed list
     (only relevant when `$deactivateMissing` is true).
   - **Unchanged** — already in the desired state; shown for completeness
     but visually de-emphasized.
   - **Uncertain (fallback-parsed)** — produced only by the Improvement 1
     fallback tier; always shown with a distinct visual treatment and never
     silently defaulted to "new/active."
3. This diff is rendered as a preview screen (Admin UI: a Bootstrap 5 modal
   or dedicated panel under Admin → Networks; consistent styling with the
   rest of the admin UI, respecting the theme stylesheets per project
   convention) showing, per row: area tag, description, current state, and
   proposed action. Nothing is written to the database at this point.
4. The sysop reviews the preview and either confirms (triggers the actual
   `syncSubscribedAreas()` call using the previewed data) or cancels (nothing
   changes).

The preview/confirm step is **mandatory for every sync, with no opt-out
setting** — there is no configuration path that lets a sync apply directly
without a human reviewing the diff first. Automatic/scheduled AreaFix polling
is out of scope for this proposal; it is addressed only to the extent that it
already exists today, and is not being redesigned here.

### API Shape (illustrative)

- `POST /api/admin/areafix/preview-sync` — parses the latest actionable
  reply for an uplink and returns the diff described above without applying
  it.
- `POST /api/admin/areafix/apply-sync` — takes a previously previewed diff
  (or re-parses and re-diffs immediately before applying, to avoid acting on
  stale data if new mail arrived in between) and performs the actual
  `syncSubscribedAreas()` call.

Per project convention, any new API routes must be documented in
`docs/API.md` with complete response tables, and any new user-facing text
must go through the i18n catalog system rather than being hardcoded.

### Why This Matters Given Improvements 1–3

The preview screen is also what makes the more permissive fallback parsing
in Improvement 1 safe to ship: instead of trusting an unrecognized format's
best-effort parse and quietly applying it, the sysop sees exactly which rows
came from the fallback tier (visually flagged as "uncertain") before
anything is activated. This turns the fallback tier from a risk into a
net improvement — coverage goes up, but nothing changes without a human
looking at it first.

## Proposed Improvement 5: Data-Driven Grammar Definitions

Today, adding support for a new hub mailer's AreaFix reply format means
writing a new private method in `AreaFixParser` (as `parseMysticBlocks`,
`parseDelimitedTable`, and `parseColumnarTable` already are), which requires
a PHP change, a PR, and a release. To make broader format coverage
achievable without that overhead, structural grammars should be describable
declaratively instead of only in code:

- A grammar definition specifies, in a config file (e.g.
  `config/areafix_grammars.json` or similar, following this project's
  convention of runtime configuration living under `config/`): a header
  detection pattern, column layout (which column holds the tag, description,
  status), the delimiter or column-width rule, and how status text maps to
  `ACTION_SUBSCRIBE` / `ACTION_UNSUBSCRIBE` / `ACTION_AVAILABLE`.
- `AreaFixParser` loads and tries each defined grammar (built-in three plus
  any config-defined ones) in order, same as today, before falling through
  to the Improvement 1 fallback tier.
- This does not remove the three existing grammars or require rewriting them
  as data — they can stay as the built-in, well-tested defaults. It only
  adds a path for new formats to be described as data going forward,
  lowering the bar for contributors (including sysops who can identify their
  own hub's format from a real reply) to add coverage without touching
  parser internals.
- Per project convention, any new runtime config file of this kind should be
  editable through an Admin UI page rather than requiring direct file edits,
  consistent with how `binkp.json`/`bbs.json`/`webdoors.json` settings are
  exposed today; direct file edits should only be a fallback for cases the
  UI doesn't yet cover.

This is a natural complement to Improvement 1: the fallback tier's logging
identifies *that* a format isn't recognized, and data-driven grammars make it
cheap to turn a logged sample into permanent, tested coverage.

## Proposed Improvement 6: Per-Uplink Format Memory

Each individual uplink is internally consistent — a given hub always emits
the same AreaFix reply format — even though the overall population of
uplinks a BBS might connect to is heterogeneous. The parser can exploit this:

- When a reply from a given uplink address is successfully parsed and its
  resulting sync is confirmed through the mandatory preview screen
  (Improvement 4), record which grammar/tier matched (one of the three
  built-in structural parsers, a data-driven grammar from Improvement 5, or
  the Improvement 1 fallback tier) against that uplink.
- On the next reply from the same uplink, try the remembered grammar first
  before falling through the full ordered list. This is a minor performance
  optimization, but more importantly it establishes a per-uplink expectation
  of "this is the format this hub sends."
- If a reply from an uplink with a remembered format *doesn't* match that
  remembered grammar anymore, treat that as notable on the preview screen —
  distinct from an uplink being parsed for the first time — since it likely
  means the hub's mailer software changed, was reconfigured, or (in rarer
  cases) the reply is coming from something other than the expected hub.
  This gives the sysop a concrete signal to look more closely at that
  specific sync, rather than a generic "review before applying" prompt.

Together, Improvements 5 and 6 move the system from "recognizes a fixed set
of formats correctly" toward "recognizes a growing, contributor-extensible
set of formats correctly, and notices when a known uplink's format changes
out from under it" — without weakening the precision gains PR 460 already
delivered.

## Out of Scope

- Replacing or supplementing the structural parser with an LLM/AI-based
  extraction step. As discussed in review, this is not recommended as a
  primary parsing mechanism given the non-deterministic/unauditable nature of
  LLM output for a subsystem that drives real subscription state; it is not
  part of this proposal.
- Changes to FileFix-specific behavior beyond what naturally falls out of
  sharing `AreaFixParser` with AreaFix (both should be kept at feature parity
  per project convention, but this proposal does not introduce new
  FileFix-only functionality).

## Open Questions

None remaining. The preview/confirm step is mandatory for all syncs with no
opt-out setting (so no new configuration surface is needed for it), and
automatic/scheduled sync behavior is out of scope for this proposal.
