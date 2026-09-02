<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Swag\AssistantStarterKit\Command\Seed\SeedRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the fashion taxonomy — ~15,200 sellable units across ~1,031 category nodes — into this shop
 * through the DAL. HANDOFF.md, Task A: every fashion-scale number measured so far is a
 * `FixtureCommerceGateway` number; this is what lets the same evals run against real Shopware search.
 *
 * **Dev-only, and deliberately impossible to run twice** — see `Command\Seed\SeedGuard`.
 * There is no `--force`: recovering from a mistaken second run is a database restore either way.
 *
 * **"Dev-only" and the `APP_ENV=prod` warning below are about different things**, and reading them as
 * a contradiction is a mistake this docblock has already caused once. *Dev-only* is about which shop:
 * a development or test shop, never a merchant's live one — nothing enforces it, `SeedGuard` only
 * stops a second run. `APP_ENV=prod` is about the environment variable that shop runs under, because
 * dev mode inflates every measurement and makes writing 15,200 sellable units slower. A test shop
 * with `APP_ENV=prod` is exactly the intended combination.
 *
 * Same context-scoping pattern as {@see ProbeCommand} and {@see BenchmarkCommand} — a console command
 * has no HTTP request, so `SalesChannelContextProvider::current()` would throw; the context is built
 * here instead. Unlike those two, this command does not need `SalesChannelContextProvider::use()`,
 * because {@see SeedRunner} takes the `SalesChannelContext` directly rather than going through
 * `CommerceGatewayInterface`.
 */
#[AsCommand(
    name: 'swag:assistant:seed-fashion-catalogue',
    description: 'Seed the fashion taxonomy into this shop through the DAL. Dev-only; run it with APP_ENV=prod; cannot be run twice.',
)]
final class SeedFashionCatalogueCommand extends Command
{
    public function __construct(
        private readonly SeedRunner $runner,
        private readonly DefaultSalesChannel $defaultSalesChannel,
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
            'Sales channel whose navigation tree the categories attach under. Defaults to the '
            . "shop's only active Storefront channel.",
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately from {@see SeedRunner::run()}'s tax
     *     lookup — the same "uncaught, by design" treatment `\RuntimeException` below gets: a
     *     connection failure is not a payload to guess at, and Symfony's console runner already
     *     reports an uncaught exception clearly.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

        if ($env !== 'prod') {
            // A warning, not a block (Global Constraints) — measurement quality, not correctness.
            $io->warning(\sprintf(
                'APP_ENV is "%s", not "prod". HANDOFF.md: dev mode inflates every number and will make '
                . 'writing 3,617 products slower. Proceeding anyway.',
                \is_string($env) ? $env : 'unset',
            ));
        }

        $given = $input->getOption('sales-channel');

        try {
            $salesChannelId = \is_string($given) && $given !== '' ? $given : $this->defaultSalesChannel->id();
        } catch (NoDefaultSalesChannelException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }
        $context = $this->contextFactory->create(Uuid::randomHex(), $salesChannelId);

        try {
            $report = $this->runner->run($io, $context);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf(
            'Seeded %d categories, %d property groups, %d products (%d sellable units).',
            $report->categoryCount,
            $report->propertyGroupCount,
            $report->productCount,
            $report->sellableUnits,
        ));

        return self::SUCCESS;
    }
}
