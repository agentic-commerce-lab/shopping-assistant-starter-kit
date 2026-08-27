<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Parses one journey file's raw `require`d array into the validated fields
 * {@see Journey::fromFile()} needs, delegating each field's own shape check to a small,
 * focused validator so no single class here approaches this project's
 * cyclomatic-complexity budget.
 *
 * @phpstan-type ParsedJourney array{id: string, category: string, runs: int, archetypes: array<string, ?string>, config: array<string, mixed>, turns: list<string>, assertions: array<string, array{assertion: Assertion, expectations: array<string, mixed>}>, page: JourneyPage, catalogue: JourneyCatalogue}
 */
final class JourneyFileParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: string, category: string, runs: int, archetypes: array<string, ?string>, config: array<string, mixed>, turns: list<string>, assertions: array<string, array{assertion: Assertion, expectations: array<string, mixed>}>, page: JourneyPage, catalogue: JourneyCatalogue}
     */
    public static function parse(array $data, string $path): array
    {
        $id = JourneyField::requireNonEmptyString($data, 'id', $path);

        return [
            'id' => $id,
            'category' => JourneyField::requireNonEmptyString($data, 'category', $path),
            'runs' => JourneyField::requirePositiveInt($data, 'runs', $path),
            'archetypes' => JourneyArchetypes::parse($data['archetypes'] ?? null, $id),
            'config' => JourneyField::requireArrayOrDefault($data, 'config', $id, []),
            'turns' => JourneyTurns::parse($data['turns'] ?? null, $id),
            'assertions' => JourneyAssertions::parse($data['assertions'] ?? null, $id),
            'page' => JourneyPage::parse($data['page'] ?? null, $id),
            // The journey-file key is `catalog`, matching ASSISTANT_EVAL_CATALOG's own spelling: a
            // journey file is read beside that variable. The parsed field is `catalogue`, matching the
            // rest of this namespace.
            'catalogue' => JourneyCatalogue::parse($data['catalog'] ?? null, $id),
        ];
    }
}
