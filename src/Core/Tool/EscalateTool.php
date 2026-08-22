<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
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
    /**
     * What the model is told when the merchant configured somewhere to send the shopper.
     *
     * **The prohibition is explicit because inference failed.** The previous wording — "this needs
     * the shop team, and a contact link follows" — was rendered by a live model as "I've flagged this
     * to the team", "I've escalated this to…" and "I've flagged your order #10023 to…", in **six of
     * six** runs of `order_status_escalates` on 2026-08-22. Nothing is sent anywhere, so each of
     * those is a false statement about the merchant's operations, made to a customer.
     *
     * The decline leads and the link follows, in that order, because the model paraphrases the first
     * instruction it is given: a handover in that slot produced a handover in the reply.
     *
     * It says a link *follows* rather than carrying one: the URL is rendered server-side by
     * {@see \Swag\AssistantStarterKit\Controller\HandoffPayload} from the same configuration, so the
     * model never has a URL it could retype wrongly (D3).
     * {@see \Swag\AssistantStarterKit\Eval\Assertion\NoHandoffClaimInProse} is what measures whether
     * this wording holds — the wording is a request, the assertion is the guarantee.
     */
    private const NOTE_WITH_DESTINATION =
        'Say that you cannot help with this yourself, and that a contact link follows your message. '
            . 'Nothing has been sent to anyone: do not say you have passed this on, flagged it, '
            . 'escalated it, forwarded it or notified anybody, and do not say that someone will '
            . 'follow up, reach out or get back to them. Do not write a URL yourself.';

    /**
     * And when they did not.
     *
     * **This must not mention a human, a team, or a follow-up.** Nothing is notified, so any of
     * those is a promise no code in this plugin keeps.
     */
    private const NOTE_WITHOUT_DESTINATION =
        'Say plainly that you cannot help with this kind of question here, and name something you '
            . 'can do instead — looking up a product, its price, or its availability.';

    public function __construct(
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param string $reason Why the conversation needs a human, in one short sentence.
     *
     * @return array{escalated: bool, note: string}
     */
    public function __invoke(string $reason): array
    {
        $reason = Guard::boundedString($reason, 500, 'reason') ?? '';

        $hasDestination = $this->config->escalationUrl !== '';

        $this->trace->record('escalate', [
            'reason' => $reason,
            'hasDestination' => $hasDestination,
        ]);

        return [
            // False when there is nowhere to escalate to. `escalated: true` with no destination is
            // the claim that started this.
            'escalated' => $hasDestination,
            'note' => $hasDestination ? self::NOTE_WITH_DESTINATION : self::NOTE_WITHOUT_DESTINATION,
        ];
    }
}
