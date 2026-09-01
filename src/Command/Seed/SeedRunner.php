<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates the seed: guard check, tax lookup, the three plan builders (Tasks 4–6), the three writes
 * ({@see SeedWriter}), then synchronous catalogue completion ({@see SeedCompletion}). The completion
 * writes the marker last, so a run that fails partway through is visibly unfinished on the next
 * invocation rather than silently guarded.
 *
 * A thin orchestrator, deliberately: it holds no `EntityRepository` itself — those live on
 * {@see SeedWriter}, which owns every DAL write — and keeps only the `Connection` its own tax lookup
 * reads.
 */
final readonly class SeedRunner
{
    public function __construct(
        private SeedGuard $guard,
        private SeedWriter $writer,
        private SeedCompletion $completion,
        private Connection $connection,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in the migration and ShopInfo
     *     Doctrine call sites in this codebase: a tax lookup that cannot run must fail loudly rather
     *     than seed unpriced products.
     */
    public function run(SymfonyStyle $io, SalesChannelContext $salesChannelContext): SeedReport
    {
        $context = $salesChannelContext->getContext();

        if ($this->guard->alreadySeeded($context)) {
            throw new \RuntimeException(
                'This shop already carries the fashion seed marker — refusing to seed twice. '
                . 'Recovery is a database restore, not a re-run (see HANDOFF.md, Task A).',
            );
        }

        // ORDER BY makes which tax rate the whole catalogue gets deterministic, matching SeedId's and
        // ProductFillerBuilder's LCG determinism elsewhere in this feature.
        //
        // The rate comes back with the id because the payloads need both: the seeded figure is a gross
        // price, and `SizeFamily::grossPrice()` derives `net` from it. Reading only the id is what let
        // this catalogue store net == gross — see that method.
        $tax = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, tax_rate AS rate FROM tax ORDER BY tax_rate DESC, id LIMIT 1',
        );
        $taxId = \is_array($tax) ? $tax['id'] ?? null : null;
        if (!\is_string($taxId) || $taxId === '') {
            throw new \RuntimeException('No tax rule exists in this shop — cannot price seeded products.');
        }

        \assert(\is_array($tax));
        $seedTax = new SeedTax($taxId, (float) $tax['rate']);

        $navigationRootId = $salesChannelContext->getSalesChannel()->getNavigationCategoryId();
        \assert(\is_string($navigationRootId), description: 'navigationCategoryId must be set on every sales channel');

        $categoryPlan = CategoryTreePlan::build($navigationRootId);
        $propertyPlan = PropertyGroupPlan::build();
        $products = ProductPlan::build(
            $categoryPlan['idsByPath'],
            $propertyPlan['optionIds'],
            $propertyPlan['sizeOptionIds'],
            $seedTax,
            $salesChannelContext->getSalesChannelId(),
        );

        $this->writer->writeCategories($io, $categoryPlan['tree'], $context);
        $this->writer->writePropertyGroups($io, $propertyPlan['groups'], $context);
        $this->writer->writeProducts($io, $products, $context);

        $this->completion->complete($navigationRootId, $context);

        $sellableUnits = 0;
        foreach ($products as $product) {
            $children = $product['children'] ?? [];
            $sellableUnits += \is_array($children) && $children !== [] ? \count($children) : 1;
        }

        return new SeedReport(
            categoryCount: \count($categoryPlan['idsByPath']),
            propertyGroupCount: \count($propertyPlan['groups']),
            productCount: \count($products),
            sellableUnits: $sellableUnits,
        );
    }
}
