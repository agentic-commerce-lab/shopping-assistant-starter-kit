<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Insights\ExportTraceSource;
use Swag\AssistantStarterKit\Core\Insights\InsightJudge;
use Swag\AssistantStarterKit\Core\Insights\InsightsAggregator;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettingsReader;
use Swag\AssistantStarterKit\Core\Insights\InsightWindow;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeBudget;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeSample;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the nightly judge over a trace export and prints what it found.
 *
 * **This command exists to satisfy decision D28, and it is the only way to.** The judge's accuracy
 * cannot have a unit test — its output is a judgement — so before a merchant is shown a single
 * finding, the judge is replayed over an archived corpus and every finding is read against its
 * conversation by hand. What comes out is a precision figure for ONE corpus and ONE model on one
 * date, which ruling R85 is unforgiving about confusing with a guarantee: a figure taken from the
 * corpus a detector was written against is not a precision figure.
 *
 * It shares the aggregator, the sample, the budget and the judge with the nightly run and varies
 * only the source ({@see ExportTraceSource}), because a measurement of something adjacent to the
 * shipped path measures nothing.
 *
 * `--dry-run` reads the file, aggregates and draws the sample without calling the model. The
 * archived corpus is 5.2 MB of real conversations; checking that the file parses and that the
 * sample is the size you expected costs nothing, and the run after it costs money.
 *
 * Depends on {@see InsightsSettingsReader} rather than on the concrete reader, which is not
 * ceremony: `SystemConfigInsightsSettings` is `final readonly` and cannot be doubled, so a test
 * asserting "the settings are not even read until a judge call is due" — the assertion that keeps
 * this command from spending money by accident — is only possible through the interface.
 */
#[AsCommand(
    name: 'swag:assistant:judge-replay',
    description: 'Replay the nightly insights judge over a trace export. Costs money unless --dry-run.',
)]
final class JudgeReplayCommand extends Command
{
    public function __construct(
        private readonly InsightsSettingsReader $settings,
        private readonly InsightJudge $judge,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('export', InputArgument::REQUIRED, 'Path to a trace export JSON file.');
        $this->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Percentage of conversations to judge.', '100');
        $this->addOption(
            'seed',
            null,
            InputOption::VALUE_REQUIRED,
            'Sample seed; the same seed draws the same conversations.',
            'replay',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Parse, aggregate and sample without calling the model.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $source = ExportTraceSource::fromFile((string) $input->getArgument('export'));
        } catch (\JsonException|\RuntimeException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $traces = $source->inWindow(InsightWindow::next(null, new \DateTimeImmutable()));
        $metrics = InsightsAggregator::aggregate($traces);
        $percent = max(0, min(100, (int) $input->getOption('sample')));
        $fitted = JudgeBudget::fit(JudgeSample::draw($traces, $percent, (string) $input->getOption('seed')));

        $io->writeln(\sprintf(
            '%d conversations in the export, %d in the sample, %d dropped over budget.',
            \count($traces),
            \count($fitted->traces),
            $fitted->dropped,
        ));
        $io->writeln(json_encode($metrics->counts(), \JSON_THROW_ON_ERROR));

        if ($input->getOption('dry-run') === true) {
            $io->note('--dry-run: no model was called and nothing was spent.');

            return Command::SUCCESS;
        }

        // An empty sample stops here rather than at the provider. `--sample=0` or an export with
        // nothing in it would otherwise send a request carrying no conversations at all — billed,
        // and answerable only with an empty array. Found by the test that asserts the settings are
        // not read until a call is due; the settings hold the key, so reading them IS the tell.
        if ($fitted->traces === []) {
            $io->note('Nothing to judge: the sample is empty. No model was called.');

            return Command::SUCCESS;
        }

        $settings = $this->settings->forSalesChannel();
        $io->writeln(\sprintf(
            'Judging with model "%s", scope "%s".',
            $settings->llm->model,
            $settings->dataScope->value,
        ));

        try {
            $findings = $this->judge->run($metrics, $fitted->traces, $settings);
        } catch (\JsonException|\Swag\AssistantStarterKit\Core\Llm\LlmException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        return JudgeReplayReport::render($io, $findings);
    }
}
