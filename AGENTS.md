Refer to CLAUDE.md for important information about the project.

# Local Project Rules
You are running inside a project that uses Claude as its primary skills manager.

## Active Workflows
Before executing tasks, you must look into the following active instruction sets located in this workspace and strictly follow their procedural steps:

- @.claude/commands/
- @CLAUDE.md

## GitHub Access
Use the GitHub CLI `gh` for GitHub access by default in this workspace. Prefer `gh` over other GitHub integrations or APIs unless `gh` is unavailable or the task explicitly requires another path.

When `gh` fails from the sandbox with network, proxy, keyring, or auth-status errors, retry it with elevated permissions before concluding that GitHub access is actually broken. In this workspace, `gh` may need to run elevated to reach the network and local credential store correctly.

When posting multi-line GitHub issue or PR comments that include Markdown code spans or file paths, do **not** use inline quoted `gh ... --body "..."` command strings. On this workspace/shell combination that can mangle text, including dropping the first character of paths such as `telnet/...`. Instead, pass the body via a file or PowerShell here-string so the Markdown is sent to `gh` verbatim.

## Available Claude Commands
The workspace-local Claude command workflows currently available under `.claude/commands/` are:

- `/bump-version`
- `/new-migration`
- `/usercredits-workflow`
- `/logging-guide`
- `/new-webdoor`
- `/tackleissue <issue#>`
