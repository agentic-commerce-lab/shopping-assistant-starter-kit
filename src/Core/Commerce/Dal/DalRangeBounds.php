<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * Validates the bounds of a range filter into the exact shape {@see RangeFilter} accepts.
 *
 * Split out of {@see DalFilterTranslator} (cyclomatic-complexity) rather than suppressed: field
 * translation and value validation are two concerns, and this is the one with the rules.
 *
 * Both checks fail loudly on purpose.
 *
 * - An **empty** range matches everything, so silently accepting one turns "under 40 euros" into
 *   "anything". A price ceiling that quietly stops applying is the same class of defect as a
 *   blocklist that quietly stops blocking, and this project treats that class as the worst one.
 * - An **unknown** operator key would be handed to the DAL, which either ignores it or errors far
 *   from its cause. Rejecting it here names the caller.
 */
final readonly class DalRangeBounds
{
    /** The only keys `RangeFilter` understands; see that class's own constants. */
    private const OPERATORS = [RangeFilter::GT, RangeFilter::GTE, RangeFilter::LT, RangeFilter::LTE];

    /**
     * @return array<'gt'|'gte'|'lt'|'lte', float|int|null|string>
     */
    public function of(mixed $value): array
    {
        if (!\is_array($value) || $value === []) {
            throw new \InvalidArgumentException('A range filter needs a non-empty array of range operators.');
        }

        $range = [];

        foreach ($value as $operator => $bound) {
            $key = $this->operator($operator);
            $range[$key] = $this->bound($key, $bound);
        }

        return $range;
    }

    /**
     * @return 'gt'|'gte'|'lt'|'lte'
     */
    private function operator(mixed $operator): string
    {
        $key = \is_string($operator) ? $operator : (string) (\is_scalar($operator) ? $operator : '');

        if (!\in_array($key, self::OPERATORS, strict: true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown range operator "%s". A range filter accepts only %s.',
                $key,
                implode(', ', self::OPERATORS),
            ));
        }

        /** @var 'gt'|'gte'|'lt'|'lte' $key */
        return $key;
    }

    private function bound(string $key, mixed $bound): float|int|string|null
    {
        if ($bound === null) {
            return null;
        }

        if (\is_bool($bound)) {
            // A boolean bound is meaningless for a range and would silently become 0 or 1.
            throw new \InvalidArgumentException(\sprintf('Range bound "%s" must be numeric or a string.', $key));
        }

        if (!\is_scalar($bound)) {
            throw new \InvalidArgumentException(\sprintf('Range bound "%s" must be scalar or null.', $key));
        }

        return $bound;
    }
}
