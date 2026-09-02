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
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
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
    // @mago-expect lint:excessive-parameter-list
    // Standing-constraints carve-out 2: a command constructor injecting the collaborators it
    // orchestrates. The sixth is `DefaultSalesChannel`, which replaced a hard-coded id — see that
    // class. Grouping it with anything here would pair "which shop" with an unrelated concern, and
    // every parameter below is used exactly once in `execute()`.
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly DefaultSalesChannel $defaultSalesChannel,
        private readonly SalesChannelContextProvider $contextProvider,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly ProbeTurnRunner $turnRunner,
        private readonly SystemConfigAssistantConfig $assistantConfig,
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
                "Sales channel id. Defaults to the shop's only active Storefront channel.",
            )
            ->addOption(
                'customer',
                null,
                InputOption::VALUE_REQUIRED,
                'Run as this customer, so customer-group, rule and B2B prices apply.',
            )
            ->addOption(
                'employee',
                null,
                InputOption::VALUE_REQUIRED,
                'Run as this B2B employee membership; needs --customer. Selects the organisation '
                . 'scope, which is what an Advanced Product Catalogue is bound to.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $request = ProbeRequest::fromInput($input, $this->defaultSalesChannel);
        } catch (NoDefaultSalesChannelException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        // A console command has no HTTP request, so the provider would throw. Scoping the context
        // to this callback rather than setting it means it cannot leak into anything else.
        $context = $this->contextFactory->create(
            Uuid::randomHex(),
            $request->salesChannelId,
            $request->shopper->contextOptions(),
        );

        // Before any measurement: Commercial resolves a shopper silently, so a context that is not
        // the one asked for must stop the run rather than quietly answer a different question.
        if (!(new ProbeShopperReport($request->shopper))->write($io, $context)) {
            return self::FAILURE;
        }

        return $this->contextProvider->use($context, fn(): int => match ($request->mode) {
            ProbeRequest::MODE_ASK => $this->ask($io, $request->question, $request->salesChannelId),
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
     *
     * **Under the merchant's own settings, which it used not to be.** This ran on `new
     * AssistantConfig()` — the shipped defaults — while claiming to show "what the model actually
     * did". Two things were wrong with that, and the second is the serious one:
     *
     * - Every measurement taken with it described a shop nobody runs. A blocklist, a cart ceiling, a
     *   tool-call budget: none of them applied, so `--ask` disagreed with the storefront on the same
     *   question and the difference was invisible.
     * - `enableAddToCart` defaults to **on**. On a shop where the merchant switched it off, the
     *   add-to-cart tool was constructed anyway and the model could reach a real cart through a
     *   guardrail the merchant had closed. A support command is not an exemption from policy.
     *
     * Reading the real config also means the assistant's own off switch now stops `--ask`, which is
     * correct: a probe that answers on a stopped assistant is reporting on a shop that does not
     * exist.
     */
    private function ask(SymfonyStyle $io, string $question, string $salesChannelId): int
    {
        $missing = $this->turnRunner->missingEnvironment();

        if ($missing !== []) {
            // All three are required (ruling R43). Naming which are absent beats "not configured".
            $io->error(\sprintf('--ask needs a live model. Not set: %s.', implode(', ', $missing)));

            return self::INVALID;
        }

        $result = $this->turnRunner->ask($question, $this->assistantConfig->forSalesChannel($salesChannelId));
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

        if ($turn->warnings->unbackedPrices !== []) {
            $io->warning(\sprintf('Prices in the prose no card backs: %s', implode(
                ', ',
                $turn->warnings->unbackedPrices,
            )));
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
