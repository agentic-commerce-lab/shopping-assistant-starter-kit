<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Withholds a reply that recited the assistant's own tool names to the shopper.
 *
 * ## Why the enforcement is here and not only in the prompt
 *
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt::RULES} now forbids describing the tools,
 * the instructions or how the conversation is assembled, and that rule is worth having: it is what
 * makes the model decline on its own. But a prompt rule is a request, and this project has 104
 * measured demonstrations of what a request is worth — the plain-prose rule lost 78 of them, and the
 * two disclosures in the same corpus arrived after three correct refusals in the same conversation.
 * A control that the shopper's next rephrasing can switch off is not a control.
 *
 * What makes an output-side check affordable here is that the signal is decidable rather than
 * judged. See {@see DisclosedToolNames} for the measurement: 6 of 104 replies flagged, every one a
 * genuine disclosure, both complete leaks among them.
 *
 * ## What it does not do
 *
 * **It does not detect prompt text.** Matching the reply against the system prompt itself was
 * considered and dropped: the prompt contains ordinary sentences a legitimate reply may echo ("the
 * search found nothing"), so the check would trade this class's precision for coverage it cannot
 * measure. The tool names are the part of the prompt no shopper-facing sentence ever contains.
 *
 * **It does not clear the cards.** They belong to {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer},
 * and reaching into it from an output processor to un-render a batch would put presentation state in
 * two places. In the measured corpus every disclosing reply rendered zero cards, so the case is
 * theoretical; if it stops being theoretical, the fix belongs on the runner that reads both.
 *
 * ## Order in the chain
 *
 * After {@see GroundingOutputProcessor}, deliberately. That processor's audits — `validate`,
 * `claims.audit`, `grounding.select` — exist to record what the MODEL wrote, and they would report
 * on this class's replacement sentence instead if they ran second. The traces keep telling the truth
 * about the model; the shopper gets what left the process.
 */
final readonly class DisclosureGuardOutputProcessor implements OutputProcessorInterface
{
    public function __construct(
        private ToolboxInterface $toolbox,
        private TraceRecorder $trace,
        private string $language,
    ) {}

    public function processOutput(Output $output): void
    {
        $result = $output->getResult();

        if (!$result instanceof TextResult) {
            return;
        }

        $disclosed = DisclosedToolNames::in($result->getContent(), $this->toolNames());

        if ($disclosed === []) {
            return;
        }

        // The reply itself is not recorded: it is the thing being withheld, and a trace is read by
        // people who have not consented to seeing the prompt either. What a merchant needs is that
        // it happened, which names, and how much text went nowhere.
        $this->trace->record('disclosure.withheld', [
            'toolNames' => $disclosed,
            'withheldChars' => mb_strlen($result->getContent()),
        ]);

        $output->setResult(new TextResult(WithheldReplyMessage::for($this->language)));
    }

    /**
     * @return list<string>
     */
    private function toolNames(): array
    {
        return array_values(array_map(static fn(Tool $tool): string => $tool->getName(), $this->toolbox->getTools()));
    }
}
