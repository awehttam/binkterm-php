# Voting Booth

The Voting Booth lets users create and vote on polls. Active polls are listed on the web interface and in terminal sessions. Each poll is single-choice, and each user may vote once per poll.

## Table of Contents

- [For Users](#for-users)
- [Creating a Poll](#creating-a-poll)
- [Enabling and Disabling](#enabling-and-disabling)
- [Terminal Access](#terminal-access)
- [Admin Management](#admin-management)

---

## For Users

Active polls are available at `/polls`. Polls you have not yet voted on are listed first, followed by polls you have already voted on.

Selecting a poll shows the question and all available options. After voting, the results are revealed with vote counts and percentages.

Each user may vote once per poll. Voting on a closed or inactive poll is not allowed.

---

## Creating a Poll

Any logged-in user can create a poll at `/create-poll`. Creating a poll costs credits (default 15 credits; configurable by the sysop from **Admin → BBS Settings → Credits**).

**Requirements:**

- Question: 10–500 characters
- Options: 2–10 options, each up to 200 characters; options must be unique

Once created, the poll is immediately active and visible to all users.

---

## Enabling and Disabling

The Voting Booth is enabled by default. It can be toggled from **Admin → BBS Settings → Features**. When disabled, the `/polls` and `/create-poll` pages are not accessible and the terminal menu option is hidden.

---

## Terminal Access

In Telnet and SSH sessions, the Voting Booth is accessible from the main menu by pressing **P**.

The polls list shows each poll's question along with its status:

- **OPEN** (yellow) — you have not yet voted on this poll
- **VOTED** (green) — you have already voted

Select a poll by entering its number. On an open poll, the options are displayed and you vote by entering the option number. On a voted poll, the results are shown directly with vote counts and percentages for each option. Press **Q** to return to the main menu.

---

## Admin Management

Admins can review, create, close, and delete polls from **Admin → Polls**.

- **Active** polls are visible to users and accept votes
- **Closing** a poll prevents new votes but keeps results visible to users who already voted
- **Deleting** a poll permanently removes it and all associated votes from the database
