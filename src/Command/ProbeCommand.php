<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the commerce gateway against the shop's real catalogue and prints what comes back.
 *
 * It closes two gaps at once, both named in the handoff:
 *
 * - The spec's day plan has *"verify against a real catalog"* as a step with no mechanism. Every
 *   test in this repo builds a fixture gateway; nothing had ever executed a DAL query, so the
 *   claim "this answers from real data" rested on wiring alone.
 * - Known-issue 8: **no trace had ever been read end to end.** `--ask` dumps one, via
 *   {@see TraceDumper}.
 *
 * A console command has no HTTP request, so {@see SalesChannelContextProvider::current()} would
 * throw. The context is built here and supplied through that provider's callback scope, which is
 * the only reason a real-catalogue check is possible before the widget exists — otherwise the first
 * look at a real product would happen only once everything else already worked.
 */
#[AsCommand(
    name: 'swag:assistant:probe',
    description: 'Run the commerce gateway against the real catalogue and print the result.',
)]
final class ProbeCommand extends Command
{
    /** The Storefront sales channel of the lab environment; overridable for any other shop. */
    private const DEFAULT_SALES_CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly SalesChannelContextProvider $contextProvider,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly ProbeTurnRunner $turnRunner,
        private readonly ProbeRenderer $renderer = new ProbeRenderer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search the catalogue for a term.')
            ->addOption(
                'ask',
                null,
                InputOption::VALUE_REQUIRED,
                'Run one full turn against a live model and dump its trace. Needs '
                . 'ASSISTANT_LLM_BASE_URL, ASSISTANT_LLM_API_KEY and ASSISTANT_LLM_MODEL.',
            )
            ->addOption('facets', null, InputOption::VALUE_NONE, 'List the facets the catalogue offers.')
            ->addOption('variant', null, InputOption::VALUE_REQUIRED, 'Parent product id to resolve.')
            ->addOption(
                'option',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Option value for --variant, repeatable (e.g. --option=Blue --option=M).',
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many products to return.', '10')
            ->addOption(
                'sales-channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Sales channel id.',
                self::DEFAULT_SALES_CHANNEL,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $request = ProbeRequest::fromInput($input, self::DEFAULT_SALES_CHANNEL);

        // A console command has no HTTP request, so the provider would throw. Scoping the context
        // to this callback rather than setting it means it cannot leak into anything else.
        $context = $this->contextFactory->create(Uuid::randomHex(), $request->salesChannelId);

        return $this->contextProvider->use($context, fn(): int => match ($request->mode) {
            ProbeRequest::MODE_ASK => $this->ask($io, $request->question),
            ProbeRequest::MODE_FACETS => $this->showFacets($io),
            ProbeRequest::MODE_VARIANT => $this->resolveVariant($io, $request->parentId, $request->selections),
            ProbeRequest::MODE_SEARCH => $this->search($io, $request->term, $request->limit),
            default => $this->explainUsage($io),
        });
    }

    private function explainUsage(SymfonyStyle $io): int
    {
        $io->error('Give one of --search=<term>, --facets, --variant=<parentId> or --ask="<question>".');

        return self::INVALID;
    }

    private function search(SymfonyStyle $io, string $term, int $limit): int
    {
        $cards = $this->gateway->search(new ProductQuery(term: $term, limit: $limit), new CatalogScope());

        if ($cards === []) {
            $io->warning(\sprintf('No products for "%s".', $term));

            return self::SUCCESS;
        }

        $this->renderer->cards($io, $cards);

        return self::SUCCESS;
    }

    /**
     * @param list<VariantSelection> $selections
     */
    private function resolveVariant(SymfonyStyle $io, string $parentId, array $selections): int
    {
        if ($selections === []) {
            $io->error('--variant needs at least one --option.');

            return self::INVALID;
        }

        $card = $this->gateway->resolveVariant($parentId, $selections, new CatalogScope());

        if ($card === null) {
            // Printed as data, not as an error: null is the CORRECT answer to an under-specified
            // or unmatched selection, and the whole point of D4 is that it never guesses.
            $io->writeln('null');
            $io->writeln('<comment>No single variant matches — resolution refuses to guess.</comment>');

            return self::SUCCESS;
        }

        $this->renderer->card($io, $card);

        return self::SUCCESS;
    }

    /**
     * One complete turn against a live model, then its trace. The only mode that spends money, and
     * the only one that shows what the model actually did rather than what the catalogue holds.
     */
    private function ask(SymfonyStyle $io, string $question): int
    {
        $missing = $this->turnRunner->missingEnvironment();

        if ($missing !== []) {
            // All three are required (ruling R43). Naming which are absent beats "not configured".
            $io->error(\sprintf('--ask needs a live model. Not set: %s.', implode(', ', $missing)));

            return self::INVALID;
        }

        $result = $this->turnRunner->ask($question, new AssistantConfig());
        $turn = $result['turn'];

        $io->section('reply');
        $io->writeln($turn->prose);

        $io->section('rendered cards');
        if ($turn->cards === []) {
            // Not a formatting nicety: an assistant that renders nothing has nothing to invent and
            // nothing to contradict, so two safety assertions pass vacuously (ruling R47). An empty
            // card set is a finding and has to read like one.
            $io->warning('No cards rendered — the reply is ungrounded.');
        } else {
            $this->renderer->cards($io, $turn->cards);
        }

        $io->section('outcome');
        $io->writeln($turn->outcome);

        if ($turn->unbackedPrices !== []) {
            $io->warning(\sprintf('Prices in the prose no card backs: %s', implode(', ', $turn->unbackedPrices)));
        }

        $io->section('trace');
        $io->writeln($result['trace']);

        return self::SUCCESS;
    }

    private function showFacets(SymfonyStyle $io): int
    {
        $this->renderer->facets($io, $this->gateway->facets(new CatalogScope()));

        return self::SUCCESS;
    }
}
