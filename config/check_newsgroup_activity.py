#!/usr/bin/env python3
"""
check_newsgroup_activity.py

Quick diagnostic tool for L33Test's Usenet gateway project.
Connects to a public NNTP server (default: aioe.org) and checks each
candidate newsgroup for real activity: article count, and how recent
the most recent article actually is.

Usage:
    python3 check_newsgroup_activity.py

No dependencies beyond Python's stdlib (nntplib). Note: nntplib was
removed from Python 3.13+ stdlib (PEP 594) - if you're on 3.13+,
install the backport first:
    pip3 install nntplib --break-system-packages
"""

import nntplib
import datetime

NNTP_SERVER = "nntp.aioe.org"
NNTP_PORT = 119

# Candidate groups to check - edit this list as needed
CANDIDATE_GROUPS = [
    "alt.folklore.computers",
    "comp.os.cpm",
    "alt.bbs",
    "alt.bbs.fidonet",
    "alt.retrocomputing",
    "rec.games.retro",
    "comp.retrocomputing",
]


def check_group(server, group_name):
    """Query a single group and return (article_count, first_id, last_id, newest_date)."""
    try:
        resp, count, first, last, name = server.group(group_name)
    except nntplib.NNTPTemporaryError as e:
        return {"group": group_name, "error": f"NNTP error: {e}"}
    except Exception as e:
        return {"group": group_name, "error": f"Failed: {e}"}

    result = {
        "group": group_name,
        "count": count,
        "first": first,
        "last": last,
        "newest_date": None,
    }

    # Try to fetch the HEAD of the most recent article to get its actual date.
    # This tells us if "last article number" is recent or ancient.
    if count > 0:
        try:
            resp, info = server.head(str(last))
            for line in info.lines:
                line_str = line.decode("utf-8", errors="replace") if isinstance(line, bytes) else line
                if line_str.lower().startswith("date:"):
                    result["newest_date"] = line_str[5:].strip()
                    break
        except Exception as e:
            result["newest_date"] = f"(couldn't fetch: {e})"

    return result


def main():
    print(f"Connecting to {NNTP_SERVER}:{NNTP_PORT} ...")
    try:
        server = nntplib.NNTP(NNTP_SERVER, NNTP_PORT, timeout=30)
    except Exception as e:
        print(f"FAILED to connect: {e}")
        print("If this is a permissions/firewall issue, check that outbound port 119 isn't blocked.")
        return

    print(f"Connected. Server welcome: {server.getwelcome()}\n")

    results = []
    for group in CANDIDATE_GROUPS:
        print(f"Checking {group} ...")
        results.append(check_group(server, group))

    server.quit()

    print("\n" + "=" * 70)
    print(f"{'Group':<28} {'Articles':>10} {'Newest article date'}")
    print("=" * 70)
    for r in results:
        if "error" in r:
            print(f"{r['group']:<28} {'ERROR':>10}  {r['error']}")
        else:
            print(f"{r['group']:<28} {r['count']:>10}  {r['newest_date']}")

    print("\nNote: 'Articles' is the count currently retained on THIS server")
    print("(retention windows vary by provider), not the group's all-time total.")
    print("A recent 'newest article date' is the real signal of active use -")
    print("a high article count with an old newest date means the group is dead")
    print("weight left over from retention, not real activity.")


if __name__ == "__main__":
    main()
