<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Support;

use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * An {@see AddToCartTool} over the fixture catalogue, with the trace it recorded into.
 *
 * The same reasoning as {@see UsesCatalogFixture} (ruling R38): several test classes each build this
 * tool the same way, and a builder every one of them repeats is one that drifts — the drift showing
 * up as two files disagreeing about what a wired tool looks like rather than as a failure.
 *
 * `$trace` is a property rather than a return value because a caller asserting on a refusal needs
 * the recorder the tool wrote into, and {@see self::cartTool()} replaces it on every call so no case
 * can read another's events.
 *
 * Shaped for the other `AddToCartTool*Test` files to adopt; they are left alone here because they
 * pass, and a refactor of green tests does not belong in a bugfix.
 */
trait BuildsCartTool
{
    /**
     * Declared here rather than in the using class, deliberately: a trait method that writes to a
     * property the trait does not declare is `mixed` to `mago analyze`, and every read of it
     * downstream becomes an error. A using class must therefore NOT redeclare it — two declarations
     * of the same property are a fatal error — but must still assign it in its own constructor, so
     * the uninitialised-property check can see a value before any case runs.
     */
    private TraceRecorder $trace;

    private function cartTool(?FixtureCommerceGateway $gateway = null, ?AssistantConfig $config = null): AddToCartTool
    {
        $this->trace = new TraceRecorder();

        return new AddToCartTool(
            $gateway ?? FixtureCommerceGateway::fromFile(__DIR__ . '/../Fixtures/catalog.json'),
            new BlocklistFilter(),
            new FactRenderer($this->trace),
            $this->trace,
            $config ?? new AssistantConfig(),
        );
    }

    /**
     * Every reason code the tool recorded, so a case can assert which refusal it got rather than
     * only that it got one.
     *
     * @return list<mixed>
     */
    private function cartReasonCodes(): array
    {
        $codes = [];

        foreach ($this->trace->events() as $event) {
            if ($event->stage === AddToCartTool::TRACE_STAGE) {
                $codes[] = $event->payload['policyReasonCode'] ?? null;
            }
        }

        return $codes;
    }
}
