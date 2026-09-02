<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * A property word the shop's OWN retrieved document contains is not an unbacked claim.
 *
 * The exact sibling of {@see PassageFigureExemptionTest}, one claim type later, and the same defect:
 * *"A safety assertion that fires on correct behaviour trains people to ignore it."*
 *
 * ## Measured on the staging shop, 2026-09-02
 *
 * Asked *"what is the return policy"*, the assistant answered correctly out of the shop's own returns
 * document — *"…tyres that have been mounted on a **rim** cannot be returned"* — and the reply came
 * back annotated:
 *
 * ```
 * 38 claims.audit  {"unbackedPropertyClaims":["Rim"]}
 * 39 turn.end      {"cards":[],"outcome":"shop_info_retrieved"}
 * ```
 *
 * `Rim` is a value in this catalogue's property vocabulary, so the extractor recognises it as a
 * property claim; the shop's document is where the word actually came from. `unbackedPrices()` was
 * given the retrieved passages on 2026-08-27 for precisely this reason and `unbackedProperties()`
 * never was, so every shop-document sentence containing a catalogue word was unbacked by
 * construction.
 *
 * The shopper saw it as a note reading *"The material and attribute details on the card below are the
 * ones that apply"* — beside a correct answer, and (since the stale-card fix) beside no card at all.
 *
 * ## Why the passages are merged into one shop-prose argument
 *
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unbackedProperties()} sits at this
 * project's five-parameter ceiling and its docblock rules out a sixth. Both arguments are the same
 * kind of thing — prose the shop itself wrote and this run handed the model — so they are merged at
 * the call site, exactly as `BackedPropertyValues::of()` merges the two value sources for the same
 * reason.
 */
final class PassagePropertyExemptionTest extends TestCase
{
    public function testAPropertyWordFromAGivenPassageIsNotFlagged(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $unbacked = $renderer->unbackedPropertiesInProse(
            'Helmets that have been worn and tyres that have been mounted on a rim cannot be returned.',
            self::facets(),
            givenShopProse: ['Tyres which have been mounted on a rim are excluded from the voluntary return.'],
        );

        self::assertSame([], $unbacked);
    }

    /** The check still catches an invention: no card, no document, no disclosure. */
    public function testAPropertyWordNoDocumentContainsIsStillFlagged(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $unbacked = $renderer->unbackedPropertiesInProse(
            'This one is made of Merino.',
            self::facets(),
            givenShopProse: ['Returns are free within 30 days.'],
        );

        self::assertSame(['Merino'], $unbacked);
    }

    private static function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Material', FacetType::Terms, ['Merino', 'Nylon']),
            new Facet('properties.Mounting', FacetType::Terms, ['Rim', 'Disc']),
        ]);
    }
}
