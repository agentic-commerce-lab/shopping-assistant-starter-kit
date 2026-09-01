<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

/**
 * The bounds of a `price` range filter, and whether a given price satisfies them.
 *
 * Split out of {@see StatedBudget} (cyclomatic-complexity) rather than suppressed: finding the
 * query's price clause and deciding whether one number is inside it are two concerns, and this is
 * the one with the arithmetic. The same split {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalRangeBounds}
 * made for the DAL side of the identical shape.
 *
 * The operator names are Shopware's own `RangeFilter` keys, because the range enforced here has to be
 * the range that was sent to the database — see {@see StatedBudget::keep()}.
 */
final readonly class PriceRange
{
    /**
     * @param array<array-key, mixed> $bounds
     */
    private function __construct(
        private array $bounds,
    ) {}

    /**
     * @param array<array-key, mixed> $bounds
     */
    public static function of(array $bounds): self
    {
        return new self($bounds);
    }

    /**
     * @param bool $unreadable the price could not be read at all, rather than genuinely being 0.00
     */
    public function contains(float $price, bool $unreadable): bool
    {
        foreach ($this->bounds as $operator => $bound) {
            // A non-numeric bound cannot constrain anything. DalRangeBounds throws on these for the
            // SQL side; here a bound that never reached the database must not narrow what came back
            // from it either, so it is skipped rather than rejected.
            if (!\is_numeric($bound)) {
                continue;
            }

            if (!self::satisfies($price, $unreadable, (string) $operator, (float) $bound)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A floor is waived for an unreadable price; a ceiling never needs waiving, because the 0.00 such
     * a price reports satisfies every ceiling already. See {@see StatedBudget} for why an unreadable
     * price keeps its product rather than losing it.
     */
    private static function satisfies(float $price, bool $unreadable, string $operator, float $bound): bool
    {
        return match ($operator) {
            'lte' => $price <= $bound,
            'lt' => $price < $bound,
            'gte' => $unreadable || $price >= $bound,
            'gt' => $unreadable || $price > $bound,
            default => true,
        };
    }
}
