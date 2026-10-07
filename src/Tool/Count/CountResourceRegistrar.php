<?php

declare(strict_types=1);

namespace App\Tool\Count;

use Mcp\Server\Builder;

/**
 * Declares `count://stream` on the server builder.
 *
 * Tools are declared from YAML, but resources are not: the registry's YAML is a
 * tool contract (name, parameters, JSON Schema), and a resource has a different
 * shape — a URI, a MIME type, no arguments. Rather than stretch the YAML to
 * cover both, the one resource this project has is declared in code, next to the
 * class that serves it.
 *
 * The handler is `[class, method]`, not a container-free string, because that
 * is the form the SDK resolves through PSR-11 — the same reason every tool
 * handler is registered that way (see `YamlToolRegistrar::handlerFor()`).
 * Without it the resource would be constructed with `new` and lose its
 * collaborators.
 *
 * Registered unconditionally, alongside every other YAML tool: `count` has no
 * `requires`, so there is no deployment that has the tool but not the resource.
 * The pair is a test fixture and says so in its own description.
 */
final class CountResourceRegistrar
{
    public function declareOn(Builder $builder): void
    {
        $builder->addResource(
            handler: [CountResource::class, 'read'],
            uri: CountResource::URI,
            name: 'count_stream',
            title: 'Count stream',
            description: 'The lines the current `count` run has emitted so far, '
                .'one per line, with the emitted count, the remaining count and '
                .'the total in its metadata. Changed each second while `count` '
                .'runs; subscribe and re-read it to watch a run in progress.',
            mimeType: CountResource::MIME_TYPE,
        );
    }
}
