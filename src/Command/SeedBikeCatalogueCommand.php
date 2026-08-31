<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedMode;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills out this shop's own bicycle catalogue — ~85 products, ~193 sellable units, 14 new
 * subcategories — attaching to the categories, property groups and brands it already has.
 *
 * **Not {@see SeedFashionCatalogueCommand}, and the difference is the point.** That one generates a
 * large catalogue from a taxonomy and adjective lists to measure retrieval at scale, on a shop it
 * assumes is empty. This one is for a shop somebody browses: every product is one that could
 * plausibly be sold here, and every id it does not create is read out of the shop first. Running the
 * fashion seeder against the demo shop would put "Bodycon Bag 3316" next to a bottle cage.
 *
 * **`--dry-run` first.** Unlike the fashion seeder this writes into a shop with existing content, so
 * the plan is worth reading before it runs: a dry run resolves everything against the live shop and
 * reports the counts without writing. A resolution failure — a renamed category, a deleted brand —
 * surfaces there rather than mid-write.
 *
 * **Dev-only**, same as its sibling: `SeedGuard` carries a bike-specific marker and a first seed
 * refuses a shop that already has it. There is still no `--force` — recovering from a doubled
 * catalogue is a database restore either way — but `--update` re-enters a marked shop to upsert the
 * catalogue, which cannot double anything because every id is derived from the product number. See
 * {@see BikeSeedMode} for where that stops being true.
 */
#[AsCommand(
    name: 'swag:assistant:seed-bike-catalogue',
    description: 'Fill out this shop\'s bicycle catalogue through the DAL. Dev-only; cannot be run twice.',
)]
final class SeedBikeCatalogueCommand extends Command
{
    public function __construct(
        private readonly BikeSeedRunner $runner,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'sales-channel',
            null,
            InputOption::VALUE_REQUIRED,
            'Sales channel whose navigation tree the categories attach under, and which the products are visible in.',
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Resolve the plan against this shop and report what would be written, without writing it.',
        );

        $this->addOption(
            'update',
            null,
            InputOption::VALUE_NONE,
            'Upsert the catalogue into a shop that already carries the marker, correcting or extending '
            . 'the products already there. Not a --force: every id is derived from the product number, '
            . 'so unchanged numbers update in place. Renumbering a product still doubles it.',
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately from {@see BikeSeedRunner::run()}'s
     *     taxonomy read, the same "uncaught, by design" treatment {@see SeedFashionCatalogueCommand}
     *     gives its own tax lookup: a connection failure is not a payload to guess at, and Symfony's
     *     console runner already reports an uncaught exception clearly.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $salesChannelId = $input->getOption('sales-channel');

        if (!\is_string($salesChannelId) || $salesChannelId === '') {
            // No default: this command attaches to an existing shop's navigation, and guessing which
            // sales channel that is would seed products into a storefront nobody is looking at.
            $io->error('A --sales-channel is required. Run "bin/console sales-channel:list" to find it.');

            return self::FAILURE;
        }

        $context = $this->contextFactory->create(Uuid::randomHex(), $salesChannelId);

        $mode = BikeSeedMode::of(
            dryRun: (bool) $input->getOption('dry-run'),
            update: (bool) $input->getOption('update'),
        );

        try {
            $report = $this->runner->run($io, $context, $mode);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf(
            '%s %d categories, %d property group changes, %d products (%d sellable units).',
            self::verb($mode),
            $report->categoryCount,
            $report->propertyGroupCount,
            $report->productCount,
            $report->sellableUnits,
        ));

        return self::SUCCESS;
    }

    /**
     * What the run actually did, so the success line does not report a first seed after an update.
     */
    private static function verb(BikeSeedMode $mode): string
    {
        return match ($mode) {
            BikeSeedMode::DryRun => 'Would seed',
            BikeSeedMode::Update => 'Updated',
            BikeSeedMode::Seed => 'Seeded',
        };
    }
}
