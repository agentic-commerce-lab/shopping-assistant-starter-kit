<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * The query narrowing {@see ConversationScopeTest}'s trace-event repository double needs.
 *
 * Deliberately not a general-purpose filter/sort engine: `DalConversationStore` only ever issues two
 * shapes of query against this table (`traceEvents()`: an equals filter on `conversationId`, sorted
 * ascending by `seq`; `nextSeq()`: the same filter, sorted descending, limited to 1), so this reads
 * the filter and the sorting at a fixed position rather than scanning for them — scanning would
 * imply a generality this double does not need and does not claim.
 *
 * Split out of {@see FakeRepositoryRows} rather than folded in with it: the two together tripped
 * mago's class-scoped `cyclomatic-complexity` budget, and this half is the one with the branching —
 * merging them back would only have moved the same total complexity into a single file, not reduced
 * it.
 */
final class FakeTraceEventSearch
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function run(array $rows, Criteria $criteria, Context $context): EntitySearchResult
    {
        $filter = $criteria->getFilters()[0] ?? null;
        $conversationId = $filter instanceof EqualsFilter ? (string) $filter->getValue() : null;

        $matches = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['conversationId'] === $conversationId,
        ));

        $sorting = $criteria->getSorting()[0] ?? null;
        if ($sorting instanceof FieldSorting) {
            $descending = $sorting->getDirection() === FieldSorting::DESCENDING;
            usort($matches, static fn(array $a, array $b): int => $descending
                ? $b['seq'] <=> $a['seq']
                : $a['seq'] <=> $b['seq']);
        }

        $limit = $criteria->getLimit();
        $matches = $limit === null ? $matches : \array_slice($matches, 0, $limit);

        $entities = array_map(FakeRepositoryRows::toTraceEventEntity(...), $matches);

        return new EntitySearchResult(
            'swag_assistant_trace_event',
            \count($entities),
            new EntityCollection($entities),
            null,
            $criteria,
            $context,
        );
    }
}
