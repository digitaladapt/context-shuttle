# Contributing to context-shuttle

Thanks for considering a contribution!

## Adding a tool

The highest-value contribution. See README → "Defining a tool":

1. Add `config/tools/<name>.yaml`.
2. Implement the handler (public service, public method).
3. Register the service as `public: true` in `config/services.yaml`.
4. Add a test in `tests/Integration/`.

## Development setup

```bash
composer install
composer lint && composer stan && composer test
```

All three must pass before a PR is mergeable. Coverage is measured in CI
(pcov); there is no coverage *gate* yet — the floor rises as coverage does.

## Conventions

- PHP 8.5, Symfony 8.1, `declare(strict_types=1)` everywhere.
- Tool YAML is the single source of truth for tool surface; don't add
  parameters in code that aren't declared in YAML.
- One tool per YAML file; file name and tool name should match.
- Structured logs over `echo`/`dump()`; use the `mcp_invocation` channel
  for anything invocation-related.

## Reporting bugs

Open an issue with: the tool YAML, the request (curl command), the
response, and the relevant `mcp_invocation` log line.