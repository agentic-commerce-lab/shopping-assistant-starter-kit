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
}
