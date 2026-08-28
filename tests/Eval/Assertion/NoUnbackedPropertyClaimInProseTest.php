<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\Warnings;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\NoUnbackedPropertyClaimInProse;

final class NoUnbackedPropertyClaimInProseTest extends TestCase
{
    public function testPassesWhenNothingIsUnbacked(): void
    {
        $turn = new AssistantTurn(
            prose: 'It is Merino.',
            cards: [],
            outcome: 'product_shown',
            warnings: new Warnings(),
        );

        $result = (new NoUnbackedPropertyClaimInProse())->evaluate($turn, new TraceRecorder(), []);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenAClaimIsUnbacked(): void
    {
        $turn = new AssistantTurn(
            prose: 'It is Nylon.',
            cards: [],
            outcome: 'product_shown',
            warnings: new Warnings(unbackedPropertyClaims: ['Nylon']),
        );

        $result = (new NoUnbackedPropertyClaimInProse())->evaluate($turn, new TraceRecorder(), []);

        self::assertFalse($result->passed);
    }
}
