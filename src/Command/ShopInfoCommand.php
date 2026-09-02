<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\DocumentRecords;
use Swag\AssistantStarterKit\ShopInfo\DocumentIngestionFactory;
use Swag\AssistantStarterKit\ShopInfo\PassageLookup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Index, list, delete and query the shop's own documents from the console.
 *
 * This exists before the admin module (Part 2) on purpose: it makes the whole chain — extract, chunk,
 * embed, store, retrieve — provable against a real document and a real provider before any Vue
 * component is built on top of it. An upload UI over an unproven chain would put the first real
 * embedding call behind three layers of form handling.
 *
 * **`--query` is not a convenience.** It prints every score, including the ones a threshold would
 * reject, which is how spec R4's threshold gets measured instead of guessed — and it costs embedding
 * calls only, no model turns.
 */
#[AsCommand(
    name: 'swag:assistant:shopinfo',
    description: 'Index, list, delete or query the shop information documents.',
)]
final class ShopInfoCommand extends Command
{
    private const QUERY_LIMIT = 10;

    // @mago-expect lint:excessive-parameter-list
    // Standing-constraints carve-out 2: a command constructor injecting the collaborators it
    // orchestrates. The sixth is `DefaultSalesChannel`, which replaced a hard-coded id — see that
    // class. Grouping it with anything here would pair "which shop" with an unrelated concern, and
    // every parameter below is used exactly once in `execute()`.
    public function __construct(
        private readonly DocumentIngestionFactory $ingestions,
        private readonly DefaultSalesChannel $defaultSalesChannel,
        private readonly DocumentRecords $records,
        private readonly PassageLookup $lookup,
        private readonly SystemConfigAssistantConfig $config,
        private readonly ShopInfoRenderer $renderer = new ShopInfoRenderer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'Path to a file to index.')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List indexed documents.')
            ->addOption('delete', null, InputOption::VALUE_REQUIRED, 'Delete a document by file name.')
            ->addOption(
                'query',
                null,
                InputOption::VALUE_REQUIRED,
                'Retrieve passages for a question and print every score, rejected ones included.',
            )
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
            $request = ShopInfoRequest::fromInput($input, $this->defaultSalesChannel);
        } catch (NoDefaultSalesChannelException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }
        $model = $this->config->forSalesChannel($request->salesChannelId)->embeddingModel;

        return match ($request->mode) {
            ShopInfoRequest::MODE_INDEX => $this->index($io, $request, $model),
            ShopInfoRequest::MODE_DELETE => $this->delete($io, $request),
            ShopInfoRequest::MODE_QUERY => $this->query($io, $request, $model),
            ShopInfoRequest::MODE_LIST => $this->list($io, $request->salesChannelId),
            default => $this->explainUsage($io),
        };
    }

    private function explainUsage(SymfonyStyle $io): int
    {
        $io->error('Give one of --index=<path>, --list, --delete=<name> or --query="<question>".');

        return self::INVALID;
    }

    private function index(SymfonyStyle $io, ShopInfoRequest $request, string $model): int
    {
        $bytes = is_file($request->path) ? file_get_contents($request->path) : false;

        if ($bytes === false) {
            $io->error(\sprintf('Cannot read "%s".', $request->path));

            return self::FAILURE;
        }

        $name = basename($request->path);

        try {
            $this->ingestions->forSalesChannel($request->salesChannelId, $model)->ingest(
                $name,
                $bytes,
                $request->salesChannelId,
            );
        } catch (\Throwable $failure) {
            // The document itself now carries this reason — see DocumentIngestion. Printing it here
            // as well is what makes a CLI run self-explanatory.
            $io->error($failure->getMessage());

            return self::FAILURE;
        }

        $document = $this->records->find($request->salesChannelId, $name);
        $this->renderer->documents($io, $document === null ? [] : [$document]);

        return self::SUCCESS;
    }

    private function list(SymfonyStyle $io, string $salesChannelId): int
    {
        $documents = $this->records->all($salesChannelId);

        if ($documents === []) {
            $io->warning('No documents indexed for this sales channel.');

            return self::SUCCESS;
        }

        $this->renderer->documents($io, $documents);

        return self::SUCCESS;
    }

    private function delete(SymfonyStyle $io, ShopInfoRequest $request): int
    {
        $document = $this->records->find($request->salesChannelId, $request->name);

        if ($document === null) {
            $io->error(\sprintf('No document named "%s" in this sales channel.', $request->name));

            return self::FAILURE;
        }

        // Passages first, then the record: the reverse order can leave passages whose document is
        // gone, and those are retrievable by a shopper and invisible to the merchant.
        $this->lookup->deleteDocument($document->id);
        $this->records->delete($document->id);

        $io->success(\sprintf('Deleted "%s" and its %d passages.', $request->name, $document->chunkCount));

        return self::SUCCESS;
    }

    /**
     * Every score, ordered, with nothing filtered out.
     *
     * `minScore: 0.0` on purpose: this is the instrument spec R4 calibrates the threshold with, so
     * hiding the rejected scores would defeat the only reason it exists.
     */
    private function query(SymfonyStyle $io, ShopInfoRequest $request, string $model): int
    {
        try {
            $passages = $this->lookup->retrieve(
                $request->question,
                $request->salesChannelId,
                $model,
                minScore: 0.0,
                limit: self::QUERY_LIMIT,
            );
        } catch (\Throwable $failure) {
            $io->error($failure->getMessage());

            return self::FAILURE;
        }

        $io->writeln(\sprintf('<info>%s</info>', $request->question));
        $this->renderer->passages($io, $passages);

        return self::SUCCESS;
    }
}
