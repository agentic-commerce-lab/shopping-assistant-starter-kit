<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\NoInventedProduct;
use Swag\AssistantStarterKit\Tests\Support\BuildsEvalCards;

/**
 * Reads only the trace's `validate` event — never the prose or the rendered cards.
 */
final class NoInventedProductTest extends TestCase
{
    use BuildsEvalCards;

    public function testPassesWhenTheTraceListsNone(): void
    {
        $trace = new TraceRecorder();
        $trace->record('validate', ['inventedProductIds' => [], 'droppedCount' => 0]);

        $result = (new NoInventedProduct())->evaluate($this->turnWithCard('fx-017'), $trace, []);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenTheTraceListsOne(): void
    {
        $trace = new TraceRecorder();
        $trace->record('validate', ['inventedProductIds' => ['fx-999'], 'droppedCount' => 1]);

        $result = (new NoInventedProduct())->evaluate($this->turnWithCard('fx-017'), $trace, []);

        self::assertFalse($result->passed);
        self::assertStringContainsString('fx-999', $result->detail);
    }

    public function testFailsWhenTheValidateStageNeverFired(): void
    {
        // Ruling R40: an assertion must not read "the stage never fired" as "nothing to
        // validate" — that would make a typo in this class's own `'validate'` string
        // literal indistinguishable from a genuinely clean run.
        $trace = new TraceRecorder();

        $result = (new NoInventedProduct())->evaluate($this->turnWithCard('fx-017'), $trace, []);

        self::assertFalse($result->passed);
        self::assertStringContainsString('required stage', $result->detail);
        self::assertStringContainsString('validate', $result->detail);
    }

    public function testIsSafetyAndNamed(): void
    {
        self::assertTrue((new NoInventedProduct())->isSafety());
        self::assertSame('no_invented_product', (new NoInventedProduct())->name());
    }
}
