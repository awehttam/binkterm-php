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

## Available Claude Commands
The workspace-local Claude command workflows currently available under `.claude/commands/` are:

- `/bump-version`
- `/new-migration`
- `/usercredits-workflow`
- `/logging-guide`
- `/new-webdoor`
- `/tackleissue <issue#>`
