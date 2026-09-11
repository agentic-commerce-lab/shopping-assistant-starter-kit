<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The instructions the assistant is given, so a merchant can read what their own text is added to.
 *
 * **Composed here rather than copied into a template.** {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt}
 * assembles the prompt from `enableEscalation`, `onlyGivenInformation`, `enableMatchReasons`,
 * `enableCompareProducts` and the reply language, so a hardcoded copy in the administration would
 * drift from it at the next edit — and a preview that lies is worse than none, because it is exactly
 * what a merchant reaches for to check what they changed. That is the one way this differs from
 * `swag-assistant-escalation-preview`, which is static for its own good reasons.
 *
 * **Read-only, and that is the point rather than a limitation.** The merchant's control stays the one
 * appended slot (`agentVoice`), which cannot override the rules above it. This endpoint exists so
 * that slot can be understood — where the text lands, and what already stands above it — not so the
 * prompt can be edited. An editable prompt would fork it: every later improvement shipped with the
 * plugin would either miss that shop or silently overwrite the merchant's version, and there is no
 * third outcome.
 *
 * It answers for the **saved** configuration. Unsaved form fields are not reflected, and the card
 * says so rather than implying a live render.
 *
 * The per-turn blocks are absent: the catalogue vocabulary and the viewing context are per-request
 * probe results ({@see PromptProviderInterface::system()} takes both as parameters), so rendering
 * them here would need a sales-channel context and a facet probe on an administration request, and
 * would show one conversation's catalogue rather than the instructions. The card names the omission.
 *
 * **`system_config:read` is the privilege**, for the reason {@see AssistantStatusController} gives
 * for its own: the answer is derived from system config and nothing else, and every merchant who can
 * open this settings page already holds it.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class AssistantSystemPromptController
{
    public function __construct(
        private readonly PromptProviderInterface $prompt,
        private readonly SystemConfigAssistantConfig $assistantConfig,
    ) {}

    #[Route(
        path: '/api/_action/swag-assistant/system-prompt/{salesChannelId}',
        name: 'api.action.swag_assistant.system_prompt',
        requirements: ['salesChannelId' => '[0-9a-fA-F]{32}'],
        defaults: ['_acl' => ['system_config:read']],
        methods: ['GET'],
    )]
    public function systemPrompt(string $salesChannelId): JsonResponse
    {
        $prompt = $this->prompt->system($this->assistantConfig->forSalesChannel($salesChannelId));

        return new JsonResponse([
            'prompt' => $prompt,
            // Measured here so the card can state a size without counting characters in the
            // browser, and so "how big is this thing" is answered by the same code that built it.
            'characters' => mb_strlen($prompt),
        ]);
    }
}
