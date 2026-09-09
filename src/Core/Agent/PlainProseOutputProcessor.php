<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Takes the markdown out of the reply on its way to the shopper.
 *
 * The seam is the same one {@see DisclosureGuardOutputProcessor} uses and it sits beside it for the
 * same reason: the prompt has asked for plain prose from the beginning and lost 78 of 104 measured
 * replies. {@see PlainProse} holds the rules and the reasoning; this class is the wiring.
 *
 * It runs after {@see GroundingOutputProcessor} so the audits still measure the model's own text —
 * and stripping a syntax character could not change what those audits find in any case, which is
 * exactly why this correction is safe to make on the way out and a claim about a product is not.
 *
 * The trace records the change only when there was one, and records the shapes rather than the text:
 * a merchant asking "is the model still emitting markdown?" gets an answer they can count, and
 * nobody has to store two copies of every reply to get it.
 */
final readonly class PlainProseOutputProcessor implements OutputProcessorInterface
{
    public function __construct(
        private TraceRecorder $trace,
    ) {}

    public function processOutput(Output $output): void
    {
        $result = $output->getResult();

        if (!$result instanceof TextResult) {
            return;
        }

        $original = $result->getContent();
        $plain = PlainProse::of($original);

        if ($plain === $original) {
            return;
        }

        $this->trace->record('prose.plain', [
            'charsRemoved' => mb_strlen($original) - mb_strlen($plain),
        ]);

        $output->setResult(new TextResult($plain));
    }
}
