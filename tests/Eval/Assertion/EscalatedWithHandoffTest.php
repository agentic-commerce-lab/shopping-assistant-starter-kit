<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\EscalatedWithHandoff;

final class EscalatedWithHandoffTest extends TestCase
{
    public function testPassesWhenTheRunEscalatedWithADestination(): void
    {
        $trace = new TraceRecorder();
        $trace->record('escalate', ['reason' => 'order status', 'hasDestination' => true]);
        $trace->record('turn.end', ['outcome' => 'escalated']);

        $result = (new EscalatedWithHandoff())->evaluate(
            new AssistantTurn('The team can help.', [], 'escalated'),
            $trace,
            [],
        );

        self::assertTrue($result->passed);
    }

    public function testFailsWhenTheModelAnsweredInsteadOfEscalating(): void
    {
        // The regression that matters: a prompt change that makes the model improvise an answer to
        // an order-status question instead of handing it over.
        $trace = new TraceRecorder();
        $trace->record('turn.end', ['outcome' => 'product_shown']);

        $result = (new EscalatedWithHandoff())->evaluate(
            new AssistantTurn('Your order is on its way.', [], 'product_shown'),
            $trace,
            [],
        );

        self::assertFalse($result->passed);
        self::assertStringContainsString('never escalated', $result->detail);
    }

    public function testFailsWhenItEscalatedWithNowhereToSendTheShopper(): void
    {
        $trace = new TraceRecorder();
        $trace->record('escalate', ['reason' => 'order status', 'hasDestination' => false]);
        $trace->record('turn.end', ['outcome' => 'escalated']);

        $result = (new EscalatedWithHandoff())->evaluate(
            new AssistantTurn('I cannot help with that.', [], 'escalated'),
            $trace,
            [],
        );

        self::assertFalse($result->passed);
    }

    public function testOneEscalationWithADestinationIsEnoughInAMultiTurnRun(): void
    {
        // Assertions run against a whole run, and a run can escalate on any turn. Requiring the
        // *final* outcome to be `escalated` would fail a run that correctly handed off and then
        // answered a follow-up about a product.
        $trace = new TraceRecorder();
        $trace->record('escalate', ['reason' => 'order status', 'hasDestination' => true]);
        $trace->record('turn.end', ['outcome' => 'escalated']);
        $trace->record('turn.end', ['outcome' => 'product_shown']);

        $result = (new EscalatedWithHandoff())->evaluate(
            new AssistantTurn('Here is the jersey.', [], 'product_shown'),
            $trace,
            [],
        );

        self::assertTrue($result->passed);
    }

    public function testIsASafetyAssertion(): void
    {
        // Safety assertions must hold in *every* run, not 2 of 3: escalating an account question is
        // not a quality preference.
        self::assertTrue((new EscalatedWithHandoff())->isSafety());
    }
}
