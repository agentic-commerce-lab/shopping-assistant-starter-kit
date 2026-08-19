<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Validates a journey's "turns" list, defaulting to a single `'archetype'` turn when
 * the key is omitted entirely — the shape every grounding journey but `cart_add` uses.
 */
final class JourneyTurns
{
    /**
     * @return list<string>
     */
    public static function parse(mixed $turns, string $journeyId): array
    {
        $turns ??= ['archetype'];

        if (!\is_array($turns) || [] === $turns) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" must declare a non-empty "turns" list.',
                $journeyId,
            ));
        }

        $result = [];
        foreach ($turns as $turn) {
            if (!\is_string($turn) || '' === $turn) {
                throw new \InvalidArgumentException(\sprintf(
                    'Journey "%s" has a non-string or empty turn.',
                    $journeyId,
                ));
            }

            $result[] = $turn;
        }

        return $result;
    }
}
