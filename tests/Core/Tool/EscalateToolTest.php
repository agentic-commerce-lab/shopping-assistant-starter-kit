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

    public function testTheNoteDoesNotAskForASecondApology(): void
    {
        // The shop's handover message is shown above the link and may itself be an apology (staging,
        // 2026-09-22). A note that also told the model to decline gave the shopper two of them.
        $tool = new EscalateTool(new TraceRecorder(), new AssistantConfig(escalationUrl: '/contact'));

        $note = $tool(reason: 'order status question')['note'];

        self::assertStringNotContainsStringIgnoringCase('say that you cannot help', $note);
        self::assertStringContainsStringIgnoringCase('do not apologise', $note);
        self::assertStringContainsStringIgnoringCase('link follows', $note);
    }
}
