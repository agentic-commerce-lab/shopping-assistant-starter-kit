<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\Warnings;
use Swag\AssistantStarterKit\Eval\TurnAggregate;

final class TurnAggregateWarningsTest extends TestCase
{
    public function testMergesAvailabilityAndPropertyClaimsAcrossTurns(): void
    {
        $turnOne = new AssistantTurn(
            prose: 'Turn one.',
            cards: [],
            outcome: 'product_shown',
            warnings: new Warnings(unbackedAvailabilityClaims: ['is available']),
        );
        $turnTwo = new AssistantTurn(
            prose: 'Turn two.',
            cards: [],
            outcome: 'product_shown',
            warnings: new Warnings(unbackedPropertyClaims: ['Merino']),
        );

        $merged = TurnAggregate::of([$turnOne, $turnTwo]);

        self::assertSame(['is available'], $merged->warnings->unbackedAvailabilityClaims);
        self::assertSame(['Merino'], $merged->warnings->unbackedPropertyClaims);
    }
}
