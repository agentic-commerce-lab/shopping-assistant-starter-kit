<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\HandoffPayload;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * The handoff is a function of the outcome and the configuration — never of anything the model said.
 * That is what makes the URL unmanglable: it is not in the model's context at all.
 */
final class HandoffPayloadTest extends TestCase
{
    public function testAnEscalatedTurnWithADestinationYieldsTheBlock(): void
    {
        $payload = (new HandoffPayload())->of(
            'escalated',
            new AssistantConfig(escalationUrl: '/contact', escalationMessage: 'Our team can help with orders.'),
        );

        self::assertSame(['message' => 'Our team can help with orders.', 'url' => '/contact'], $payload);
    }

    public function testANonEscalatedTurnYieldsNothing(): void
    {
        $config = new AssistantConfig(escalationUrl: '/contact');
        $payload = new HandoffPayload();

        self::assertNull($payload->of('product_shown', $config));
        self::assertNull($payload->of('cart_added', $config));
        self::assertNull($payload->of('no_result', $config));
        self::assertNull($payload->of('error', $config));
    }

    public function testAnEscalatedTurnWithNoDestinationYieldsNothing(): void
    {
        // EscalateTool already stops the prose promising a handoff in this case. This stops the
        // interface rendering an empty one beside it.
        self::assertNull((new HandoffPayload())->of('escalated', new AssistantConfig()));
    }

    public function testNothingIsOfferedOnceEscalationIsSwitchedOff(): void
    {
        // A live turn cannot reach this state — with the toggle off the tool does not exist, so no
        // turn ends as `escalated`. A *stored* one can: transcripts written before the merchant
        // switched escalation off are re-hydrated through this class, and must not offer a route the
        // merchant has since withdrawn.
        $payload = (new HandoffPayload())->of(
            'escalated',
            new AssistantConfig(enableEscalation: false, escalationUrl: '/contact'),
        );

        self::assertNull($payload);
    }

    public function testAnEmptyMessageLeavesTheDefaultToTheInterface(): void
    {
        // Same convention as `greeting`: a blank merchant field falls back to a translated snippet,
        // which lives in the storefront, so the server sends '' rather than an English default that
        // would reach a German shop untranslated.
        $payload = (new HandoffPayload())->of('escalated', new AssistantConfig(escalationUrl: '/contact'));

        self::assertSame(['message' => '', 'url' => '/contact'], $payload);
    }
}
