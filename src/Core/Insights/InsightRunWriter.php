<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Entity\InsightRun\InsightRunEntity;

/**
 * Writes a finished run, and reads back where the next window starts.
 *
 * **One `create` call for the run and one for its findings**, not one per finding: a night at 100 %
 * on a busy shop is hundreds of rows, and a DAL round trip each would make a nightly task a
 * nightly outage.
 *
 * The findings are written even when the run records a `judgeError`, because the two are not
 * exclusive: a judge can answer about four conversations and then time out on the fifth, and the
 * four findings are still worth having. What must never happen is the reverse — losing the metrics
 * because the judge failed — and that is the generator's `try`, not this class's job.
 */
final readonly class InsightRunWriter implements InsightRunSink
{
    /**
     * @param EntityRepository<\Swag\AssistantStarterKit\Entity\InsightRun\InsightRunCollection>         $runs
     * @param EntityRepository<\Swag\AssistantStarterKit\Entity\InsightFinding\InsightFindingCollection> $findings
     */
    public function __construct(
        private EntityRepository $runs,
        private EntityRepository $findings,
    ) {}

    public function lastWindowEnd(): ?\DateTimeImmutable
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('windowEnd', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $latest = $this->runs->search($criteria, Context::createDefaultContext())->first();

        if (!$latest instanceof InsightRunEntity) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($latest->getWindowEnd());
    }

    public function write(CompletedRun $run): string
    {
        $context = Context::createDefaultContext();
        $runId = Uuid::randomHex();

        $this->runs->create([[
            'id' => $runId,
            'windowStart' => $run->window->start,
            'windowEnd' => $run->window->end,
            'metrics' => $run->metrics->counts(),
            'searchTerms' => $run->metrics->searchTerms(),
            'sampleSeed' => $run->sampleSeed,
            'judgeError' => $run->judgeError,
        ]], $context);

        $rows = [];

        foreach ($run->findings as $finding) {
            $rows[] = [
                'id' => Uuid::randomHex(),
                'runId' => $runId,
                'conversationId' => $finding->conversationId,
                'type' => $finding->type->value,
                'severity' => $finding->severity,
                'summary' => $finding->summary,
                'quote' => $finding->quote,
                'suggestion' => $finding->suggestion,
            ];
        }

        if ($rows !== []) {
            $this->findings->create($rows, $context);
        }

        return $runId;
    }
}
