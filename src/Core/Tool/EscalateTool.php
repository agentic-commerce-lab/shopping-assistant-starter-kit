<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Terminal tool: hands the conversation to a human. Exposed to the model as a
 * Symfony AI tool.
 */
#[AsTool(
    name: 'escalate',
    description: 'Hand the conversation to a human. Use for order status, returns, account '
    . 'questions, complaints, or anything you cannot answer from shop data.',
)]
final class EscalateTool
{
    public function __construct(
        private readonly TraceRecorder $trace,
    ) {}

    /**
     * @param string $reason Why the conversation needs a human, in one short sentence.
     *
     * @return array{escalated: bool, note: string}
     */
    public function __invoke(string $reason): array
    {
        $reason = Guard::boundedString($reason, 500, 'reason') ?? '';

        $this->trace->record('escalate', ['reason' => $reason]);

        return [
            'escalated' => true,
            'note' => 'Handing this over to a human.',
        ];
    }
}
