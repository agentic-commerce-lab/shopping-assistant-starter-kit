<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRenderer;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRunner;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports what the assistant costs against the shop's real catalogue.
 *
 * Phase B of the catalogue-scale work: phase A crossed every constant this kit sets, but did it with
 * an in-memory fixture, so it could say nothing about what happens below the gateway. This does —
 * facet-probe and search latency, how much of a real catalogue's vocabulary survives the prompt
 * budget, and how many lookups one cards request costs.
 *
 * **Read-only, and it reports rather than asserts.** A latency is not a pass or a fail, so there is
 * nothing here to exit non-zero about; the output is a table meant to be read, compared with the
 * previous run, and quoted.
 *
 * A console command has no HTTP request, so {@see SalesChannelContextProvider::current()} would
 * throw. The context is built here and supplied through that provider's callback scope — the same
 * pattern {@see ProbeCommand} uses, and the reason a real-catalogue measurement is possible from the
 * CLI at all.
 */
#[AsCommand(
    name: 'swag:assistant:benchmark',
    description: 'Measure assistant retrieval cost against the real catalogue. Read-only.',
)]
final class BenchmarkCommand extends Command
{
    // @mago-expect lint:excessive-parameter-list
    // Standing-constraints carve-out 2: a command constructor injecting the collaborators it
    // orchestrates. The sixth is `DefaultSalesChannel`, which replaced a hard-coded id — see that
    // class. Grouping it with anything here would pair "which shop" with an unrelated concern, and
    // every parameter below is used exactly once in `execute()`.
    public function __construct(
        private readonly BenchmarkRunner $runner,
        private readonly DefaultSalesChannel $defaultSalesChannel,
        private readonly SystemConfigAssistantConfig $configs,
        private readonly SalesChannelContextProvider $contextProvider,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly BenchmarkRenderer $renderer = new BenchmarkRenderer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'term',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Search term to measure, repeatable. Defaults to the committed query list.',
            )
            ->addOption('repetitions', null, InputOption::VALUE_REQUIRED, 'Searches per term.', '20')
            ->addOption(
                'card-id',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Product id for the cards-endpoint measurement, repeatable (up to CardIdList::MAX_IDS of 12).',
            )
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Name for this shop in the output.', 'shop')
            ->addOption(
                'sales-channel',
                null,
                InputOption::VALUE_REQUIRED,
                "Sales channel id. Defaults to the shop's only active Storefront channel.",
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $request = BenchmarkRequest::fromInput($input, $this->defaultSalesChannel);
        } catch (NoDefaultSalesChannelException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $context = $this->contextFactory->create(Uuid::randomHex(), $request->salesChannelId);

        return $this->contextProvider->use($context, function () use ($io, $request): int {
            $this->renderer->render($io, $this->runner->run(
                $request->shopLabel,
                // Per sales channel, because the config is: a merchant can configure the assistant
                // differently per channel, and measuring one channel's prompt with another's config
                // would report a prompt size nobody is ever sent.
                $this->configs->forSalesChannel($request->salesChannelId),
                $request->terms,
                $request->repetitions,
                $request->cardIds,
            ));

            return self::SUCCESS;
        });
    }
}
