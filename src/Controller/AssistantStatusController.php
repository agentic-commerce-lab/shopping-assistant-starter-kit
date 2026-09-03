<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AssistantStarterKit\Core\Config\AssistantReadiness;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One question the settings page cannot answer for itself: can this assistant answer a shopper?
 *
 * {@see AssistantReadiness} carries the whole reason this endpoint exists — the status card used to
 * report a green "Running" on a shop whose chat endpoint answered 503, because it knew only the off
 * switch. It has to be asked over HTTP rather than read from the form, because environment variables
 * beat stored config and are in no form field.
 *
 * **`system_config:read` is the privilege**, not one of this plugin's own. The answer is derived from
 * system config and from nothing else, and every merchant who can open this settings page already
 * holds it — a plugin-specific privilege would make the card go blank for someone who is looking
 * straight at the settings it describes.
 *
 * Read-only, and it returns a boolean rather than the settings behind it. A GET that echoed which of
 * the three values was missing would name the API key as present or absent to anyone with config
 * read access; "configured" is all the card renders and all it needs.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class AssistantStatusController
{
    public function __construct(
        private readonly AssistantReadiness $readiness,
    ) {}

    #[Route(
        path: '/api/_action/swag-assistant/status',
        name: 'api.action.swag_assistant.status',
        defaults: ['_acl' => ['system_config:read']],
        methods: ['GET'],
    )]
    public function status(): JsonResponse
    {
        return new JsonResponse(['configured' => $this->readiness->isConfiguredAnywhere()]);
    }
}
