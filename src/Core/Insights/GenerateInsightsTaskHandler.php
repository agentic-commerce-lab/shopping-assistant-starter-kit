<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Thin shell around {@see InsightsGenerator}; the logic lives there because it is testable there.
 *
 * Mirrors {@see \Swag\AssistantStarterKit\Core\Trace\Retention\PruneConversationsTaskHandler}
 * exactly, including taking the clock as a fresh `DateTimeImmutable` at call time rather than as an
 * injected service: the window's end must be the moment the run starts, and a handler that held a
 * clock from container build time would take it from whenever the worker booted.
 */
#[AsMessageHandler(handles: GenerateInsightsTask::class)]
final class GenerateInsightsTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly InsightsGenerator $generator,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->generator->generate(new \DateTimeImmutable());
    }
}
