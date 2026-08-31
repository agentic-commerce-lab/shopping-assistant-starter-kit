<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\DescriptionExcerpt;

/**
 * The shop's own words about a product, cut down to something a tool result can carry.
 *
 * **Markup is stripped, and that is not cosmetic.** Shopware stores `product.description` as HTML,
 * written through a rich-text editor or an import. Handing it over raw would put `<p>` and `&nbsp;`
 * into the model's context — which the system prompt explicitly forbids the model from *emitting*,
 * and which costs tokens for nothing — and it would widen the injection surface from prose to markup.
 *
 * **The character cap is a token measure, not a security measure**, and the distinction is worth
 * keeping straight: truncating an injected instruction leaves an injected instruction. What stops
 * `fx-017` is the prompt rule and the price audit, never this class.
 */
final class DescriptionExcerptTest extends TestCase
{
    public function testAShortDescriptionSurvivesWhole(): void
    {
        self::assertSame(
            'A light full-finger glove for shoulder-season riding.',
            DescriptionExcerpt::of('A light full-finger glove for shoulder-season riding.'),
        );
    }

    public function testHtmlIsReducedToTheWordsInsideIt(): void
    {
        self::assertSame(
            'Deeper coverage at the back and larger vents.',
            DescriptionExcerpt::of('<p>Deeper coverage at the <strong>back</strong> and larger vents.</p>'),
        );
    }

    public function testEntitiesBecomeTheCharactersTheyStandFor(): void
    {
        self::assertSame(
            'Cork-backed tape — 2 m & plugs.',
            DescriptionExcerpt::of('Cork-backed tape &mdash; 2&nbsp;m &amp; plugs.'),
        );
    }

    /**
     * Newlines and runs of space collapse. A description pasted out of a spreadsheet routinely carries
     * both, and they change nothing about the meaning while costing tokens and making the trace hard
     * to read.
     */
    public function testWhitespaceCollapsesToSingleSpaces(): void
    {
        self::assertSame('One two three.', DescriptionExcerpt::of("One\n\n  two\tthree."));
    }

    public function testNothingToSayYieldsAnEmptyString(): void
    {
        self::assertSame('', DescriptionExcerpt::of(null));
        self::assertSame('', DescriptionExcerpt::of('   '));
        self::assertSame('', DescriptionExcerpt::of('<p>&nbsp;</p>'));
    }

    /**
     * Cut at a sentence end rather than mid-thought. A description that stops at "the retention system
     * is" reads as a defect and invites the model to complete the sentence itself.
     */
    public function testALongDescriptionIsCutAtASentenceBoundary(): void
    {
        $long =
            'In-mould trail helmet with an extended rear shell that reaches lower at the back. '
            . 'Twenty-two vents keep it cool on the kind of long fireroad climb that has no shade. '
            . 'The dial-fit retention system adjusts with one hand while riding, even in gloves. '
            . 'Supplied with a spare set of pads, a visor screw and a small hex key for the visor.';

        // Long enough to be cut, or this asserts nothing.
        self::assertGreaterThan(DescriptionExcerpt::MAX_CHARS, \strlen($long));

        $excerpt = DescriptionExcerpt::of($long);

        self::assertStringEndsWith('.', $excerpt);
        self::assertLessThanOrEqual(DescriptionExcerpt::MAX_CHARS, \strlen($excerpt));
        self::assertStringStartsWith('In-mould trail helmet', $excerpt);
        // The cut lands on a boundary, so no partial sentence survives.
        self::assertStringNotContainsString('Supplied with a spare', $excerpt);
    }

    /**
     * A wall of text with no sentence end inside the budget still has to be cut somewhere, and a word
     * boundary is the least bad place. The ellipsis is what tells the model the text is incomplete —
     * without it, a truncated clause reads as the whole claim.
     */
    public function testTextWithNoSentenceEndIsCutAtAWordBoundaryAndMarked(): void
    {
        $excerpt = DescriptionExcerpt::of(str_repeat('lightweight alloy cage ', 40));

        self::assertLessThanOrEqual(DescriptionExcerpt::MAX_CHARS, \strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
        self::assertStringNotContainsString('  ', $excerpt);
    }
}
