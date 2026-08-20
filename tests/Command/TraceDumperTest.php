<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\TraceDumper;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Handoff known-issue 8: no trace on this branch has ever been read end to end, so every claim
 * about model behaviour was inferred from assertion text. This class is what makes one readable —
 * which means a dumper that hides something is worse than no dumper at all, because it makes the
 * trace look clean.
 */
final class TraceDumperTest extends TestCase
{
    public function testTheFourFieldsThatAreHowThisProductLiesAreAlwaysRendered(): void
    {
        // ARCHITECTURE.md names these four as "the four ways this class of product lies" and says
        // they are never collapsed in the Administration. The same rule has to hold here, since
        // this is the only trace reader that exists.
        $recorder = new TraceRecorder();
        $recorder->record('query.build', ['filtersDropped' => ['properties.Colour']]);
        $recorder->record('retrieve', ['hits' => 3, 'retainedIds' => ['a1', 'a2', 'a3']]);
        $recorder->record('validate', ['inventedProductIds' => ['fx-999']]);
        $recorder->record('grounding.select', ['modelClaimsDiscarded' => 1]);
        $recorder->record('render', ['stockSource' => 'variant']);

        $out = (new TraceDumper())->dump($recorder);

        foreach (['filtersDropped', 'inventedProductIds', 'modelClaimsDiscarded', 'stockSource'] as $field) {
            self::assertStringContainsString($field, $out);
        }
    }

    public function testTheValuesOfThoseFieldsAppearAndNotJustTheirNames(): void
    {
        // A dumper printing "inventedProductIds" without "fx-999" would satisfy the test above
        // while telling the reader nothing.
        $recorder = new TraceRecorder();
        $recorder->record('validate', ['inventedProductIds' => ['fx-999']]);

        $out = (new TraceDumper())->dump($recorder);

        self::assertStringContainsString('fx-999', $out);
    }

    public function testEveryRecordedStageAppearsInSequenceOrder(): void
    {
        $recorder = new TraceRecorder();
        $recorder->record('guard.check', []);
        $recorder->record('understand', []);
        $recorder->record('retrieve', []);

        $out = (new TraceDumper())->dump($recorder);

        $guard = strpos($out, 'guard.check');
        $understand = strpos($out, 'understand');
        $retrieve = strpos($out, 'retrieve');

        self::assertIsInt($guard);
        self::assertIsInt($understand);
        self::assertIsInt($retrieve);
        self::assertLessThan($understand, $guard);
        self::assertLessThan($retrieve, $understand);
    }

    public function testAStageThatRanTwiceIsShownTwiceRatherThanDeduplicated(): void
    {
        // TraceRecorder::stages() de-duplicates by design (ruling R18) and events() does not. A
        // dumper built on stages() would hide the second tool round entirely — and the tool-call
        // budget being exhausted was a live pilot blocker (ruling R52), invisible without this.
        $recorder = new TraceRecorder();
        $recorder->record('tool.call', ['name' => 'search_products']);
        $recorder->record('tool.call', ['name' => 'get_product']);

        $out = (new TraceDumper())->dump($recorder);

        self::assertSame(2, substr_count($out, 'tool.call'));
        self::assertStringContainsString('search_products', $out);
        self::assertStringContainsString('get_product', $out);
    }

    public function testAnEmptyTraceSaysSoRatherThanPrintingNothing(): void
    {
        // A turn that recorded no stage at all is a finding, not an empty page. Blank output
        // would read as "the dump failed" and send the reader looking in the wrong place.
        $out = (new TraceDumper())->dump(new TraceRecorder());

        self::assertNotSame('', trim($out));
    }

    public function testANestedPayloadIsRenderedRatherThanShownAsTheWordArray(): void
    {
        // variant.resolve records a list of attempt maps, which is exactly where a reader looks
        // to see whether resolution ran and what it decided.
        $recorder = new TraceRecorder();
        $recorder->record('variant.resolve', [
            'attempts' => [['parentId' => 'fafa', 'variantId' => 'a2a2', 'stockRefetched' => true]],
        ]);

        $out = (new TraceDumper())->dump($recorder);

        self::assertStringContainsString('a2a2', $out);
        self::assertStringNotContainsString('Array', $out);
    }
}
