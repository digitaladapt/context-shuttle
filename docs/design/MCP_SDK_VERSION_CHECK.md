# Checking for `mcp/sdk` updates

**Short version:** run `composer outdated mcp/sdk` monthly. The dependency is
pinned exactly, so nothing changes until someone decides it should.

## Why the pin is exact

`composer.json` pins `mcp/sdk` to `0.8.1` — no `^`, no `~`. That is deliberate
and should stay until 1.0.0 ships.

The SDK is pre-1.0, and pre-1.0 SemVer lets any digit carry a breaking change.
The project does document every one of them with a `[BC Break]` marker in its
CHANGELOG, but that only helps if the bump is a decision rather than a side
effect. An exact pin means upgrading is a reviewed commit; a caret range means
the next `composer update` picks for us.

## The monthly check

```bash
composer outdated mcp/sdk     # is there a newer release?
composer show mcp/sdk         # what are we actually resolved to?
```

Then read, in [the SDK repo](https://github.com/modelcontextprotocol/php-sdk):

1. **`CHANGELOG.md`** — scan for `[BC Break]`. The APIs we use are listed below.
2. **`ROADMAP.md`** — **has 1.0.0 shipped?** That is the trigger to relax the
   pin to `^1.0`, because it is the point at which Symfony's BC promise starts
   applying.
3. **`README.md`** — the conformance table; watch the server score for
   `2026-07-28`.
4. **`docs/design/MCP_SDK_MIGRATION.md`** → "Landmines". All four are
   *defaults*, which means any of them can change under us in a minor release.

## APIs we depend on

Grep the SDK for these before bumping — if one moved, the migration doc's
"API mapping" table is where to work out the replacement.

| Area | Symbols |
|---|---|
| Server assembly | `Server::builder()`, `setServerInfo`, `setInstructions`, `setContainer`, `setLogger`, `setEventDispatcher`, `setReferenceHandler`, `setSession`, `addTool`, `build`, `run` |
| Transport | `Server\Transport\StreamableHttpTransport` (constructor, `middleware:` argument) |
| Sessions | `Server\Session\Psr16SessionStore`, `Server\Session\SessionManager` |
| Handlers | `Capability\Registry\ReferenceHandler`, `ReferenceHandlerInterface`, `ElementReference`, `RegistryInterface` |
| Events | `Event\RequestEvent`, `Event\ResponseEvent`, `Event\ErrorEvent` |
| Schema | `Schema\Tool`, `Schema\ToolAnnotations`, `Schema\Request\CallToolRequest`, `Schema\Result\CallToolResult` |
| Exceptions | `Exception\ToolCallException` |

## After an intentional bump

1. `php bin/phpunit` — the integration suite is the real safety net here.
2. `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix --dry-run --diff`.
3. `bin/console lint:container` — catches a service-wiring change early.
4. Re-check the four landmines, since each is a default rather than a contract.
5. Update the pinned version here, in `docs/design/MCP_SDK_MIGRATION.md`, and in
   `CHANGELOG.md`.
