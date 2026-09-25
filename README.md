# context-shuttle — API gateway (api-gateway profile, Guiding Light §0)

**A YAML-driven MCP + REST tool gateway.** Tools are defined as YAML files in
`config/tools/`; the server exposes each tool simultaneously as an
MCP tool (Model Context Protocol, streamable HTTP at `/mcp`) and as a REST
endpoint (`POST /tools/{name}`), with a generated OpenAPI 3.1 spec at
`/openapi.json`. Both entry points share one execution pipeline and one
structured invocation log.

Built with Symfony 8.1 / PHP 8.5 on `php-mcp/server`.

---

## Quick start

```bash
composer install
symfony server:start        # or: php -S 127.0.0.1:8000 -t public/
```

Verify:

```bash
curl http://127.0.0.1:8000/health   # {"status":"ok"}
curl http://127.0.0.1:8000/ready    # {"status":"ready","tools":1}
curl http://127.0.0.1:8000/tools    # tool inventory
```

## Defining a tool

Create `config/tools/<name>.yaml`:

```yaml
name: get_weather
description: Get current weather and a forecast for any location.
handler: App\Tool\Weather\WeatherTool::getWeather
parameters:
  location:
    type: string
    description: 'Location as "lat,lon" or a place name.'
    required: true
  forecast_days:
    type: integer
    description: Number of forecast days (1-7).
    required: false
    default: 3
    minimum: 1
    maximum: 7
```

Then implement the handler as a public method on a service:

```php
final class WeatherTool
{
    public function getWeather(string $location, ?int $forecast_days = null, ?string $units = null): array
    {
        // ...
    }
}
```

Register the service publicly (see `config/services.yaml`) and drop the YAML
file into `config/tools/`. That's it: the tool is now callable via MCP
(`tools/call get_weather`) and REST (`POST /tools/get_weather`), appears in
`GET /tools` and `/openapi.json`, and every invocation is logged.

### Tool YAML reference

| Key | Required | Notes |
|---|---|---|
| `name` | ✅ | lowercase snake_case, unique, ≤64 chars |
| `description` | ✅ | non-empty; shown to LLMs and in OpenAPI |
| `handler` | ✅ | `FQCN::method` or invokable `FQCN` |
| `parameters` | – | mapping of parameter definitions |
| `parameters.<name>.type` | ✅ | `string` / `integer` / `number` / `boolean` / `array` |
| `parameters.<name>.description` | – | |
| `parameters.<name>.required` | – | default `false` |
| `parameters.<name>.default` | – | applied when omitted |
| `parameters.<name>.enum` | – | list of allowed scalar values |
| `parameters.<name>.items` | – | for `array` types: `{type: ...}` |
| `parameters.<name>.format` / `pattern` / `minimum` / `maximum` | – | JSON Schema pass-through |

Invalid YAML fails **at boot** (container compile), never at request time.

## Calling tools

**MCP** (streamable HTTP, JSON response mode — POST JSON-RPC):

```bash
curl -X POST http://127.0.0.1:8000/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{
        "protocolVersion":"2025-03-26","capabilities":{},
        "clientInfo":{"name":"demo","version":"1.0"}}}'

curl -X POST http://127.0.0.1:8000/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{
        "name":"get_weather","arguments":{"location":"Berlin"}}}'
```

**REST**:

```bash
curl -X POST http://127.0.0.1:8000/tools/get_weather \
  -H 'Content-Type: application/json' \
  -d '{"location":"Berlin","forecast_days":2,"units":"imperial"}'
```

The server is **stateless per request** for MCP: every POST creates a session,
marks it initialized, processes the message, and discards it. No `Mcp-Session-Id`
is required. This is spec-compliant for stateless servers and the simplest
deployment model; a persistent-session mode is a roadmap item (see
`docs/design/ROADMAP.md`).

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/mcp` | JSON-RPC over HTTP (initialize, tools/list, tools/call, ping) |
| GET | `/tools` | Tool inventory with schemas |
| POST | `/tools/{name}` | Invoke a tool (same pipeline as MCP) |
| GET | `/openapi.json` | Generated OpenAPI 3.1 spec |
| GET | `/health` | Liveness (no dependencies) |
| GET | `/ready` | Readiness (tool registry loaded) |

## Observability

Every tool invocation — via MCP or REST — emits **one JSON log line** on the
`mcp_invocation` channel (stdout):

```json
{"message":"Tool invoked.","context":{"request_id":"...","tool":"get_weather",
 "arguments":{...},"duration_ms":519,"result":{"content_preview":"…"},
 "is_error":false},"channel":"mcp_invocation",...}
```

Failures are logged at `error` level with `error` / `error_class` context.

## Configuration

All configuration via environment variables — see `.env.example`. Key vars:

| Variable | Default | Purpose |
|---|---|---|
| `MCP_SERVER_NAME` | `context-shuttle` | MCP serverInfo name |
| `MCP_SERVER_VERSION` | `1.0.0` | MCP serverInfo version |
| `CORS_RESOURCE_POLICY` | `same-site` | `Cross-Origin-Resource-Policy` header: `same-site`, `same-origin`, or `cross-origin` |
| `PENNYTRACK_URL` / `PENNYTRACK_API_KEY` | empty | Enable the `get_transactions` tool |
| `VITALPULSE_URL` / `VITALPULSE_API_KEY` | empty | Enable the `get_health_logs` tool |
| `NTFY_TOPIC` | empty | Enable the ntfy channel of `send_alert` |
| `NTFY_URL` / `NTFY_TOKEN` | `https://ntfy.sh` / empty | Self-hosted ntfy server and access token |
| `DISCORD_WEBHOOK_URL` | empty | Enable the Discord channel of `send_alert` |
| `DISCORD_MENTION_USER_ID` | empty | User mentioned by priority-5 alerts only |
| `IMAP_HOST` / `IMAP_USERNAME` / `IMAP_PASSWORD` | empty | Enable the email tools. Use an app password for Gmail |
| `IMAP_PORT` / `IMAP_ENCRYPTION` | derived / `ssl` | Port defaults to 993 for `ssl`, 143 otherwise |
| `IMAP_READ_FOLDERS` | empty | Folders `list_emails` and `read_email` may name |
| `IMAP_TAG_FOLDERS` / `IMAP_MARK_FOLDERS` | empty | Folders `tag_email` / `mark_email_*` may change |
| `IMAP_MOVE_SOURCE_FOLDERS` / `IMAP_MOVE_TARGET_FOLDERS` | empty | Folders `move_email` may take from / move into |
| `IMAP_TRASH_FOLDER` / `IMAP_ARCHIVE_FOLDER` | empty | What `move_email`'s `trash` / `archive` mean |
| `IMAP_DELETE_FOLDER` | empty | Alias for `IMAP_TRASH_FOLDER`; wins when both are set |
| `CALDAV_URL` / `CALDAV_USERNAME` / `CALDAV_PASSWORD` | empty | Enable the `calendar_list_events` and `calendar_get_event` tools |
| `CALDAV_CALENDARS` | empty | Optional comma-separated calendars to expose; empty means all discovered |
| `TZ` | `UTC` | The timezone every calendar timestamp is rendered in, and date inputs are read in |

Alert channels are enabled by presence: set `NTFY_TOPIC`, `DISCORD_WEBHOOK_URL`,
or both — every enabled channel receives every alert, and `send_alert` reports
delivery per channel.

### Email access

The email tools are **gated by folder, per operation**: reading a folder does
not grant tagging it, marking it read, or moving from it, and every write-ish
capability is off until an operator names folders in the relevant list. An
operation on a folder outside its list is refused with a message naming the
variable to set. `move_email` is the only tool that removes a message from a
folder, and it **moves** — there is no delete anywhere, because expunging a
mailbox would also destroy messages other clients had flagged.

### Calendar access

Read-only access to a CalDAV server, for events. Basic auth only, so an app
password is usually what you want; `CALDAV_PASSWORD` may live in the secrets
vault (`bin/console secrets:set CALDAV_PASSWORD`) instead of `.env.local`.
`CALDAV_URL` is not checked at boot — a calendar server being down must never
stop context-shuttle from starting — so a misconfiguration surfaces on first
use, with a message naming the variable.

**Google Calendar is not supported.** Google's CalDAV endpoint no longer
accepts Basic auth, and OAuth is not implemented, so pointing `CALDAV_URL`
at Google will fail at authentication rather than half-work. Radicale,
Nextcloud, Baïkal, Fastmail and Apple app passwords all work.

Timestamps come back already expressed in `TZ`, so a caller never has to
reason about offsets or daylight-saving changes, and `from`/`to` are read in
the same zone. Recurring events are expanded into one entry per occurrence,
each with its own `id`; use that `id` — not the shared `uid` — to fetch one
occurrence back with `calendar_get_event`.

`CALDAV_CALENDARS` narrows which calendars are exposed at all, which matters
on a real account that also sees subscribed holidays and shared team
calendars. Entries may be full hrefs (`/user/work/`) or bare names (`work`);
an entry that matches nothing is logged with the calendars the server did
offer, so a typo shows up as a warning rather than as an empty listing.

## Development

```bash
composer lint   # php-cs-fixer dry-run
composer cs-fix # php-cs-fixer fix
composer stan   # phpstan
composer test   # phpunit
```

> **Dependency note:** `php-mcp/server` is currently pinned to a commit on the
> `feat/symfony-8-support` branch of the project fork at
> `code.digitaladapt.com/public/php-mcp-server` (12 commits ahead of the 3.3.0
> release; the release does not yet allow symfony/finder 8.x). When upstream
> ships a tag allowing Symfony 8, replace the pinned constraint with that tag.

## License

MIT — see `LICENSE`.
