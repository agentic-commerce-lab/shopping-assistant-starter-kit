<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Small, generic "pull this required field out of a journey's raw array, or throw"
 * helpers, shared by {@see JourneyFileParser}. Split into its own class purely to keep
 * every one of these classes' own cyclomatic-complexity total (mago sums it per class,
 * across every method) under this project's threshold — none of these methods is
 * complex on its own; there are just too many of them to share one class with the
 * per-field validators in {@see JourneyArchetypes}, {@see JourneyTurns} and
 * {@see JourneyAssertions}.
 */
final class JourneyField
{
    /** @param array<string, mixed> $data */
    public static function requireNonEmptyString(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey file "%s" must declare a non-empty string "%s".',
                $path,
                $key,
            ));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function requirePositiveInt(array $data, string $key, string $path): int
    {
        $value = $data[$key] ?? null;

        if (!\is_int($value) || $value < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey file "%s" must declare a positive int "%s".',
                $path,
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>
     */
    public static function requireArrayOrDefault(array $data, string $key, string $journeyId, array $default): array
    {
        $value = $data[$key] ?? $default;

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf('Journey "%s" key "%s" must be an array.', $journeyId, $key));
        }

        /** @var array<string, mixed> $result */
        $result = $value;

        return $result;
    }
}
