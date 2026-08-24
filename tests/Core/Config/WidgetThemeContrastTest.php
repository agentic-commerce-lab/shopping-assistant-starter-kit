<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\WidgetTheme;

/**
 * The colours {@see WidgetTheme} *derives*, as opposed to the ones it validates.
 *
 * Split from {@see WidgetThemeTest} for the reason `mago`'s method cap exists: validation and colour
 * maths are two subjects, and a class covering both covers neither clearly. These are also the tests
 * that caught a real defect — the first implementation used the familiar 0.179 luminance pivot, which
 * is the crossover for white against *black*, and put dark text on Shopware's own accent blue.
 */
final class WidgetThemeContrastTest extends TestCase
{
    public function testAPalePrimaryGetsDarkForeground(): void
    {
        // The reason there is no third config field: a merchant picking a pale brand colour would
        // otherwise ship white-on-pale. Contrast is correctness, not preference.
        self::assertSame('#00153e', WidgetTheme::of('#ffe066', '', 'icon')->onPrimary);
    }

    public function testADarkPrimaryGetsWhiteForeground(): void
    {
        self::assertSame('#ffffff', WidgetTheme::of('#0870ff', '', 'icon')->onPrimary);
    }

    public function testAnUnsetPrimaryLeavesTheForegroundToTheStylesheet(): void
    {
        // Nothing to compute against, so nothing is claimed: both properties stay empty and the
        // stylesheet's own pairing applies.
        $theme = WidgetTheme::of('', '', 'icon');

        self::assertSame('', $theme->primary);
        self::assertSame('', $theme->onPrimary);
    }

    public function testAPrimaryAlsoYieldsADarkerToneForTextAndHoverStates(): void
    {
        // Found by looking at the panel: overriding only the primary left the suggestion chips and the
        // card links Shopware blue while every surface had gone purple. `$swag-assistant-accent-dark`
        // is dual-purpose — a creature gradient stop *and* the UI's text/hover colour — so a brand
        // override that ignores it is half-applied, which is the "one accent" rule failing.
        $theme = WidgetTheme::of('#7a3cff', '', 'icon');

        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $theme->primaryDark);
        self::assertNotSame($theme->primary, $theme->primaryDark);
    }

    public function testTheDarkerToneIsDarkerThanThePrimary(): void
    {
        // Darker, not merely different: it is used for text on light surfaces, where a lighter tone
        // would drop below the contrast floor.
        $theme = WidgetTheme::of('#7a3cff', '', 'icon');

        self::assertLessThan(self::channelSum($theme->primary), self::channelSum($theme->primaryDark));
    }

    public function testAPrimaryYieldsTheFullRampTheCharacterNeeds(): void
    {
        // The creature's surface is a four-stop shaded sphere. Branding it means deriving the whole
        // ramp from one colour, not dropping one flat colour into a gradient built for another.
        $theme = WidgetTheme::of('#7a3cff', '', 'creature');

        foreach ([$theme->primaryLight, $theme->primaryDark, $theme->primaryAbyss] as $tone) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $tone);
        }
    }

    public function testTheRampRunsLightToDarkInOrder(): void
    {
        // A sphere lit from the top-left needs its stops monotonic. Out of order it reads as a
        // pattern rather than as a lit object.
        $theme = WidgetTheme::of('#7a3cff', '', 'creature');

        self::assertGreaterThan(self::channelSum($theme->primary), self::channelSum($theme->primaryLight));
        self::assertLessThan(self::channelSum($theme->primary), self::channelSum($theme->primaryDark));
        self::assertLessThan(self::channelSum($theme->primaryDark), self::channelSum($theme->primaryAbyss));
    }

    public function testNoPrimaryMeansNoRamp(): void
    {
        $theme = WidgetTheme::of('', '', 'creature');

        self::assertSame('', $theme->primaryLight);
        self::assertSame('', $theme->primaryAbyss);
    }

    /**
     * The three channels added up — a crude brightness proxy, which is all "is it darker" needs.
     */
    private static function channelSum(string $hex): int
    {
        $sum = 0;

        foreach ([1, 3, 5] as $offset) {
            $sum += (int) hexdec(substr($hex, $offset, 2));
        }

        return $sum;
    }

    public function testNoPrimaryMeansNoDarkerTone(): void
    {
        self::assertSame('', WidgetTheme::of('', '', 'icon')->primaryDark);
    }
}
