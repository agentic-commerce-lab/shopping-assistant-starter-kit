<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The shop-information passages a run handed the model, read back out of the trace.
 *
 * **The trace is the only record of them**, which is why {@see SearchShopInfoTool} writes them there:
 * the tool sits on the unprivileged tier and cannot reach the renderer that carries product cards, so
 * anything wanting to audit a reply against the text behind it has to come here.
 *
 * Shared by the runtime audit and the `no_unsupported_period_in_prose` eval assertion, rather than
 * copied into both — two readers of one trace contract, and the copy that drifts is always the one
 * nobody reads.
 */
final readonly class RetrievedPassages
{
    /**
     * Every passage from every retrieval in the run, in order.
     *
     * **Every retrieval, not the last.** One recorder spans a whole conversation (ruling R84), and a
     * period supported by turn one's passage is not an invention when restated in turn two.
     *
     * @return list<string>
     */
    public static function from(TraceRecorder $trace): array
    {
        $passages = [];

        foreach ($trace->events() as $event) {
            if ($event->stage !== 'retrieve.shopinfo') {
                continue;
            }

            $found = $event->payload['passages'] ?? null;

            foreach (\is_array($found) ? $found : [] as $passage) {
                if (\is_string($passage) && $passage !== '') {
                    $passages[] = $passage;
                }
            }
        }

        return $passages;
    }
}
