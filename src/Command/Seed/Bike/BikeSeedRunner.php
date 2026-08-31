<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Command\Seed\SeedCompletion;
use Swag\AssistantStarterKit\Command\Seed\SeedGuard;
use Swag\AssistantStarterKit\Command\Seed\SeedReport;
use Swag\AssistantStarterKit\Command\Seed\SeedWriter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Guard, read, plan, write, complete — the bike catalogue's equivalent of
 * {@see \Swag\AssistantStarterKit\Command\Seed\SeedRunner}, with one step the fashion seeder does not
 * need: **it reads the shop before it plans.**
 *
 * That difference is the whole reason this is a separate runner rather than a flag on the other one.
 * The fashion seeder builds a catalogue on a clean shop and owns every id it writes. This one attaches
 * to a shop somebody already built — its categories, its property groups, its manufacturers — so the
 * ids come out of the database and an unresolved name is an error rather than something to create.
 *
 * `SeedWriter` and `SeedCompletion` are shared unchanged: writing DAL payloads and completing a
 * catalogue are the same job whatever the payloads describe.
 */
final readonly class BikeSeedRunner
{
    public function __construct(
        private SeedGuard $guard,
        private DalShopTaxonomyReader $reader,
        private SeedWriter $writer,
        private SeedCompletion $completion,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately from the taxonomy read, exactly as
     *     {@see \Swag\AssistantStarterKit\Command\Seed\SeedRunner::run()} propagates its tax lookup:
     *     a shop this cannot read is not a shop to guess at.
     */
    public function run(
        SymfonyStyle $io,
        SalesChannelContext $salesChannelContext,
        BikeSeedMode $mode = BikeSeedMode::Seed,
    ): SeedReport {
        $context = $salesChannelContext->getContext();

        // Queried unconditionally, dry run included: one SELECT in a console command is not worth a
        // branch, and a dry run that skipped the check would report a plan the real run would refuse.
        $refusal = $mode->refusalFor($this->guard->alreadySeeded($context));

        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }

        $navigationRootId = $salesChannelContext->getSalesChannel()->getNavigationCategoryId();
        \assert(\is_string($navigationRootId), description: 'navigationCategoryId must be set on every sales channel');

        $io->writeln('Reading this shop\'s categories, property groups and manufacturers…');
        $plan = BikeSeedPlan::build($this->reader->read(), $salesChannelContext->getSalesChannelId(), mode: $mode);

        $report = self::report($plan);

        if (!$mode->writes()) {
            $io->note('Dry run — nothing was written.');

            return $report;
        }

        // Categories first, then property groups, then products: a product payload references both,
        // and the DAL resolves a referenced id at write time rather than deferring it.
        //
        // Upsert, not create: this seed attaches to a shop it did not build, so adding a value to an
        // existing property group is an update — see SeedWriter::upsertPropertyGroups() for the live
        // failure that established it, and for why it also makes a half-finished run repairable.
        $this->writer->upsertCategories($io, $plan->categories, $context);
        $this->writer->upsertPropertyGroups($io, $plan->propertyGroups, $context);
        $this->writer->upsertProducts($io, $plan->products, $context);

        // Marker last, inside completion, exactly as the fashion seeder does it: a run that dies
        // partway through must look unfinished on the next invocation rather than guarded.
        //
        // An update skips it: the shop is already marked and already indexed, and re-marking would let
        // a later partial run produce the "this finished" signal only a full first seed earns. See
        // BikeSeedMode::marks().
        if ($mode->marks()) {
            $this->completion->complete($navigationRootId, $context);
        } elseif ($mode->indexes()) {
            // An update reindexes but must not re-mark — the two used to travel together, and that is
            // how the first successful update left a stale index behind.
            $this->completion->reindex();
        }

        return $report;
    }

    private static function report(BikeSeedPlan $plan): SeedReport
    {
        $units = 0;

        foreach ($plan->products as $product) {
            $children = $product['children'] ?? [];
            $units += \is_array($children) && $children !== [] ? \count($children) : 1;
        }

        return new SeedReport(
            categoryCount: \count($plan->categories),
            propertyGroupCount: \count($plan->propertyGroups),
            productCount: \count($plan->products),
            sellableUnits: $units,
        );
    }
}
