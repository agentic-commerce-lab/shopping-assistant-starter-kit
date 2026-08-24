<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSource;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceJsonSerialiser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Takes conversations out of the Administration.
 *
 * **This endpoint emits customer names**, which is why the privilege is declared on the route: the
 * file is personal data the moment it is written, and what may produce it must not depend on the
 * frontend behaving.
 *
 * It owns policy — the bound, the refusals, the file name — and nothing else. The format lives in
 * {@see TraceJsonSerialiser}; the reading lives behind {@see TraceExportSource}, so everything here
 * is testable without a database.
 *
 * **One format, and no parameter to choose it.** A CSV export shipped alongside this and was removed
 * on 2026-08-24: it could only ever carry a summary row per conversation, never the events, so it
 * promised "the traces" and delivered a metrics table. A trace is nested and formvariable, which is
 * the one shape CSV cannot hold.
 *
 * Not an `AbstractController`: it needs no container, no twig and no `setContainer()` call, and
 * extending one would add a dependency purely to inherit helpers this never uses.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class AssistantTraceExportController
{
    /**
     * How many conversations one export may carry.
     *
     * Retention keeps 30 days and a busy shop is well past a thousand in that time, so this is
     * reached in practice rather than in theory. A synchronous request that assembles tens of
     * thousands of event rows is not a feature — and refusing with the count tells a merchant what
     * to narrow, which a timeout does not.
     */
    private const MAX_CONVERSATIONS = 1000;

    public function __construct(
        private readonly TraceExportSource $source,
    ) {}

    #[Route(
        path: '/api/_action/swag-assistant/trace/export',
        name: 'api.action.swag_assistant.trace.export',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['swag_assistant_conversation:read']],
        methods: ['POST'],
    )]
    public function export(Request $request, Context $context): Response
    {
        $export = TraceExportRequest::fromRequest($request);

        if ($export->ids === []) {
            return new JsonResponse([
                'error' => 'Select at least one conversation to export.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (\count($export->ids) > self::MAX_CONVERSATIONS) {
            return new JsonResponse([
                'error' => \sprintf(
                    'This export covers %d conversations; at most %d can be exported at once. '
                    . 'Narrow the filter and try again.',
                    \count($export->ids),
                    self::MAX_CONVERSATIONS,
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        ['conversations' => $conversations, 'salesChannelNames' => $names] = $this->source->load(
            $export->ids,
            $context,
        );

        return self::file(
            TraceJsonSerialiser::serialise($conversations, $names),
            \count($export->ids) - \count($conversations),
        );
    }

    private static function file(string $body, int $skipped): Response
    {
        $response = new Response($body, Response::HTTP_OK);

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', \sprintf(
            'attachment; filename=assistant-traces-%s.json',
            date('Y-m-d-His'),
        ));

        // Named rather than silent: an id that no longer resolves must not cost the merchant the
        // rest of the export, but "some rows are missing" is not something to discover by counting.
        if ($skipped > 0) {
            $response->headers->set('X-Swag-Assistant-Skipped', (string) $skipped);
        }

        return $response;
    }
}
