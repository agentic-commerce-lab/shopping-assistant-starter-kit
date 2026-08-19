<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * One shopper scenario, loaded from a plain PHP file under `tests/Journeys/` that
 * returns an array — no YAML dependency, and the shape is checked by hand, via
 * {@see JourneyFileParser}, since there is no schema library in this project's
 * dependency set.
 *
 * Archetype phrasings are frozen fixtures: {@see self::fromFile()} loads them verbatim
 * from the committed file, never generating or rephrasing them, so a journey's input is
 * the same on every run and a diff against a baseline means something.
 */
final readonly class Journey
{
    // @mago-expect lint:excessive-parameter-list
    // A frozen, plan-dictated value object: one field per journey-file concept (identity,
    // run count, per-archetype phrasing, config, turns, resolved assertions). The only
    // call site, self::fromFile() below, uses named arguments.
    public function __construct(
        public string $id,
        public string $category,
        public int $runs,
        /** @var array<string, ?string> archetype name => its phrasing, or null when this journey uses only literal turns */
        public array $archetypes,
        /** @var array<string, mixed> maps onto {@see \Swag\AssistantStarterKit\Core\Policy\AssistantConfig} */
        public array $config,
        /** @var list<string> the literal `'archetype'` substitutes the current archetype's phrasing; anything else is sent verbatim */
        public array $turns,
        /** @var array<string, array{assertion: Assertion, expectations: array<string, mixed>}> */
        public array $assertions,
    ) {}

    public static function fromFile(string $path): self
    {
        $data = require $path;

        if (!\is_array($data)) {
            throw new \InvalidArgumentException(\sprintf('Journey file "%s" must return an array.', $path));
        }

        /** @var array<string, mixed> $fileData */
        $fileData = $data;

        $fields = JourneyFileParser::parse($fileData, $path);

        return new self(...$fields);
    }
}
