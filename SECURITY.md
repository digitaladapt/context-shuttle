# Security Policy

## Supported versions

Only the latest release line receives security fixes.

## Reporting a vulnerability

Report privately to the maintainer (see the security contact in the Gitea
repo settings). Please include:

- Description of the issue and its impact
- Steps or proof-of-concept to reproduce
- Affected endpoints or tools

You will receive an acknowledgement within 72 hours. Please avoid public
disclosure until a fix is released.

## Scope notes

- v1 ships **without authentication** on `/mcp` and `/tools/*`: deploy it
  behind a reverse proxy or on a trusted network. Auth is on the roadmap
  (env `TOOL_TOKEN` guard).
- Secrets belong in environment variables, never in the image or repo
  (`.env` is gitignored; `.env.example` documents the safe defaults).
- Tool handlers run with the process's privileges — review YAML additions
  and handler code as you would any privileged code.