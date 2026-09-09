<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\ContinuedProductNames;
use Swag\AssistantStarterKit\Core\Grounding\ProseProductNames;

/**
 * The reply that rendered three real helmets as a mirror, a visor and a lamp.
 *
 * Measured through the staging endpoint 2026-09-09: *"I would recommend the **Road Helmet Aero
 * Mirror**… **Gravel Helmet Visor**… **Kids Helmet Light**"*, with the cards for Road Helmet Aero,
 * Gravel Helmet and Kids Helmet underneath — each with a real price and a real add button.
 * `validate` reported `inventedProductIds: []` and was right: the ids were real. The boundary is
 * drawn around ids and the shopper reads names.
 */
#[CoversClass(ContinuedProductNames::class)]
#[CoversClass(ProseProductNames::class)]
final class ContinuedProductNamesTest extends TestCase
{
    /** @var array<string, string> */
    private const NAMES = [
        'h1' => 'Road Helmet Aero',
        'h2' => 'Gravel Helmet',
        'h3' => 'Kids Helmet',
    ];

    public function testAContinuedNameRendersNoCard(): void
    {
        $prose = 'I would recommend the Road Helmet Aero Mirror. It mounts on your helmet.';

        self::assertSame([], ProseProductNames::idsNamedIn($prose, self::NAMES));
    }

    /**
     * The whole reply from that turn: three invented products, three real names inside them, and
     * before this class three cards.
     */
    public function testTheMeasuredReplyRendersNothing(): void
    {
        $prose =
            'For an accessory to pair with a bike helmet, I would recommend the Road Helmet Aero '
            . 'Mirror. Here are two other options: Gravel Helmet Visor shields your eyes, and the '
            . 'Kids Helmet Light clips on for visibility.';

        self::assertSame([], ProseProductNames::idsNamedIn($prose, self::NAMES));
    }

    public function testAnOrdinaryMentionStillRendersItsCard(): void
    {
        $prose = 'The Gravel Helmet comes in M and L, and it is the one rated for trails.';

        self::assertSame(['h2'], ProseProductNames::idsNamedIn($prose, self::NAMES));
    }

    /**
     * Punctuation ends the name, so the next sentence starting with a capital must not suppress it.
     */
    public function testASentenceBoundaryIsNotAContinuation(): void
    {
        $prose = 'I would take the Gravel Helmet. Alternatives are below.';

        self::assertSame(['h2'], ProseProductNames::idsNamedIn($prose, self::NAMES));
    }

    public function testANameAtTheEndOfTheProseStillCounts(): void
    {
        self::assertSame(['h3'], ProseProductNames::idsNamedIn('That would be the Kids Helmet', self::NAMES));
    }

    /**
     * Named once as itself and once inside an invention: the card still renders, because every
     * occurrence is examined rather than only the first.
     */
    public function testANameUsedBothWaysStillRendersOnce(): void
    {
        $prose = 'The Gravel Helmet Visor is one option. The Gravel Helmet itself is the other.';

        self::assertSame(['h2'], ProseProductNames::idsNamedIn($prose, self::NAMES));
    }

    /**
     * The false negative, stated rather than hidden: a bare option after a name loses its card. The
     * trade is asymmetric — a missing card is a presentation loss, a real card beneath an invented
     * name is the central guarantee failing where a shopper can see it.
     */
    public function testABareOptionAfterANameCostsItsCard(): void
    {
        self::assertSame([], ProseProductNames::idsNamedIn('the Gravel Helmet Black', self::NAMES));
    }

    public function testItReportsThePhrasesForTheTrace(): void
    {
        $prose = 'the Road Helmet Aero Mirror and the Kids Helmet Light, plus the Gravel Helmet itself';

        self::assertSame(
            ['Road Helmet Aero Mirror', 'Kids Helmet Light'],
            ContinuedProductNames::in($prose, self::NAMES),
        );
    }

    public function testACleanReplyReportsNothing(): void
    {
        self::assertSame([], ContinuedProductNames::in('The Gravel Helmet is the trail one.', self::NAMES));
    }
}
