<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * Scalar bounds that #[AsTool] cannot express. The schema is derived from the
 * method signature by reflection, so maxLength/maxItems/min-max have to be
 * enforced here. Reject, never coerce — a coerced argument is a silent
 * injection success.
 *
 * {@see VariantSelectionGuard} holds the composite "list of option selections"
 * bound separately: keeping it here pushed this class's aggregate cyclomatic
 * complexity over the project's own threshold (mago's `cyclomatic-complexity`
 * rule, class-scoped, sums every method's own complexity), so it was split
 * out rather than suppressed.
 */
final class Guard
{
    public static function boundedString(?string $value, int $max, string $name): ?string
    {
        if ($value !== null && mb_strlen($value) > $max) {
            throw new ToolArgumentException(sprintf('Argument "%s" exceeds %d characters.', $name, $max));
        }

        return $value;
    }

    /** @param array<int, mixed>|null $value */
    public static function boundedArray(?array $value, int $max, string $name): ?array
    {
        if ($value !== null && \count($value) > $max) {
            throw new ToolArgumentException(sprintf('Argument "%s" accepts at most %d entries.', $name, $max));
        }

        return $value;
    }

    public static function boundedInt(int $value, int $min, int $max, string $name): int
    {
        if ($value < $min || $value > $max) {
            throw new ToolArgumentException(sprintf('Argument "%s" must be between %d and %d.', $name, $min, $max));
        }

        return $value;
    }
}
