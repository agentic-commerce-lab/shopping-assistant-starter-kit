<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Grounding\PropertyClaimExtractor;

/**
 * Saying a value is **absent** is not claiming it.
 *
 * The regression this exists for, measured on the running shop on 2026-09-03: asked for a red dress
 * in size S, the assistant replied *"The search found no dresses in red or burgundy in size S"* and
 * the audit flagged `Burgundy` — so the shopper was warned that *"the material and attribute details
 * on the card below are the ones that apply"* about a colour the reply had just ruled out. `Red`
 * escaped only because the shopper had used the word themselves, which is an accident of that turn.
 *
 * Every property warning recorded in that shop was this same false positive, because saying what is
 * not there is the ordinary shape of a no-match reply. Ruling R85: a warning that fires on correct
 * behaviour teaches shoppers to ignore the ones that matter.
 *
 * Both languages on every case, for the reason `AvailabilityClaimExtractorGermanTest` gives — there
 * is no per-turn language to select on, because the model takes it from the shopper's words.
 */
final class PropertyClaimNegationTest extends TestCase
{
    private function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Colour', FacetType::Terms, ['Red', 'Burgundy', 'Blue', 'Black', 'Navy', 'Rust']),
            new Facet('properties.Material', FacetType::Terms, ['Merino', 'Silk', 'Nylon']),
        ]);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function proseProvider(): iterable
    {
        // The live false positive, verbatim in shape.
        yield 'a no-match reply naming what it did not find' => [
            'The search found no dresses in red or burgundy in size S.',
            [],
        ];
        yield 'a no-match reply in German' => [
            'Ich habe leider keine Kleider in Rot oder Burgundy gefunden.',
            [],
        ];
        yield 'nothing in X' => ['There is nothing in silk this season.', []];
        yield 'without X' => ['We have no blue without merino.', []];
        yield 'weder … noch' => ['Weder Merino noch Silk sind verfügbar.', []];
        yield 'ohne' => ['Ohne Merino, dafür in Silk.', ['Silk']];

        // Denied from behind, which the first version of this fix missed — it read only the text
        // before a value, and the next live turn phrased the denial the other way round.
        yield 'the denial follows the value' => [
            'A search for dresses in red or burgundy in size S found no matches.',
            [],
        ];
        yield 'the same in German, verb-final' => [
            'Kleider in Rot oder Burgundy gibt es leider nicht.',
            [],
        ];

        // An offer is not a statement. Naming real catalogue values as a next step is what the
        // system prompt asks for, and the vocabulary is rendered into the prompt so the model can.
        yield 'values offered in a question' => [
            'Would you like to explore dresses in other colours like Blue, Black, or Navy?',
            [],
        ];
        yield 'values offered in a German question' => ['Moechten Sie Kleider in Blue oder Navy sehen?', []];

        // The whole reply that produced the live false positive, verbatim in shape: two values
        // asserted, one denied from behind, one offered in a question.
        yield 'the live reply end to end' => [
            'A search for dresses in red or burgundy in size S found no matches. '
                . 'The closest colour tone in the catalogue is Rust. '
                . 'For a wedding, I would recommend the Mini Dress 3136 in size S, as it is made from Silk. '
                . 'Would you like to explore dresses in other colours like Blue, Black, or Navy?',
            ['Rust', 'Silk'],
        ];

        // Real claims, which must still be found — the expensive direction to lose.
        yield 'a plain material claim' => ['It is made of merino wool.', ['Merino']];
        yield 'a denial and a claim in one sentence' => [
            'We have no blue, but the black one is merino.',
            ['Black', 'Merino'],
        ];
        yield 'the same without the comma English often drops' => [
            'we have no blue but the black one is merino',
            ['Black', 'Merino'],
        ];
        yield 'the claim before the denial of the same value' => [
            'this one is merino, but we have no merino',
            ['Merino'],
        ];
        yield 'aber opens a clause the denial cannot cross' => [
            'kein Merino, aber dieses ist aus Silk',
            ['Silk'],
        ];
        yield 'a denial cannot reach into the next sentence' => [
            'We have no blue. The jersey is merino.',
            ['Merino'],
        ];
        yield 'a trailing denial stops at its own comma' => ['It is merino, we have no silk.', ['Merino']];
        yield 'and opens a clause going forward' => ['This one is merino and we have no silk.', ['Merino']];
        // The case that forbids a general negation vocabulary in the trailing direction: `Blue` is
        // asserted here and something else is denied.
        yield 'a value asserted while another attribute is denied' => [
            'The blue jersey is not waterproof.',
            ['Blue'],
        ];
        // `noch` means "still" as often as it continues a `weder … noch`, and this is the assertion
        // shape. AvailabilityNegation lost a regression to the same word living inside `no`.
        yield 'noch is not a negation' => ['Das ist noch in Merino verfügbar.', ['Merino']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('proseProvider')]
    public function testNegatedValuesAreNotClaims(string $prose, array $expected): void
    {
        $found = (new PropertyClaimExtractor())->extract($prose, $this->facets());

        sort($found);
        sort($expected);

        self::assertSame($expected, $found);
    }
}
