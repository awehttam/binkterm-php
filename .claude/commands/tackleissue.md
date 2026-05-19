# Tackle GitHub Issue

Interactive workflow for picking up, implementing, and closing a GitHub issue.

## Invocation

```
/tackleissue <issue-number>
```

## Step 1 — Assign the Issue

Fetch the issue and assign it to the current developer:

```bash
DEVELOPER=$(git config user.name)
gh issue view <issue-number>
gh issue edit <issue-number> --add-assignee "$DEVELOPER"
```

## Step 2 — Review with the Developer

Display the full issue body, labels, and any linked PRs. Summarize your understanding of:

- What the problem or feature request is
- Which subsystem(s) are affected
- What a minimal correct implementation looks like

Ask any clarifying questions before writing any code. Do not begin implementation until the developer confirms the plan.

## Step 3 — Implementation Loop

Repeat until the developer signs off:

1. Implement the agreed changes.
2. Describe what was changed and how to test it.
3. Wait for the developer to test and provide feedback.
4. Apply any requested changes and return to step 2.

**Do NOT commit or push at any point in this loop.** Only the developer may stage and commit.

## Step 4 — Sign-off Tasks

When the developer explicitly signs off on the work:

### 4a. Documentation

- Check `docs/DEVELOPER_GUIDE.md`'s Doc Maintenance Checklist for subsystem→doc pairings and update any affected docs.
- Read the current version from `src/Version.php`.
- Add a concise entry to the matching `docs/UPGRADING_x.y.z.md` (the file for the current version) describing what changed, why it matters, and any upgrade action required. Follow the UPGRADING voice rules from `/bump-version`: self-contained, no assumed shared context.

### 4b. Implementation Notes on the Issue

Post a comment to the GitHub issue summarising what was implemented:

```bash
gh issue comment <issue-number> --body "$(cat <<'EOF'
## Implementation Notes

<summary of changes — files touched, approach taken, any known limitations>

Changes are staged locally and have been signed off by the developer.
EOF
)"
```

### 4c. Close the Issue

```bash
gh issue close <issue-number> --reason completed
```

## Constraints

- Never automatically commit, push, or open a PR. Only the developer does that.
- Never close the issue before the developer signs off.
- If the issue has a milestone or a linked PR, note it but do not modify the milestone automatically.
