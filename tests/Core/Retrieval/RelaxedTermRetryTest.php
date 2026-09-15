<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Retrieval\RelaxedTermRetry;

/**
 * The relaxation itself, separately from the retry that uses it, because this is where the judgement
 * lives: which words get shortened, and when shortening is not worth a second read.
 *
 * The measured case is `gloves` in a shop that sells the Commuter Glove. The storefront's own search
 * box has the same gap — `/search?search=glove` returned 3 products on the live shop and
 * `?search=gloves` returned none — so this is not the assistant inventing a problem.
 */
final class RelaxedTermRetryTest extends TestCase
{
    public function testALongWordLosesItsLastCharacter(): void
    {
        self::assertSame('glove', RelaxedTermRetry::relax('gloves'));
        self::assertSame('light', RelaxedTermRetry::relax('lights'));
    }

    /**
     * Not an English plural rule — a prefix. That is the whole reason it also covers a language
     * whose plural is not an "s".
     */
    public function testItIsAPrefixRatherThanAPluralRule(): void
    {
        self::assertSame('Handschuh', RelaxedTermRetry::relax('Handschuhe'));
        self::assertSame('Fahrradhelm', RelaxedTermRetry::relax('Fahrradhelme'));
    }

    /** Every long word in a phrase relaxes; the short ones are left alone. */
    public function testEachLongWordRelaxesAndShortOnesDoNot(): void
    {
        self::assertSame('bottle cage', RelaxedTermRetry::relax('bottles cages'));
        self::assertSame('red glove', RelaxedTermRetry::relax('red gloves'));
    }

    /**
     * Null rather than an unchanged string, so a caller can tell "not worth attempting" from
     * "attempted and empty" — and so a second identical gateway read never happens.
     */
    public function testNothingToRelaxReturnsNull(): void
    {
        // Every word already under the threshold: shortening "cap" to "ca" would prefix half a
        // catalogue rather than widen a search.
        self::assertNull(RelaxedTermRetry::relax('cap'));
        self::assertNull(RelaxedTermRetry::relax('red cap'));
        self::assertNull(RelaxedTermRetry::relax(''));
        self::assertNull(RelaxedTermRetry::relax('   '));
        self::assertNull(RelaxedTermRetry::relax(null));
    }

    /** Five characters is the floor, so exactly five relaxes and four does not. */
    public function testTheThresholdIsFiveCharacters(): void
    {
        self::assertSame('shoe', RelaxedTermRetry::relax('shoes'));
        self::assertNull(RelaxedTermRetry::relax('hats'));
    }

    /** Multibyte input must not be cut mid-character. */
    public function testAMultibyteWordIsShortenedByOneCharacterNotOneByte(): void
    {
        self::assertSame('Größ', RelaxedTermRetry::relax('Größe'));
    }

    /**
     * The German case the one-character step cannot reach, measured against the parts catalogue
     * on 2026-09-15: the shop finds nothing for either plural, and only the two-character form
     * reaches the singular that does match.
     *
     * ```
     * Anlassermotoren -> Anlassermotore   0 hits   -> Anlassermotor   10 hits
     * Kettenführungen -> Kettenführunge   0 hits   -> Kettenführung     6 hits
     * ```
     */
    public function testTwoCharactersReachTheGermanEnPlural(): void
    {
        self::assertSame('Anlassermotore', RelaxedTermRetry::relax('Anlassermotoren'));
        self::assertSame('Anlassermotor', RelaxedTermRetry::relax('Anlassermotoren', 2));

        self::assertSame('Kettenführung', RelaxedTermRetry::relax('Kettenführungen', 2));
    }

    /**
     * Two characters must not create a stub one character would have refused to make. A five-letter
     * word is shortened by one and left alone by two, so the floor rises with the cut.
     */
    public function testTheFloorRisesWithTheNumberOfCharacters(): void
    {
        self::assertSame('Hemd', RelaxedTermRetry::relax('Hemds'));
        self::assertNull(RelaxedTermRetry::relax('Hemds', 2));

        self::assertSame('Hemd', RelaxedTermRetry::relax('Hemden', 2));
    }

    /**
     * Still a prefix rule and not a German one: two characters come off whatever the word ends in,
     * and a word too short to survive the cut is carried through untouched.
     */
    public function testTwoCharactersStaysAPrefixRule(): void
    {
        self::assertSame('glov', RelaxedTermRetry::relax('gloves', 2));

        // Every word too short to survive the cut means there is nothing to retry, exactly as one
        // character already reports for a term of short words.
        self::assertNull(RelaxedTermRetry::relax('brake pads', 2));

        // Mixed: the long word relaxes, the short ones are carried through untouched.
        self::assertSame('rot Hemden Hemd', RelaxedTermRetry::relax('rot Hemdenen Hemd', 2));
    }
}
