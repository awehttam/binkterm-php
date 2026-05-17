# Credit Economy Setup Guide

BinktermPHP's built-in credits system lets you reward participation, manage resource costs, and monetize premium features — or simply ignore it entirely if you prefer a cost-free BBS. This guide is aimed at sysops who want to design and tune their economy, not at developers adding new credit types (see `docs/CreditSystem.md` for that).

All credit settings are configured through **Admin → BBS Settings → Credits System Configuration**. Changes take effect immediately without a server restart.

---

## Should You Enable Credits?

Credits work best when you have a goal in mind. Common goals:

- **Encourage participation** — Reward quality posts and daily logins to keep activity high.
- **Manage resources** — Charge for crash delivery, AI usage, or large file downloads to cover real costs.
- **Create a social layer** — Let users earn status through credit balance and transfers.
- **Gate premium features** — Require credits to create polls, access special areas, or play door games.

If none of these fit your community, leave credits disabled. A credit system that adds friction without purpose will frustrate users.

---

## Getting Started

### Enable the System

Turn on the master switch in **Admin → BBS Settings → Credits System Configuration**. While disabled, all credit UI is hidden, no transactions are recorded, and all cost/reward logic is bypassed.

### Choose a Currency Symbol

The symbol appears next to balances throughout the interface. Keep it short (up to 5 characters). Common choices:

| Symbol | Feel |
|--------|------|
| `CR`   | Classic BBS / sci-fi neutral |
| `$`    | Familiar, implies real-money feel |
| `BK`   | BBS-branded (e.g. "Binkbucks") |
| `★`   | Gamified / points-style |
| `bits` | Hacker aesthetic |

Avoid symbols that imply real currency unless you actually integrate real payment.

---

## Starting Balance

New users arrive with zero credits. The **approval bonus** (`approval_bonus`, default 100) is a one-time grant given when a sysop approves a pending account. This is every user's first credits and sets expectations for the overall scale.

**Guideline:** Set the approval bonus to roughly 4–7× the daily login amount. This gives new users enough runway to participate before they've established a login habit. Too small and new users feel broke immediately; too large and it devalues the daily reward.

---

## Daily Login Economy

The daily login reward (`daily_amount`, default 25) is the heartbeat of your economy. It flows credits in at a predictable rate and rewards consistency.

The `daily_login_delay_minutes` setting (default 5) prevents bots from hitting the login endpoint and immediately collecting the bonus. Leave this at 5 minutes unless you have a specific reason to change it.

**Sizing the daily reward:**

The daily amount sets the baseline purchasing power of an active user over time. Think about how long it should take a regular user to afford your most common paid action:

- If a netmail costs 5 credits and a user logs in daily, they can send 5 netmails per day without any posting activity. Is that the right balance?
- If your most expensive action (e.g. crashmail at 10 credits) should be affordable once a week for casual users, set `daily_amount` to at least `crashmail_cost / 7`.

The **14-day return bonus** (`return_14days`, default 50) fires once when a lapsed user logs in after 14+ days away. It acts as a re-engagement incentive. Set it to 2× the daily amount or a bit more — enough to feel meaningful, not so much that churning and returning becomes a farming strategy.

---

## Rewards: Earning Credits

### Echomail Posts

Posting a public message earns `echomail_reward` credits (default 3). Posts of approximately 2+ paragraphs earn double (`echomail_reward × 2`). This bonus encourages substantive contributions over one-liner replies.

**Tuning tips:**
- If you want a high-volume, active forum, keep this reward meaningful (3–10 credits).
- If you want to discourage spam posting, lower it or set it to 0. Pure reward can be gamed.
- Consider: at your chosen daily rate, how many posts would a user need to break even if they also send netmails? That ratio reveals whether posting covers its costs or requires supplemental login income.

### File Uploads

`file_upload_reward` (default 0) is paid after a successful upload. This is disabled by default because rewarding uploads without curation can flood your file areas. Enable it when you have a community that contributes quality files and you want to recognize that contribution.

Set this to 0 while your BBS is new. Enable it once you have active sysop moderation to prevent reward farming via junk uploads.

### Admin Adjustments

You can manually grant or deduct credits from any user account via **Admin → Users → [user] → Credits**. Use this for:
- Contest prizes
- Compensating users for system errors
- Penalizing abuse
- Seeding new accounts beyond the approval bonus

All admin adjustments are logged in the transaction ledger with a note field you provide.

### Referral Bonuses

Enable the referral system (`referral_enabled`, default false) to give existing users `referral_bonus` credits (default 25) when someone they referred gets approved. The referred user must sign up under the referrer's account.

This is a useful growth lever for invite-only or private BBSs. On open-registration systems, watch for self-referral abuse.

---

## Costs: Spending Credits

### Netmail (Private Messages)

`netmail_cost` (default 1) is charged per private message sent. A small cost discourages spam netmail while still being accessible. Most operators leave this at 1–5.

If your BBS is support-oriented or you want to encourage users to contact each other freely, set this to 0. If netmail is a premium communication channel, 5–15 is appropriate.

### Crashmail (Direct Delivery)

`crashmail_cost` (default 10) is an additional cost on top of netmail when crash/direct delivery is selected. Crash delivery bypasses normal polling schedules and may incur real costs on your side (bandwidth, uplink calls). This should be priced to reflect that — typically 3–10× the base netmail cost.

If your uplink is always-on with no per-poll cost, you may set this to 0 or a nominal amount.

### Poll Creation

`poll_creation_cost` (default 15) is charged when a user creates a new poll in the voting booth. This is purely a frivolous-use deterrent — polls take up space and attention. Adjust based on how freely you want polls created.

### File Downloads

`file_download_cost` (default 0) is charged before a file download begins. This is disabled by default because most BBSs expect free file distribution. Enable it for:
- Exclusive or premium file sections (paid access model)
- Large-file areas where bandwidth has real cost
- Gated content that requires demonstrated community participation to access

Pair download costs with upload rewards to create a "ratio" economy reminiscent of classic BBS file trading.

### File Uploads

`file_upload_cost` (default 0) is charged before accepting an upload. This is uncommon but useful if you want to deter bulk spam uploads from low-balance users. Usually left at 0.

---

## Transfer Fee

`transfer_fee_percent` (default 0.05, meaning 5%) is a percentage taken from the transfer amount when one user sends credits to another. It flows to the system (credits are removed from circulation) rather than to a specific account.

A small fee:
- Creates a mild sink that slows inflation over time
- Discourages credit gifting as a way to accumulate wealth via sockpuppets
- Keeps the economy from becoming purely zero-sum

Set this to 0 if you want free transfers and prefer a more cooperative feel. Set it to 10–15% if you want transfers to be significant decisions rather than casual activity.

---

## AI Usage

`ai_credits_per_milli_usd` (default 0, disabled) defines how many credits are charged per $0.001 of AI provider spend when a user uses the AI message assistant. This allows you to partially or fully offset AI infrastructure costs with credits.

**Example:** If you set this to 10, and an AI request costs $0.002 (2 milli-USD), the user is charged 20 credits.

**Before enabling this:**
1. Know your actual per-request AI cost from your provider.
2. Decide what fraction of that cost you want users to bear.
3. Set the rate so a typical AI interaction costs roughly 5–25% of a user's daily income, not the full amount.

If AI is subsidized by the sysop and credits aren't required, leave this at 0.

---

## Designing a Balanced Economy

### The Inflation Problem

Credits inflate when more enter the system than leave. In a purely reward-based system with no meaningful costs, balances grow indefinitely. High balances eventually become meaningless — users stop caring about earning more.

Sinks (places credits leave the system) are essential for long-term balance. Ensure your costs and fees collectively remove roughly the same volume of credits that daily logins and post rewards inject.

**Back-of-envelope check:**
- Assume 20 active users logging in daily: `20 × daily_amount` credits enter per day.
- Estimate average daily sends: `avg_netmails × netmail_cost + avg_polls × poll_creation_cost`.
- If the inflow >> outflow, your economy will inflate and rewards will feel hollow within weeks.

### The Deflation Problem

Overly aggressive costs leave users perpetually broke. If active users can't afford basic actions, they'll disengage. As a rule of thumb:

- A user who logs in daily and posts 2–3 times should be able to send at least 1 netmail and have credits left over.
- Users should not be penalized into debt for normal participation.

### Suggested Configurations

These are starting points — adjust based on your community size and activity level.

**Minimal/social BBS** (low friction, encourage conversation)

| Setting | Value |
|---------|-------|
| `daily_amount` | 20 |
| `approval_bonus` | 75 |
| `echomail_reward` | 2 |
| `netmail_cost` | 0 |
| `crashmail_cost` | 5 |
| `poll_creation_cost` | 10 |
| `file_upload_reward` | 0 |
| `file_download_cost` | 0 |
| `transfer_fee_percent` | 0.02 |

**Classic BBS** (moderate friction, ratio-style file economy)

| Setting | Value |
|---------|-------|
| `daily_amount` | 25 |
| `approval_bonus` | 100 |
| `echomail_reward` | 3 |
| `netmail_cost` | 2 |
| `crashmail_cost` | 10 |
| `poll_creation_cost` | 15 |
| `file_upload_reward` | 10 |
| `file_download_cost` | 5 |
| `transfer_fee_percent` | 0.05 |

**Resource-managed / premium** (AI and crash delivery cost real money; users bear more)

| Setting | Value |
|---------|-------|
| `daily_amount` | 50 |
| `approval_bonus` | 200 |
| `echomail_reward` | 5 |
| `netmail_cost` | 3 |
| `crashmail_cost` | 20 |
| `poll_creation_cost` | 25 |
| `file_upload_reward` | 15 |
| `file_download_cost` | 10 |
| `ai_credits_per_milli_usd` | 5 |
| `transfer_fee_percent` | 0.05 |

---

## Monitoring the Economy

The economy dashboard at **Admin → Economy** (requires a valid BinktermPHP license) gives you a live view of:

- **Credits in circulation** — total balance held across all user accounts
- **Transaction volume** — inflow vs. outflow over selected periods (7d, 30d, 90d, all-time)
- **Transaction type distribution** — how much comes from login rewards vs. post rewards vs. costs
- **Top earners and spenders** — useful for spotting farming behavior or unusually high usage
- **Richest accounts** — watch for runaway accumulation that might indicate hoarding or sockpuppets

Review the dashboard weekly for the first month after launch. You'll quickly see whether your economy is inflating, deflating, or balanced. Adjust settings in **Admin → BBS Settings** as needed; changes take effect on the next relevant action without resetting existing balances.

---

## Credits and Real Payment

BinktermPHP credits are a first-party virtual currency with no built-in connection to real money. There is no built-in payment gateway, no exchange rate mechanism, and no automatic top-up flow.

If you want to accept real payment to top up credits, you must handle the payment externally (e.g. via PayPal, Stripe, or a donation link) and then manually grant the credits via **Admin → Users → [user] → Credits** with an appropriate note. Keep a record of these transactions for your own accounting.

**If you describe credits as having monetary value or offer them in exchange for money, be aware of the legal and regulatory implications in your jurisdiction.** Virtual currencies that can be purchased, traded, or cashed out may be subject to financial regulations. Most sysops avoid this by keeping credits strictly non-monetary — they are earned through participation and spent within the BBS only, with no cash-out path.

---

## Common Pitfalls

**Setting rewards but no sinks.** Users accumulate unlimited credits that eventually mean nothing. Add costs or fees proportionate to your reward rates.

**Setting costs so high that new users can't participate.** The approval bonus should cover at least a week of normal activity before a user has logged in enough times to sustain themselves on daily rewards.

**Forgetting AI costs.** If you enable AI features, the `ai_credits_per_milli_usd` setting is 0 by default — meaning AI is free to users even if it costs you real money. Decide consciously whether to pass that cost along.

**Not monitoring.** A balanced economy at launch can drift over time as user behavior shifts. Check the economy dashboard monthly.

**Using credits as punishment.** Negative balances (from admin deductions going below zero) can happen, but BinktermPHP does not prevent users from going negative by default. If you penalize users via credit deductions, be explicit about this in your BBS rules so it doesn't feel arbitrary.

---

## See Also

- `docs/CreditSystem.md` — Developer reference: transaction types, PHP API, how to add new credit types
- **Admin → BBS Settings → Credits System Configuration** — All configurable values
- **Admin → Economy** — Real-time economy dashboard (license required)
- **Admin → Users → [user] → Credits** — Manual credit adjustments per user
