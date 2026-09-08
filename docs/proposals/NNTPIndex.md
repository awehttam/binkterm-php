# NNTP Proposals Index

**Generated:** 2026-08-29

> This index is a navigational aid for the NNTP-related proposal documents under
> `docs/proposals/`. It was generated with AI assistance and may not have been reviewed
> for accuracy. Each linked document carries its own draft/status notice; where a proposal
> and the code disagree, the code and `docs/NNTP.md` are authoritative.

## Reading order

1. **[NNTPServer.md](NNTPServer.md)** — the foundation; read this first.
2. **[NNTPNetmail.md](NNTPNetmail.md)** — extends the server with a per-user netmail folder.
3. **[NNTPClientTransport.md](NNTPClientTransport.md)** — the other direction: BinktermPHP as an outbound subscriber.
4. **[NNTPPeering.md](NNTPPeering.md)** — server-to-server article exchange (`IHAVE`/`CHECK`/`TAKETHIS`).

## The proposals

### [NNTPServer.md](NNTPServer.md) — NNTP Server Interface
**Status:** Draft; substantially implemented on the `nntpserver` branch.

Exposes FTN echoareas as Usenet newsgroups so standard newsreaders (Thunderbird, slrn,
tin, etc.) can read and post echomail. Covers the FTN↔NNTP data-model mapping, newsgroup
name translation, `Message-ID` construction (folding in both halves of the FTN `MSGID`),
per-echoarea article numbering (`nntp_article_numbers` + watermark), header translation,
`STARTTLS` / implicit-TLS listeners, `AUTHINFO` auth, and bidirectional posting via
`MessageHandler::postEchomail()`.

Implemented under `src/Nntp/` + `scripts/nntp_server.php`; see `docs/NNTP.md`. Design
Decision 3 rules out server-to-server peering (see NNTPPeering.md).

### [NNTPNetmail.md](NNTPNetmail.md) — NNTP Netmail Folder
**Status:** Draft; not yet implemented.

Adds a single per-user **virtual netmail newsgroup** on top of the NNTP server. Articles
are the authenticated user's own netmail (`netmail WHERE user_id = …`), readable in any
newsreader; `POST` routes out through `MessageHandler::sendNetmail()`. Introduces an
`NntpGroupSource` strategy so netmail and echomail share transport and command dispatch
without the echoarea assumptions. Covers per-user article numbering
(`nntp_netmail_article_numbers`), a netmail-shaped article builder (real `To:` header),
destination resolution for `POST` (reply-derivation plus a parseable `To:` / `X-FTN-To:`
path), strict user-scoping, and new `config/nntp.json` keys.

### [NNTPClientTransport.md](NNTPClientTransport.md) — Upstream Client Transport
**Status:** Draft; not yet implemented.

The inverse of NNTPServer.md: BinktermPHP connects *outbound* to one or more upstream news
servers, pulls articles from subscribed newsgroups, and pushes locally-authored messages
back upstream — behaving as a network peer/subscriber rather than a user-facing service.
Modeled on the existing QWK client work. Shares article/header translation, `Message-ID`
construction, and threading helpers with the server side rather than reimplementing them.

### [NNTPPeering.md](NNTPPeering.md) — NNTP Peering
**Status:** Draft; not yet implemented.

Server-to-server article exchange (`IHAVE` / `CHECK` / `TAKETHIS`, streaming mode), which
NNTPServer.md Design Decision 3 explicitly excludes and `NntpSession::dispatch()` currently
rejects with `500`. Written against the `nntpserver` branch baseline. Would let BinktermPHP
feed and receive echomail with other NNTP servers directly, alongside (not replacing) the
FTN mailer.

## Shared concerns

All four proposals converge on the same translation rules and should draw from common code
in `src/Nntp/` — `NntpMessageId`, `NntpArticleBuilder` / `NntpArticleParser`, header
naming, `From:` synthesis, charset handling, and `reply_to_id`-based threading — rather
than maintaining parallel implementations.

## Related non-proposal docs

- **`docs/NNTP.md`** — operator-facing documentation for the implemented NNTP server
  (authoritative where it and any proposal disagree).
