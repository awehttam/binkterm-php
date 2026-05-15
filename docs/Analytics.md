# Analytics

**Admin → Analytics** contains four reports that give sysops visibility into how the BBS is being used.

## Table of Contents

- [Activity Stats](#activity-stats)
- [AI Usage](#ai-usage)
- [Ad Analytics](#ad-analytics)
- [Economy Viewer](#economy-viewer)
- [Referral Analytics](#referral-analytics)
- [Sharing](#sharing)

---

## Activity Stats

**Admin → Analytics → Activity Stats**

A comprehensive view of user activity across the BBS. All data is filtered by a selectable period (7 days, 30 days, 90 days, or all time). An **Exclude admins** checkbox removes admin-account activity from all counts.

### Summary Cards

Six headline numbers across the top of the page:

| Card | What it counts |
|------|---------------|
| Echomail | Echo area views + messages sent |
| Netmail | Netmail reads + messages sent |
| Files | File-related events |
| Door Plays | Door game sessions started |
| Logins | Authentication events |
| Total | All tracked events combined |

### Overview Tab

Two tables side by side:

**Activity by Category** — event counts for each category: echomail (with view and sent sub-rows), netmail (with read and sent sub-rows), files, doors, nodelist, chat, and auth (with per-source sub-rows showing web, telnet, SSH, and other login sources). Each row has a proportional progress bar.

**Daily Activity** — event totals for each day in the selected period, most recent first.

### Popular Areas Tab

- **Most Viewed Echo Areas** — ranked by the number of times users opened each area
- **Most Active Echo Areas** — ranked by the number of messages posted

### Interests Tab

*(Shown only when the Interests feature is enabled.)*

Most subscribed interests, ranked by subscriber count.

### Doors Tab

- **Most Played Web Doors** — web door sessions ranked by count
- **Most Played DOS Doors** — DOS door sessions ranked by count

### File Activity Tab

- **Top Downloaded Files** — individual files ranked by download count
- **Most Browsed File Areas** — file areas ranked by the number of times they were opened

### Nodelist Tab

- **Most Searched Nodelist Queries** — search terms users typed in the node browser, ranked by frequency
- **Most Viewed Nodes** — individual node detail pages ranked by view count

### Top Users Tab

Users ranked by total tracked events in the selected period. Admin accounts can be excluded using the checkbox at the top of the page.

### Hourly Tab

Event counts grouped by hour of day (0–23), displayed in the admin's local timezone. Useful for identifying peak usage hours and scheduling maintenance windows.

---

## AI Usage

**Admin → Analytics → AI Usage**

Request counts, token usage, estimated cost, and failure rate for all AI features (assistant, bots, digests). See [AI Providers and Usage](AIProviders.md) for full documentation.

---

## Ad Analytics

**Admin → Analytics → Ad Analytics** *(registered license required)*

Impression and click-through data for all ads. Period selector covers 7 days, 30 days, 90 days, or all time.

### Summary Cards

| Card | Description |
|------|-------------|
| Total Impressions | Times any ad was displayed |
| Total Clicks | Times a click-through link was followed |
| Overall CTR | Click-through rate across all ads (clicks ÷ impressions) |
| Active / Total Ads | How many ads are currently enabled out of the total |

### Daily Activity

A day-by-day table showing impressions (blue) and clicks (green) for each date in the selected period, with proportional bars for visual comparison.

### Per-Ad Breakdown

A table with one row per ad showing:

- Ad title, active/inactive status, and click-through URL
- Impression count and click count
- CTR percentage with an impression-scaled bar
- Date of last impression and last click

---

## Economy Viewer

**Admin → Analytics → Economy Viewer** *(registered license required)*

A read-only view of the credit economy across all users. Period selector covers 7 days, 30 days, 90 days, or all time. If credits are currently disabled, a notice is shown but historical data is still available.

### Summary Cards

| Card | Description |
|------|-------------|
| Credits In Circulation | Total credits held across all user wallets |
| Funded Wallets | Number of users with a balance above zero |
| Average Balance | Mean credit balance across all users |
| Median Balance | Median credit balance across all users |

### Period Snapshot

Activity within the selected period: transaction count, number of active users, total credits earned, total credits spent, and net flow (earned minus spent, color-coded green or red).

### Current Distribution

A snapshot of the current state of the economy regardless of period: funded vs. total users, average balance, median balance, the single largest balance, and the username of the richest account.

### Transaction Types

A breakdown of every transaction type that occurred in the period, showing the count, number of distinct users involved, and the net credit flow for that type (positive = net credits created, negative = net credits consumed).

### Top Earners and Top Spenders

Side-by-side tables ranking users by total credits earned and total credits spent in the period, with transaction counts.

### Richest Accounts

Users ranked by their current credit balance.

### Recent Transactions

The most recent transactions in the period: user, transaction type, description, signed amount, and running balance after the transaction.

---

## Referral Analytics

**Admin → Analytics → Referral Analytics** *(registered license required)*

An overview of how the referral program is performing. Requires the referral feature to be enabled in **Admin → BBS Settings → Credits**.

### Summary

Two headline counts: total users who joined via a referral link, and the number of distinct users who have successfully referred at least one person.

### Top Referrers

Users ranked by how many signups their referral link has generated, with their referral code and the total bonus credits they have earned (shown only when credits are enabled).

### Recent Referral Signups

The most recently joined users who arrived via a referral link, showing who referred them and when they joined.

---

## Sharing

**Admin → Analytics → Sharing**

A view of all active public share links created by users, with access statistics.

### Shared Messages Tab

Each row represents one echomail message that has been shared via a public link:

| Column | Description |
|--------|-------------|
| Subject | Message subject line |
| Area | Echo area tag |
| Shared By | Username who created the link |
| Views | Total times the shared link has been accessed |
| Top Referrers | External URLs that linked to this share, with access counts |
| Last Accessed | When the share link was most recently opened |
| Access | Public or Private |
| Open | Link to view the shared message |

### Shared Files Tab

Same structure as Shared Messages, with filename in place of subject, and the Access column indicating whether the file is also accessible via FREQ (shown as **FREQ**) or web only.
