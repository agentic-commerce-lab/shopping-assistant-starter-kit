<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\ProseProductNames;

/**
 * Which retrieved products a reply actually names.
 *
 * **Why this exists.** `GroundingOutputProcessor` decided what to render by looking for product *ids*
 * in the reply — and the prompt forbids the model from writing ids, so it never found any and always
 * fell back to "render the whole last tool batch". That was invisible while the model listed
 * everything it found. Once the prompt asked for one recommendation and at most two alternatives, the
 * reply named three products and the widget rendered six. Measured live 2026-09-01: asked for helmets,
 * the prose named Trail Helmet, Gravel Helmet and Road Helmet Aero while Kids Helmet, Commuter Helmet
 * and Helmet Rain Cover appeared as cards nobody had mentioned.
 *
 * **Longest name first, then mask.** This catalogue contains a product called `Chain` alongside
 * `Wet Chain Lube 100ml`, `Chain Wear Indicator` and `Chain Lock 90cm`. Matching short names naively
 * would render the chain whenever the model recommended chain lube — the exact class of wrong card
 * this is meant to remove.
 */
final class ProseProductNamesTest extends TestCase
{
    public function testAProductNamedInTheProseIsFound(): void
    {
        $ids = ProseProductNames::idsNamedIn('For trails I would take the Trail Helmet.', [
            'id-trail' => 'Trail Helmet',
            'id-road' => 'Road Helmet Aero',
        ]);

        self::assertSame(['id-trail'], $ids);
    }

    public function testAProductTheProseNeverMentionsIsNotFound(): void
    {
        $ids = ProseProductNames::idsNamedIn('For trails I would take the Trail Helmet.', ['id-kids' => 'Kids Helmet']);

        self::assertSame([], $ids);
    }

    /**
     * The `Chain` case. "Wet Chain Lube 100ml" contains the word "Chain", and a short-name match would
     * put a chain in front of a shopper who asked about lubricant.
     */
    public function testAShortNameInsideALongerProductNameDoesNotMatch(): void
    {
        $ids = ProseProductNames::idsNamedIn('I recommend the Wet Chain Lube 100ml for winter.', [
            'id-chain' => 'Chain',
            'id-lube' => 'Wet Chain Lube 100ml',
        ]);

        self::assertSame(['id-lube'], $ids);
    }

    /**
     * And a short name standing on its own still matches, or the masking would have thrown away real
     * mentions along with the spurious ones.
     */
    public function testAShortNameOnItsOwnStillMatches(): void
    {
        $ids = ProseProductNames::idsNamedIn('The Chain is nickel-plated. The Wet Chain Lube 100ml suits rain.', [
            'id-chain' => 'Chain',
            'id-lube' => 'Wet Chain Lube 100ml',
        ]);

        self::assertEqualsCanonicalizing(['id-chain', 'id-lube'], $ids);
    }

    public function testMatchingIgnoresCase(): void
    {
        $ids = ProseProductNames::idsNamedIn('i would take the trail helmet', ['id-trail' => 'Trail Helmet']);

        self::assertSame(['id-trail'], $ids);
    }

    /**
     * Two products, both named, both rendered — the ordinary recommendation-plus-alternative shape.
     */
    public function testEveryNamedProductIsReturned(): void
    {
        $ids = ProseProductNames::idsNamedIn('Take the Trail Helmet. The Gravel Helmet is an alternative.', [
            'id-trail' => 'Trail Helmet',
            'id-gravel' => 'Gravel Helmet',
            'id-kids' => 'Kids Helmet',
        ]);

        self::assertEqualsCanonicalizing(['id-trail', 'id-gravel'], $ids);
    }

    /**
     * A name that is blank or whitespace cannot be searched for — every text "contains" the empty
     * string, so it would match everything.
     */
    public function testABlankNameIsIgnoredRatherThanMatchingEverything(): void
    {
        $ids = ProseProductNames::idsNamedIn('anything at all', ['id-blank' => '   ', 'id-empty' => '']);

        self::assertSame([], $ids);
    }

    public function testNoNamesAndNoProseYieldNothing(): void
    {
        self::assertSame([], ProseProductNames::idsNamedIn('', ['id' => 'Trail Helmet']));
        self::assertSame([], ProseProductNames::idsNamedIn('some prose', []));
    }

    /**
     * A name shared by several retrieved rows yields **one** id — the first the retrieval offered.
     *
     * **This is what makes duplicate-looking cards go away, and it is deliberate rather than a side
     * effect.** A variant family shares its parent's name: measured on the live shop, "Road Helmet
     * Aero" exists as one parent and six variants, and a search that returned three of them rendered
     * three cards with identical titles. `RedundantParentFilter` already drops a superseded parent, so
     * these were genuinely different products — but a reply that says "Road Helmet Aero: designed for
     * road riding" is naming a product, not a size, and three cards under one sentence read as a
     * duplicate.
     *
     * First-offered rather than "the parent" or "the cheapest": the retrieval already ranked them, and
     * re-ranking here would put a second opinion about relevance in a class that only reads text.
     * PHP's sort is stable, so equal-length names keep the index's order.
     */
    public function testANameSharedByAVariantFamilyYieldsOnlyTheFirstId(): void
    {
        $ids = ProseProductNames::idsNamedIn('The Road Helmet Aero is built for road riding.', [
            'id-first' => 'Road Helmet Aero',
            'id-second' => 'Road Helmet Aero',
            'id-third' => 'Road Helmet Aero',
        ]);

        self::assertSame(['id-first'], $ids);
    }
}
