<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Everything the plan could not resolve against the shop, collected so all of it can be reported at
 * once.
 *
 * **Collected rather than thrown on the first miss**, which is the same decision `ProductPlan::build()`
 * made and for the same reason: a seeder that stops at the first unknown category sends whoever is
 * running it around a loop of fix-one, re-run, find-the-next. Naming every miss in one error means one
 * pass over the catalogue fixes all of them.
 *
 * A small mutable object rather than a by-reference array parameter, so the plan's own methods stay
 * inside the parameter budget and so "what could not be resolved" has somewhere to describe itself.
 */
final class UnresolvedReferences
{
    /** @var list<string> */
    private array $references = [];

    public function add(string $reference): void
    {
        $this->references[] = $reference;
    }

    public function isEmpty(): bool
    {
        return $this->references === [];
    }

    public function count(): int
    {
        return \count($this->references);
    }

    /** Distinct and in the order first seen: one missing brand is one problem, not eighty-five. */
    public function describe(): string
    {
        return implode(', ', array_values(array_unique($this->references)));
    }
}
