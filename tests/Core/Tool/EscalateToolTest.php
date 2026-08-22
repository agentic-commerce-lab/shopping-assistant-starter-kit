<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The tool's return value is read by the *model*, which paraphrases it into prose a shopper reads.
 * So the copy here is not a detail: for months it said "Handing this over to a human" with no
 * destination configured and nothing notified, and the model duly told shoppers so.
 */
final class EscalateToolTest extends TestCase
{
    public function testRecordsTheReasonAndSignalsHandover(): void
    {
        $trace = new TraceRecorder();
        $tool = new EscalateTool($trace, new AssistantConfig(escalationUrl: '/contact'));

        $result = $tool(reason: 'Order status is not available to me.');

        self::assertTrue($result['escalated']);
        self::assertSame('Order status is not available to me.', $trace->payload('escalate')['reason']);
    }

    public function testTheNoteDoesNotPromiseAHumanWhenNoDestinationIsConfigured(): void
    {
        // A promise no code keeps is worse than an admitted gap.
        $tool = new EscalateTool(new TraceRecorder(), new AssistantConfig());

        $result = $tool(reason: 'order status question');

        self::assertFalse($result['escalated']);
        self::assertStringNotContainsStringIgnoringCase('human', $result['note']);
        self::assertStringNotContainsStringIgnoringCase('team', $result['note']);
    }

    public function testTheNoteAnnouncesAHandoffWhenADestinationIsConfigured(): void
    {
        $tool = new EscalateTool(new TraceRecorder(), new AssistantConfig(escalationUrl: '/contact'));

        self::assertStringContainsStringIgnoringCase('link', $tool(reason: 'order status question')['note']);
    }

    public function testTheNoteNeverContainsTheUrlItself(): void
    {
        // D3: the shop supplies facts, the model supplies words. A URL in the note is a URL the
        // model will retype, and a retyped URL is a 404 waiting to happen. HandoffPayload renders it.
        $tool = new EscalateTool(new TraceRecorder(), new AssistantConfig(escalationUrl: '/contact'));

        self::assertStringNotContainsString('/contact', $tool(reason: 'why')['note']);
    }

    public function testTheTraceRecordsWhetherADestinationExisted(): void
    {
        // Without this a merchant reading a trace cannot tell "escalated to the contact page" from
        // "gave up because nothing was configured" — and the second is a settings bug they can fix.
        $trace = new TraceRecorder();
        (new EscalateTool($trace, new AssistantConfig()))(reason: 'why');

        self::assertSame(['reason' => 'why', 'hasDestination' => false], $trace->payload('escalate'));
    }

    public function testTheNoteForbidsClaimingContactWasMade(): void
    {
        // The note is read by the model and paraphrased. "This needs the shop team" was rendered as
        // "I've flagged this to the team" in six of six live runs (2026-08-22), so the instruction
        // now rules that out explicitly instead of leaving it to inference.
        $tool = new EscalateTool(new TraceRecorder(), new AssistantConfig(escalationUrl: '/contact'));

        $note = $tool(reason: 'order status question')['note'];

        self::assertStringContainsStringIgnoringCase('do not say', $note);
        self::assertStringContainsStringIgnoringCase('nothing has been sent', $note);
    }

    public function testTheNoteOpensWithADeclineRatherThanAHandover(): void
    {
        // The model paraphrases the *first* thing it is told to do. "This needs the shop team" put a
        // handover in that slot, and the model wrote one. A decline goes there instead; the link is
        // the second clause.
        $tool = new EscalateTool(new TraceRecorder(), new AssistantConfig(escalationUrl: '/contact'));

        $note = $tool(reason: 'order status question')['note'];

        self::assertStringContainsStringIgnoringCase('cannot help', $note);
        self::assertLessThan(
            mb_stripos($note, 'link'),
            mb_stripos($note, 'cannot help'),
            'the decline has to come before the link, not after it',
        );
    }
}
