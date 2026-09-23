# Changelog

All notable changes to context-shuttle are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Composer package renamed `devgnome/context-shuttle` →
  `digitaladapt/context-shuttle`, matching the GitHub and Docker Hub repos.
- Public-facing links now use `code.digitaladapt.com/public/context-shuttle`
  (the `code.devgnome.com` name is LAN-only).
- `README.md` dependency note points at
  `code.digitaladapt.com/public/php-mcp-server`; the `php-mcp/server` VCS
  repository moved to the same public host.
- Conform to Guiding Light §5/§6 env & image rules: `.env` and
  `config/reference.php` are no longer committed (`.env` is local-only;
  `reference.php` is a generated IDE-support dump), `.env.dev` removed,
  `.gitignore`/`.dockerignore` aligned to the canonical baselines.
- Dockerfile: non-root runtime user (`app`, uid/gid 1000), wires
  `docker/Caddyfile` and `docker/entrypoint.sh` (previously unreferenced),
  adds HEALTHCHECK against `GET /health`, installs ca-certificates/curl.
- `LOG_LEVEL` env var is now actually read: both monolog handlers use
  `%env(LOG_LEVEL)%` (default `info`), and `.env.example` documents it.

### Added

- `.env.example` now documents `DEFAULT_URI` (required by routing when
  `.env` is absent).
- `.ci/conformance.sh` + `css-control-size.py` vendored from the shared
  standards repo; the CI conformance step now runs instead of silently
  skipping.

### Fixed

- `docker-bake.hcl`: `DOCKERHUB_TARGET` default was misspelled
  `digitaladapt/comtext-shuttle`; corrected to
  `digitaladapt/context-shuttle`.

## [1.0.0] - 2026-09-20

### Added

- YAML tool registry (`config/tools/*.yaml`) with strict boot-time
  validation: names, handlers (reflection-verified), parameter types,
  enums, defaults, items.
- MCP endpoint `POST /mcp` (streamable HTTP, JSON response mode,
  stateless per request) bridged into the Symfony request cycle via
  `php-mcp/server`'s Protocol/Dispatcher.
- REST surface: `GET /tools`, `POST /tools/{name}` sharing the exact MCP
  execution pipeline.
- Generated OpenAPI 3.1 document at `GET /openapi.json`.
- Structured JSON invocation logging (channel `mcp_invocation`):
  request id, tool, arguments, duration, result preview, error info.
- Health/readiness split: `GET /health` (liveness) and `GET /ready`
  (registry loaded, tool count).
- Example tool `get_weather` (Open-Meteo; free-text geocoding or
  `lat,lon`; metric/imperial; 1–7 day forecast).
- Toolchain: PHPStan (level 8), php-cs-fixer, PHPUnit; composer scripts
  `lint`, `cs-fix`, `stan`, `test`.
- Docker: FrankenPHP 1 / PHP 8.5 / trixie base, php.ini, Caddyfile,
  entrypoint (prod cache warmup).

[Unreleased]: https://code.digitaladapt.com/public/context-shuttle/compare/v1.0.0...HEAD
[1.0.0]: https://code.digitaladapt.com/public/context-shuttle/releases/tag/v1.0.0