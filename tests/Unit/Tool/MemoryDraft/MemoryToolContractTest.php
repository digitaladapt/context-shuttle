<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\MemoryDraft;

use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolLoader;
use PHPUnit\Framework\TestCase;

/**
 * The memory tools' *prose contract* — what a model is told before it calls
 * anything.
 *
 * For a tool family whose whole audience is a language model, the description
 * is part of the interface: it is the only thing a small model reads before
 * deciding which arguments to pass. A description that is merely out of date
 * is therefore a bug with a usage cost, not a doc nit, so the claims a caller
 * depends on are asserted rather than trusted.
 *
 * The claims here are the ones that were actually wrong or missing:
 *
 *  - the recalled `depth` default was documented as `2` while the service's
 *    default had moved on, which is exactly the kind of drift that makes a
 *    caller pass an argument it should not have to;
 *  - nothing said that pinned sentences come back regardless of `depth`, so
 *    "I asked for a keyword and got two of its nine facts" looked like the
 *    caller's fault;
 *  - nothing said which keys hold pinned facts, so the only way to find out
 *    was to recall one and guess from its output.
 *
 * @internal
 *
 * @coversNothing
 */
final class MemoryToolContractTest extends TestCase
{
    /**
     * @return array<string, ToolDefinition>
     */
    private function memoryTools(): array
    {
        $tools = [];
        foreach ((new ToolLoader(__DIR__.'/../../../../config/tools'))->load() as $definition) {
            if (str_starts_with($definition->name, 'memory_')) {
                $tools[$definition->name] = $definition;
            }
        }

        return $tools;
    }

    public function test_recall_does_not_document_a_stale_depth_default(): void
    {
        // A hard-coded "(default: 2)" survived the service changing its own
        // default. The tool must not restate a number the store owns, because
        // the two then disagree and the caller trusts the one it can see.
        $depth = $this->memoryTools()['memory_recall']->parameters['depth']['description'] ?? '';

        self::assertDoesNotMatchRegularExpression(
            '/default:\s*\d+/',
            $depth,
            'memory_recall must not hard-code a default depth; the store owns that value',
        );
    }

    public function test_recall_says_pinned_sentences_are_always_returned(): void
    {
        // The failure this documents: a keyword lookup returning two sentences
        // of nine, with nothing saying the other seven were being withheld.
        $description = $this->memoryTools()['memory_recall']->description;

        self::assertStringContainsString('Pinned', $description);
        self::assertStringContainsString('always returned', $description);
    }

    public function test_keys_says_it_reports_which_keys_are_pinned(): void
    {
        // Discovery has to be able to answer "which keys have durable facts?"
        // without recalling each one in turn.
        $description = $this->memoryTools()['memory_keys']->description;

        self::assertStringContainsString('pinned', $description);
    }

    public function test_remember_explains_pinning_as_the_way_to_protect_a_fact(): void
    {
        $pin = $this->memoryTools()['memory_remember']->parameters['pin']['description'] ?? '';

        self::assertStringContainsString('trimming', $pin, 'pin must say what it protects against');
    }

    public function test_remember_says_omitting_pin_leaves_it_alone(): void
    {
        // The distinction a small model will otherwise get wrong by default:
        // it re-states a fact, has no opinion about `pin`, and — reading the
        // old text, which mentioned only what `true` does — either passes
        // `false` or assumes silence is an un-pin. Both silently strip the
        // pin. The description has to say that omitting means "leave it".
        $pin = $this->memoryTools()['memory_remember']->parameters['pin']['description'] ?? '';

        self::assertStringContainsString('un-pin', $pin, 'pin must distinguish explicit false from omission');
    }
}
