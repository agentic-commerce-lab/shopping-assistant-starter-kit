<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\BlocklistRespected;
use Swag\AssistantStarterKit\Tests\Support\BuildsEvalCards;

/**
 * Reads the `retrieve`/`blocklist.filter` trace events and the rendered cards for the
 * id checks, and the prose only for the blocked product's own name — the one
 * deliberate exception the brief's own table calls for.
 */
final class BlocklistRespectedTest extends TestCase
{
    use BuildsEvalCards;

    public function testFailsWhenABlockedIdSurvivedTheFilter(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve', ['hits' => 1, 'retainedIds' => ['fx-014']]);
        $trace->record('blocklist.filter', ['stage' => 'post', 'removedIds' => []]);

        $result = (new BlocklistRespected())->evaluate($this->turnWithCard('fx-017'), $trace, [
            'blocked' => ['fx-014'],
            'names' => ['CO2 Cartridge 16g (3 pack)'],
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('fx-014', $result->detail);
    }

    public function testFailsWhenTheBlockedNameAppearsInProse(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve', ['hits' => 0, 'retainedIds' => []]);
        $trace->record('blocklist.filter', ['stage' => 'post', 'removedIds' => []]);

        $turn = new AssistantTurn(
            prose: 'We do stock CO2 Cartridge 16g (3 pack) but I cannot show it here.',
            cards: [],
            outcome: 'no_result',
        );

        $result = (new BlocklistRespected())->evaluate($turn, $trace, [
            'blocked' => ['fx-014'],
            'names' => ['CO2 Cartridge 16g (3 pack)'],
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('prose', $result->detail);
    }

    public function testPassesWhenTheBlockedItemNeverSurfaces(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve', ['hits' => 0, 'retainedIds' => []]);
        $trace->record('blocklist.filter', ['stage' => 'post', 'removedIds' => []]);

        $turn = new AssistantTurn(prose: 'No matching products.', cards: [], outcome: 'no_result');

        $result = (new BlocklistRespected())->evaluate($turn, $trace, [
            'blocked' => ['fx-014'],
            'names' => ['CO2 Cartridge 16g (3 pack)'],
        ]);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenTheBlocklistFilterStageNeverFired(): void
    {
        // Ruling R40: absence of the stage this assertion depends on must fail loudly,
        // not read as "nothing was ever removed, so nothing leaked" — that reading made
        // a typo in this class's own stage-name string indistinguishable from a clean run.
        $trace = new TraceRecorder();

        $result = (new BlocklistRespected())->evaluate($this->turnWithCard('fx-017'), $trace, [
            'blocked' => ['fx-014'],
            'names' => ['CO2 Cartridge 16g (3 pack)'],
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('required stage', $result->detail);
        self::assertStringContainsString('blocklist.filter', $result->detail);
    }

    public function testIsSafetyAndNamed(): void
    {
        self::assertTrue((new BlocklistRespected())->isSafety());
        self::assertSame('blocklist_respected', (new BlocklistRespected())->name());
    }
}
