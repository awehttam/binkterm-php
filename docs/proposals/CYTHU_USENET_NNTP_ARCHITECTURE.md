# Upstream collaboration brief: Cythu's Usenet NNTP client and article model

**For:** Matthew Asham / BinkTermPHP's Claude Code workflow  
**Prepared from:** Cythu (`SkrawlCO/cythu`, private), branch `experience-lobby-v2` at `64dcae1d7312bd1e35c8ec2c9ae68c2ef9776d7b`; upstream BinkTermPHP (`awehttam/binkterm-php`, public), `main` at `d1341191df2bd9f4fe6c39ba05998142f2c75542`.  
**Status:** Architecture handoff and implementation plan for discussion; **not** a request to reopen a closed upstream issue, not an upstream-ready patch, not production configuration, and not a claim that an external NNTP peer is configured.  
**Question being answered:** How Cythu implements **NNTP client** access to Usenet, particularly `echoareas` and mapping to newsgroups, so upstream can implement compatible capabilities without redoing the work.

## Existing upstream direction and collaboration context (2026-10-14)

Upstream's existing [NNTP Client issue #371](https://github.com/awehttam/binkterm-php/issues/371) proposed *deliberately* mapping outbound NNTP client transport to `echoareas` and `echomail`, in the style of its QWK transport. It was closed **not planned / keep for later** on 2026-08-11. Upstream's [NNTP Peering issue #427](https://github.com/awehttam/binkterm-php/issues/427) is a separate, still-open server-to-server proposal. These are Matthew's product decisions, not defects in BinktermPHP.

**Cythu's design is an alternative for a different goal:** true native Usenet consumption with raw global Message-IDs, unmodified crossposts, and a separate article store. Sharing how it works does **not** assert that BinktermPHP should replace its planned `echoareas`-based transport model or require INN. Matthew may prefer (1) his existing NNTP-to-FTN transport design, (2) native Usenet beside FTN, or (3) a carefully scoped bridge between them. Do not assume a choice for him, open new upstream issues, or make a PR until he opts in.

The requested answer to "how do you map usenet groups to echoareas?" is simply **we do not map them in Cythu's native path**. The legacy `USENET_*` echoarea AutoFeed mirror is separate, not the native Usenet client.

## To Claude Code: work instruction

1. Read this brief and the *current* upstream BinkTermPHP NNTP server, `echoareas`, messaging, migration, auth and test code. Check for any new upstream client support before implementing anything. Source current at execution time supersedes the pinned references below.
2. **Separate two distinct capabilities:** BinkTermPHP's existing **NNTP server** exposes FTN echomail to newsreader clients; Cythu's **NNTP reader/posting client** consumes and publishes native Usenet articles through a separate INN-backed site. These are not the same storage or network direction.
3. Start with a design determination of whether upstream wants **direct remote NNTP client ingestion** or a **co-hosted INN-backed local site** (Cythu's approach). Do not silently adopt INN operational complexity if a simpler upstream client contract meets the maintainer's requirement.
4. Implement small reviewable slices against the selected upstream architecture, with test protocol fixtures and no live public feed/peer needed. Ask maintainer before opening/merging changes to his upstream repository.
5. Preserve FTN `echoareas` and `echomail` unchanged unless the maintainer explicitly requests a *separate gateway/bridge*. Never create fake FTN messages as an accidental side effect of a native Usenet article ingest.

## 1. Most important answer: Usenet groups do **not** map to `echoareas`

**BinkTermPHP upstream's current NNTP server:**

- `docs/NNTP.md` and `src/Nntp/*` expose existing FTN echoareas as NNTP newsgroups named approximately `<Network>.<AreaTag>` (e.g. `FidoNet.GENERAL`), controlled by echoarea subscriptions.
- This is **NNTP server / outbound-to-newsreader representation**: the authoritative messages remain `echoareas` + `echomail`.
- Existing `scripts/nntp_server.php` services ordinary newsreaders; this is not an NNTP **client** that fetches arbitrary Usenet from external news servers.

**Cythu's native Usenet reader/client:**

- Uses a **separate** canonical schema: `usenet_groups`, `usenet_articles`, `usenet_article_groups`, and durable ingest/posting ledgers.
- A Usenet group is identified by **INN site + actual newsgroup name** (e.g. `comp.lang.php`). It is not an `echoareas` row and does not require `echoareas.id` or an FTN network domain.
- One global `Message-ID` means **one canonical article**. Crossposts are separate `(article_id, group_id)` membership rows, not duplicated articles or fake FTN crossposted echomails.
- Articles preserve their post-acceptance **raw NNTP bytes** (SHA-256 + size), with sanitized header projections for UI/search. Remote `From:` authors are **not** automatically BinkTermPHP users.
- Cythu's conversation/UI layer (`ConversationKind::usenet`) reads the native store; Web `/usenet`, Terminal `UsenetWorkspace` and Prowl are different surfaces over it.
- A **legacy** Eternal-September-to-`USENET_*` echoarea AutoFeed mirror also exists in the older Cythu lineage, but it is explicitly separate from the native store. Its existence must **not** be confused with the native architecture or used as a hidden conversion layer.

### The three flows in one picture

```text
EXISTING UPSTREAM FTN→NNTP SERVER  (not the client requested)
  binkp / FTN uplink → echoareas + echomail → src/Nntp/* → newsreader

CYTHU NATIVE USENET CLIENT, READ PATH
  upstream Usenet → INN spool/peer → internal NNTP reader (nnrpd)
                                    ├─ channel event → fetch by Message-ID
                                    └─ LIST ACTIVE / GROUP / OVER reconciliation
                                    ↓
                          canonical usenet_* tables
                                    ↓
                       Web / Terminal / Prowl reader

CYTHU NATIVE USENET CLIENT, POST PATH
  Cythu member → compose + identity + durable ledger
               → NNTP POST to internal INN
               → INN returns protocol response
               → later local re-observation by Message-ID
               → ledger records accepted_locally
```

**No arrow connects `usenet_groups` to `echoareas`, or `usenet_articles` to `echomail`.** Such a bridge would be a *new, explicit feature* with nontrivial identity, threading and loop-prevention rules.

## 2. Canonical schema: concrete, minimal reference

Actual Cythu initial migration: `database/migrations/v20260918001659_usenet_canonical_article_model.sql`.

| Table | Unique identity and purpose |
|---|---|
| `usenet_groups` | `UNIQUE(site, name)`; group's INN-derived active/carried state, posting status, metadata, observed article-number range and durable reconcile cursor; `exposed` is a separate SysOp/steward decision |
| `usenet_articles` | `UNIQUE(message_id)`; canonical raw bytes, bytes checksum, sanitized headers, References/parent/root relations, ingest provenance |
| `usenet_article_groups` | `PRIMARY KEY(article_id, group_id)`; crossposting membership, per-group INN article number and source of observation (`xref` or `overview`) |
| `usenet_ingest_events` | Append-only-ish ingest observations with source/outcome, not duplicate article content |
| `usenet_ingest_cursors` | Durable site/feed byte offset and fingerprint for channel event log |
| `usenet_fetch_queue` | Bounded retries for notification-before-article-readable race |
| `usenet_ingest_runs` | Later migration: summary of bounded feed/retry/reconcile/posting passes for operator diagnostics |
| `usenet_identities`, `usenet_posts`, `usenet_post_events` | Later migration: local posting identity, immutable submission evidence and state transitions |
| `usenet_app_passwords` | Later optional native NNTP app-password lifecycle; **not a prerequisite for read-only ingest** |

Suggested *conceptual* DDL core (use upstream migration conventions and actual constraints; this is not a copy of Cythu's full migration):

```sql
CREATE TABLE usenet_groups (
    id BIGSERIAL PRIMARY KEY,
    site TEXT NOT NULL,
    name TEXT NOT NULL,
    carried BOOLEAN NOT NULL DEFAULT FALSE,
    exposed BOOLEAN NOT NULL DEFAULT FALSE,
    posting_status CHAR(1),
    reconcile_cursor BIGINT NOT NULL DEFAULT 0,
    UNIQUE (site, name)
);
CREATE TABLE usenet_articles (
    id BIGSERIAL PRIMARY KEY,
    message_id TEXT NOT NULL UNIQUE,
    raw_bytes BYTEA NOT NULL,
    raw_sha256 CHAR(64) NOT NULL,
    subject TEXT NOT NULL DEFAULT '',
    from_raw TEXT NOT NULL DEFAULT '',
    references_raw TEXT,
    parent_message_id TEXT
);
CREATE TABLE usenet_article_groups (
    article_id BIGINT NOT NULL REFERENCES usenet_articles(id),
    group_id BIGINT NOT NULL REFERENCES usenet_groups(id),
    inn_article_number BIGINT,
    PRIMARY KEY(article_id, group_id)
);
```

Do not use this short illustration as a deployment migration; the real model also enforces message-ID syntax, data shapes, size bounds, membership provenance, thread state and deletion behavior.

## 3. NNTP reader mechanics

Cythu's `src/Usenet/NntpReaderClient.php` talks to an internal INN reader service, **not** directly to arbitrary public Usenet peers. It implements a bounded reader seam (`NntpReaderInterface`):

- `ARTICLE <Message-ID>` → fetch one canonical article; distinguish NNTP 430/missing from temporary transport failures.
- `LIST ACTIVE <wildmat>` → authoritative group listing and group status for the selected site/scope.
- `GROUP` and `OVER` (with `XOVER` fallback) → article-number range and bounded per-group reconciliation; **not** unbounded `NEWNEWS`.
- Strict Message-ID and newsgroup token validation prevents command injection.
- Multi-line NNTP response handling is dot-unstuffed; preserves CRLF; excludes the terminator dot line before hashing/storing.
- Bounded connections/timeouts, per-line length and per-article/overview byte budgets. An indeterminate protocol state is discarded rather than reusing the socket.
- Reader and posting sessions are distinct interfaces; outbound `POST` is implemented via `NntpPostingClient` and `PostingService`.

For a **direct remote news-server** feature upstream, first design encrypted transport and authentication, newsreader-style group selection, polling limits, and legal/operational handling of remote posting; do not assume Cythu's loopback/private credential boundary applies to an Internet connection.

## 4. Ingest: fast path + correctness path

Actual entrypoint: `scripts/usenet_ingest.php`; commands: `feed`, `retry`, `reconcile`, `sweep`, `health`, `status`, `rebuild-threads`.

**Fast path — ChannelFeedConsumer:** INN writes append-only-ish feed events. The adapter reads bounded batches, records a durable byte-offset/fingerprint, validates an event, fetches its article by Message-ID and stores it. If INN notifies before the article is readable, a bounded retry queue parks it. Rotation/missed events are recovered by reconciliation.

**Correctness path — ReconciliationSweep:** For each carried group, `LIST ACTIVE`, `GROUP`, `OVER` (and fallback) walk forward by a durable article-number cursor, update existing crosspost memberships and ingest unobserved articles. `NEWNEWS` is deliberately not the contract.

**Idempotence:**

- `message_id` unique in `usenet_articles`.
- `(article_id,group_id)` unique in `usenet_article_groups`.
- Re-ingest changes verification/provenance/membership, not global article identity.
- One crossposted article appears in many groups but exists once as content.
- Retries/resumption operate in bounded batches with failure evidence rather than claiming every fetch succeeded.

**Recommended for upstream:** Start with **reader + reconciliation**, not the INN channel program, unless a local INN site is already part of the accepted deployment. A direct NNTP client can be useful with `GROUP`/`OVER`/`ARTICLE` and per-group cursors alone. The channel feed is an optimization/INN integration—not a mandatory requirement for every upstream user.

## 5. Group exposure, subscriptions and permissions

Cythu distinguishes four concepts, explicitly:

1. **Known:** a group name exists in the local catalog.
2. **Carried:** INN has been observed carrying it (via active list/placement); not a user choice.
3. **Exposed:** SysOp/steward explicitly allows it on Cythu's caller surfaces; hidden groups remain inaccessible even by direct article URL, search, unread, saved or posting endpoints.
4. **Followed:** caller opts to follow it for convenient navigation/notifications; separate from exposure authority.

Do **not** substitute FTN `user_echoarea_subscriptions` for Cythu's exposure model. If upstream prefers unified UI, put an adapter/view on top; do not silently give native Usenet groups FTN semantics.

`src/Usenet/UsenetConversationService.php` owns the one visibility predicate: a caller-visible article must belong to at least one group exposed at that site, and must not be suppressed. Article-level read/saved state stays canonical across crossposts.

Local posting identity is a separate `usenet_identities` row. An arbitrary remote `From` header never becomes an existing BBS caller merely because the display names match.

## 6. Posting lifecycle and truthful success

Cythu composes a local article with stable Message-ID and identity, persists a posting ledger, sends over NNTP, and later correlates local INN re-observation. `240` or an apparently successful network send does **not** prove distribution to remote sites.

Relevant files: `ArticleComposer.php`, `PostingService.php`, `NntpPostingClient.php`, `PostingLedger.php`, `LocalPostCorrelator.php`, `ReconciliationSweep.php`, `UsenetConversationService.php`.

The client distinguishes `queued`, `submitted`, `awaiting_local_acceptance`, `accepted_locally`, `failed`, `rejected` and uncertain outcomes. Only an identified re-observed article can establish local acceptance. Propagation beyond that site is **never inferred**.

**Upstream option:** ship read-only groups/threads first; make posting a later feature that explicitly requires real identity/authentication, TLS, rate limiting, anti-spam and uncertain-send recovery.

## 7. Implementable upstream work program (for Claude)

### Stage 0 — architecture choice and acceptance criteria

- Read upstream `src/Nntp/*`, `scripts/nntp_server.php`, `docs/NNTP.md`, `docs/EchoAreas.md`, the actual message read/save APIs and migrations.
- Confirm feature request: native remote NNTP **reader**, local INN-adapted reader, or both.
- Document `echoareas` non-coupling and choose the minimal Usenet store needed.
- State distinct credentials/authorization and retention policy; ask maintainer for any required product choices before adding privileged infrastructure.

### Stage 1 — bounded NNTP reader library

- Reader interface, fake deterministic NNTP transport and hardened socket/TLS transport.
- `LIST ACTIVE`, `GROUP`, `OVER`, `ARTICLE`; safe dot-stuffing/un-stuffing and CRLF handling.
- Validation and size/time budgets; useful transient vs permanent error categories.
- Test malformed status lines, oversized articles, early disconnect, continuation, unicode headers, bad Message-ID, dot-escaped article and server greeting.

### Stage 2 — native data store and read-only reconciliation

- Add `usenet_groups`, `usenet_articles`, `usenet_article_groups` plus durable cursors/observations, with correct constraints.
- Run a bounded ingest pass; duplicate observations and crossposts are idempotent.
- Correlate threads by `References` and `Message-ID`, **never solely by Subject**.
- Keep raw canonical bytes and sanitized UI projections separate.
- Honor group/account visibility and prevent direct-ID/search access to hidden content.
- Do not update `echoareas` or `echomail`.

### Stage 3 — caller-facing UI and administration

- Minimal newsgroup browse, article list, thread view, read state, opt-in follow, and site-health diagnostics using upstream's actual Web/Terminal UX conventions.
- Admin group allowlist/exposure choice distinct from incoming server active state.
- Keep FTN and Usenet labeling honest; no synthetic Binkp or network domain from newsgroup names.
- Use normal safe HTML/Terminal rendering for untrusted headers and MIME; neither remote HTML nor raw controls should execute.

### Stage 4 — outbound posting (optional later scope)

- Secure named posting identity, restricted credentials, proper `POST` protocol handling and durable uncertainty/receipt ledger.
- Per-group posting status, moderated group behavior and send rate limits.
- Reconcile by stable Message-ID before claiming local success.
- Independently test response-lost-after-acceptance without duplicate publication.

## 8. Source index verified in Cythu

Cythu is private: **the upstream Claude does not automatically have access**. These are precise paths to request as a selective source bundle if the maintainer wants a direct port, rather than a conceptual reimplementation.

| Path | Specific contribution |
|---|---|
| `database/migrations/v20260918001659_usenet_canonical_article_model.sql` | Groups/articles/crosspost membership, raw bytes and ingest cursor schema |
| `database/migrations/v20260918013900_usenet_identity_posting_lifecycle.sql` | Identity and posting ledger tables |
| `database/migrations/v20260918060000_usenet_w6a_suppression_ingest_runs.sql` | Takedown and pass evidence |
| `src/Usenet/NntpReaderInterface.php` | Read-side seam (actual interface is `NntpReaderInterface.php`) |
| `src/Usenet/NntpReaderClient.php` | Bounded NNTP reader implementation |
| `src/Usenet/CanonicalArticle.php` and `ArticleStore.php` | Raw article identity, validation, transactional upserts |
| `src/Usenet/GroupRegistry.php` | Known/carried site group catalog and reconciliation ranges |
| `src/Usenet/GroupExposure.php` | Exposed/hidden permission policy |
| `src/Usenet/ArticleIngester.php` | Fetch and ingest event outcome |
| `src/Usenet/ChannelFeedConsumer.php` | Optional internal INN channel log ingestion |
| `src/Usenet/ReconciliationSweep.php` | Per-group OVER-based recovery and membership reconciliation |
| `src/Usenet/UsenetConversationService.php` | Caller-facing group/thread/read/save/search/post API |
| `src/Usenet/NntpPostingClient.php`, `PostingService.php`, `PostingLedger.php` | Posting with truthful acceptance/retry semantics |
| `scripts/usenet_ingest.php` | Bounded CLI entrypoint |
| `templates/usenet.twig`, `public_html/js/usenet.js` | Native Web UI; cannot be ported wholesale into upstream UI |
| `telnet/src/UsenetWorkspace.php` | Cythu Terminal caller UI; depends on Cythu shell |
| `config/networks/usenet.descriptor.json` | Distinct Usenet network-kind contract and identity boundaries |
| `tests/Unit/Usenet*Test.php` | Deterministic coverage by slice |

## 9. Concrete tests / acceptance matrix

- FTN NNTP server regression: an existing `FidoNet.GENERAL` newsgroup and echomail message still works after all client migrations.
- Separate client import of `comp.lang.php` does not create or mutate an `echoareas` row or `echomail` record.
- A single Message-ID crossposted to two groups occupies exactly one `usenet_articles` row and two membership rows.
- Duplicate NNTP observations or retry do not duplicate a message.
- Distinct NNTP groups with overlapping article numbers are not conflated; each number is scoped to its group.
- Reading/saving one crosspost from either group has one canonical identity/read state.
- A hidden/unexposed group is inaccessible by group URL, direct article ID, search and post target.
- Malformed CRLF/dot-stuffing, oversized content, timeout, NNTP disconnect and missing article behave safely.
- A feed event ahead of availability is retried; if never read, reconciliation can recover it.
- A `POST` timeout after acceptance does not automatically create a second Message-ID/post.
- Remote `From:` containing the same name as a local account is never mistaken for the account.
- Without any configured NNTP client site, Web/Terminal reports “not configured” rather than throwing or polluting FTN areas.
- All migrations and scripts run against a disposable database/site only; no live public feed required for initial patch.

## 10. Upstream Claude's final output

Produce: architecture choice (direct reader vs INN-backed), smallest accepted read-only vertical slice, actual branch/diff, schema, tests/results, secure configuration example with no live credentials, operational notes, extension plan for posting, and a list of any product choices requiring the maintainer. Keep **client** and **server** functionality clearly distinguished.

## 11. License, attribution and collaboration

Upstream is <https://github.com/awehttam/binkterm-php>. Cythu descends from BinkTermPHP and retains its BSD-3-Clause license and attribution. If source is copied, preserve license/copyright notices and check individual file provenance. Technical design patterns may be independently ported with attribution as appropriate.

**Bottom line:** Cythu did **not** solve Usenet client support by treating NNTP groups as FTN echoareas. It built a native Usenet data model and reader/ingest adapter beside the existing FTN model. The upstream maintainer can reuse that distinction even if he chooses a lighter architecture than Cythu's full INN-backed implementation.
