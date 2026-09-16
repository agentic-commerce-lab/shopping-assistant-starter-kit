<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Retention;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * What a prune asked a table for, rendered so an assertion can be read at a glance.
 *
 * Its own class rather than two more methods on {@see FakeInsightRepositories}, and the gate is what
 * says so: `mago lint` sums cyclomatic complexity over a class and fails at 10 at `error` level, and
 * a renderer is a chain of `instanceof` branches. Worth knowing that the pre-commit hook lints the
 * *staged* files on their own, so a class over the threshold fails there even when a whole-project
 * run stays under its issue budget.
 */
final class CriteriaFilters
{
    /**
     * Each search as its ordered list of filters.
     *
     * Whole ordered sets rather than an index into one, for the reason
     * {@see PerSalesChannelRetentionTest} gives: an index into a `list` is not provably there to the
     * analyser, and a filter nobody intended fails here instead of going unnoticed.
     *
     * @param list<Criteria> $searches
     *
     * @return list<list<string>>
     */
    public static function describe(array $searches): array
    {
        return array_map(static fn(Criteria $criteria): array => array_map(
            self::describeFilter(...),
            array_values($criteria->getFilters()),
        ), $searches);
    }

    /**
     * Dates are rendered to the day rather than to the second, as the retention tests next to this
     * one do: the clock is the timezone's business and a retention boundary is a day.
     */
    private static function describeFilter(Filter $filter): string
    {
        if ($filter instanceof RangeFilter) {
            return $filter->getField() . ' < ' . substr((string) $filter->getParameter(RangeFilter::LT), 0, 10);
        }

        if ($filter instanceof EqualsFilter) {
            $value = $filter->getValue();

            return $filter->getField() . ($value === null ? ' IS NULL' : ' = ' . (string) $value);
        }

        if ($filter instanceof NotFilter) {
            return 'NOT (' . implode(' AND ', array_map(self::describeFilter(...), $filter->getQueries())) . ')';
        }

        return $filter::class;
    }
}
