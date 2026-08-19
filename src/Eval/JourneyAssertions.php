<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Eval\Assertion\AssertionRegistry;

/**
 * Validates a journey's "assertions" map and resolves each name through
 * {@see AssertionRegistry}, pairing every resolved {@see Assertion} with its own
 * expectations block from the journey file.
 */
final class JourneyAssertions
{
    /**
     * @return array<string, array{assertion: Assertion, expectations: array<string, mixed>}>
     */
    public static function parse(mixed $raw, string $journeyId): array
    {
        if (!\is_array($raw) || [] === $raw) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" must declare at least one assertion.',
                $journeyId,
            ));
        }

        $assertions = [];
        foreach ($raw as $name => $expectations) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Journey "%s" has a non-string assertion key.',
                    $journeyId,
                ));
            }

            if (!\is_array($expectations)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Journey "%s" assertion "%s" must map to an array.',
                    $journeyId,
                    $name,
                ));
            }

            /** @var array<string, mixed> $expectationsMap */
            $expectationsMap = $expectations;

            $assertions[$name] = [
                'assertion' => AssertionRegistry::resolve($name, $journeyId),
                'expectations' => $expectationsMap,
            ];
        }

        return $assertions;
    }
}
