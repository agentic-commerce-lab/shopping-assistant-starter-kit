<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Validates a journey's "archetypes" map: at least one entry, each key a persona name,
 * each value a phrase or null. A journey with only literal `turns` — `cart_add` is the
 * only one today — needs no phrasing at all, hence the archetype value being nullable.
 */
final class JourneyArchetypes
{
    /**
     * @return array<string, ?string>
     */
    public static function parse(mixed $archetypes, string $journeyId): array
    {
        if (!\is_array($archetypes) || [] === $archetypes) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" must declare a non-empty "archetypes" map.',
                $journeyId,
            ));
        }

        $result = [];
        foreach ($archetypes as $name => $phrase) {
            if (!\is_string($name) || null !== $phrase && !\is_string($phrase)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Journey "%s" archetype "%s" must map to a string phrase or null.',
                    $journeyId,
                    (string) $name,
                ));
            }

            $result[$name] = $phrase;
        }

        return $result;
    }
}
