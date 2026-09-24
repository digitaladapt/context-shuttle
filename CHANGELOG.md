# Changelog

All notable changes to context-shuttle are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- CI now calls the shared reusable workflows instead of carrying its own
  copies. `.gitea/workflows/{tests,develop,docker,publish}.yaml` went from
  265 lines of hand-maintained steps to 143 lines of trigger plus `uses:` —
  the pipeline itself lives in the shared standards repo, so a change there
  reaches every project that calls it with no PR here. The inlined
  `tests.yaml` existed because the cross-repo `uses:` form was believed not
  to work on this instance; it does work, and the original failure was the
  source repo being unreachable to the runner rather than the syntax.
- Leaf dev-tool configs re-synced from the shared repo: `phpstan.neon.dist`
  (level 6 + the high-signal checks, plus an empty `phpstan-baseline.neon`),
  `.php-cs-fixer.dist.php`, `.editorconfig`, `LICENSE`, and
  `.ci/conformance.sh` (+ the newly vendored `validate-bake.py`). PHPStan
  stays clean at the shared level, so the baseline ships empty rather than
  recording violations.
- Adopting the shared php-cs-fixer config reformatted 29 of 31 files. This is
  a formatting change, not a behaviour change: the rules are `@Symfony` +
  `@Symfony:risky`, and the only non-whitespace edits are native-function
  calls taking a leading `\` (14 files) and yoda/`self::` idiom. All 61 tests
  pass after the reformat.
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
- The `get_health_logs` tool now states its measurement units instead of
  leaving them implicit: the description lists mmHg (systolic/diastolic),
  bpm (heart rate), and lbs (weight), and every response carries the same
  mapping as `meta.units`. vital-pulse's dashboard labels readings with
  these units but its API returns bare numbers, so callers previously had
  to infer them; any units vital-pulse reports itself take precedence
  per field, with the defaults completing the map.

### Added

- `Cross-Origin-Resource-Policy` response header on every response
  (preflight, CORS, same-origin, errors), configurable via
  `CORS_RESOURCE_POLICY` (`same-site` default, `same-origin`, or
  `cross-origin`). CORP is enforced on "no-cors" subresource loads
  (images, scripts, fonts), which never send an Origin header, so it is
  applied independently of the CORS handshake. An unrecognised value
  fails loudly rather than silently falling back.
- `.env.example` now documents `DEFAULT_URI` (required by routing when
  `.env` is absent).
- `.ci/conformance.sh` + `css-control-size.py` vendored from the shared
  standards repo; the CI conformance step now runs instead of silently
  skipping.
- `#[Override]` on every method that overrides a parent or interface method
  (11 sites across `Kernel`, `CorsSubscriber`, `CaptureTransport`,
  `LoggingDispatcher`, `ToolRegistryPass`, and two test classes), so a
  renamed parent method fails static analysis instead of silently no longer
  overriding anything.

### Fixed

- CORS: `MCP-Protocol-Version` was missing from
  `Access-Control-Allow-Headers`, so browsers blocked every post-initialize
  request from spec-compliant MCP clients (llama.cpp's web UI, MCP
  Inspector) with "CORS Missing Allow Header", even though the preflight
  itself returned 204. The streamable HTTP spec requires the header on all
  requests after `initialize`; header-less clients (curl, Postman) were
  unaffected, which is why the failure only showed up in the browser.
- `docker-bake.hcl`: `DOCKERHUB_TARGET` default was misspelled
  `digitaladapt/comtext-shuttle`; corrected to
  `digitaladapt/context-shuttle`.
- `ToolLoaderTest`: `$tmpDir` was read by `tearDown()` before `setUp()` had
  assigned it; given a default so a failing `setUp()` cannot surface as an
  unrelated uninitialised-property error.
- `CaptureTransport`: dropped a `@phpstan-ignore` that no longer matched
  anything, and whose comment claimed a `return.type` error the code does not
  produce.

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