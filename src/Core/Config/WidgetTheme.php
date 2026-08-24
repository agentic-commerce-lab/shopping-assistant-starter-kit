<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

/**
 * The merchant's two brand colours, validated, plus the foreground the primary needs.
 *
 * **These are CSS custom properties, not SCSS variables, and that is forced rather than chosen.** The
 * widget's tokens live in `_tokens.scss` and are resolved when Shopware compiles the theme; this
 * config is read per request, per sales channel. One shop with two storefronts and two brand colours
 * is a legitimate setup that a compiled stylesheet cannot serve. So these travel to the browser as
 * properties on `.swag-assistant`, and every rule reads
 * `var(--swag-assistant-primary, #{$swag-assistant-accent})` — the token stays the default, the
 * property is the override, and no hardcoded colour is introduced anywhere.
 *
 * **Empty is a real value.** A colour that fails validation, or was never set, yields `''`, the
 * property is not emitted at all, and the `var()` fallback keeps the shipped token. Correcting a bad
 * value would be guessing at what the merchant meant.
 */
final readonly class WidgetTheme
{
    public const STYLE_ICON = 'icon';

    public const STYLE_CREATURE = 'creature';

    /**
     * Night Blue, matching `$swag-assistant-text`. A literal here because this is the one place a
     * colour has to exist in PHP: a stylesheet cannot compute contrast.
     */
    private const DARK_FOREGROUND = '#00153e';

    private const LIGHT_FOREGROUND = '#ffffff';

    // @mago-expect lint:excessive-parameter-list
    // A flat set of derived colours, and flat is the point: each one is a distinct CSS custom
    // property the template emits by name. Grouping the ramp behind a sub-object would add a level of
    // indirection to `theme.primaryLight` for no reduction in what a reader has to hold — the same
    // argument `ProductCard` makes for its allowlist.
    private function __construct(
        public string $primary,
        public string $primaryLight,
        public string $primaryDark,
        public string $primaryAbyss,
        public string $secondary,
        public string $onPrimary,
        public string $entryPointStyle,
    ) {}

    public static function of(string $primary, string $secondary, string $entryPointStyle): self
    {
        $safePrimary = self::hexOrEmpty($primary);

        return new self(
            $safePrimary,
            // The character's surface is a four-stop shaded sphere, so branding it means deriving the
            // whole ramp from one colour rather than dropping one flat colour into a gradient built
            // for another. All three are derived, never configured: they are the same brand colour at
            // different depths, and asking a merchant for four values is asking them to keep four in
            // step.
            $safePrimary === '' ? '' : self::mixToward($safePrimary, self::LIGHT_FOREGROUND, 0.28),
            $safePrimary === '' ? '' : self::mixToward($safePrimary, '#000000', 0.18),
            $safePrimary === '' ? '' : self::mixToward($safePrimary, self::DARK_FOREGROUND, 0.72),
            self::hexOrEmpty($secondary),
            // Nothing to compute against means nothing claimed: the stylesheet's own pairing applies.
            $safePrimary === '' ? '' : self::readableOn($safePrimary),
            $entryPointStyle === self::STYLE_CREATURE ? self::STYLE_CREATURE : self::STYLE_ICON,
        );
    }

    /**
     * A strict `#rrggbb`, or nothing.
     *
     * The allowlist is deliberately narrower than CSS accepts: no `var()`, no `rgb()`, no named
     * colours, no three-digit shorthand. Everything else CSS would happily parse is also a way to
     * smuggle a second declaration into the `style` attribute this ends up in, and config access is
     * not permission to do that — the same reasoning as
     * {@see SystemConfigAssistantConfig::safeUrl()}.
     */
    private static function hexOrEmpty(string $value): string
    {
        $trimmed = strtolower(trim($value));

        return preg_match('/^#[0-9a-f]{6}$/', $trimmed) === 1 ? $trimmed : '';
    }

    /**
     * Whichever of the two foregrounds actually contrasts better against this background.
     *
     * There is no config field for this on purpose. A merchant picking a pale brand colour would
     * otherwise ship a white icon on a pale disc and an unreadable entry point, and asking them to
     * choose the foreground as well is how that ends up wrong.
     *
     * **Both ratios are computed rather than compared against a luminance pivot.** The familiar 0.179
     * threshold is the crossover for white against *black*, and the dark option here is Night Blue,
     * which is not black. Using the pivot put dark text on Shopware's own accent blue — 3.69:1, where
     * white gives 4.40:1 — so the shipped default would have shipped worse than it does today. Caught
     * by the two expectations in `WidgetThemeTest`.
     */
    private static function readableOn(string $hex): string
    {
        $background = self::luminance($hex);

        $onLight = self::contrast($background, self::luminance(self::DARK_FOREGROUND));
        $onDark = self::contrast($background, self::luminance(self::LIGHT_FOREGROUND));

        return $onLight > $onDark ? self::DARK_FOREGROUND : self::LIGHT_FOREGROUND;
    }

    /**
     * WCAG relative luminance. The sRGB linearisation matters: perceived lightness is not the mean of
     * the channels, and a naive average picks white on mid-yellow.
     */
    private static function luminance(string $hex): float
    {
        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $srgb = (int) hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $srgb <= 0.03928 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
        }

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    /**
     * One colour moved a given fraction of the way toward another.
     *
     * Computed here rather than in CSS. `color-mix()` is not universally supported enough to be the
     * only path to a brand's hover state, and SCSS cannot do colour maths on a custom property, so
     * the one place that can derive these is PHP.
     *
     * The three fractions are chosen, not incidental: **0.18 toward black** for the pressed and
     * text tone — enough to read as deeper than the base without turning a saturated hue muddy;
     * **0.28 toward white** for the lit face of the sphere; and **0.72 toward Night Blue** for its
     * shadowed edge, which is what the shipped `$swag-assistant-abyss` already is relative to the
     * shipped accent.
     *
     * `$swag-assistant-accent-dark` needed this first for a reason worth keeping: it is dual-purpose
     * in the stylesheet — a stop in the creature's gradient *and* the colour of suggestion-chip text,
     * card links and pressed states. Overriding only the primary left half the widget Shopware blue.
     */
    private static function mixToward(string $hex, string $target, float $amount): string
    {
        $out = '#';

        foreach ([1, 3, 5] as $offset) {
            $from = (int) hexdec(substr($hex, $offset, 2));
            $to = (int) hexdec(substr($target, $offset, 2));
            $mixed = (int) round($from + (($to - $from) * $amount));

            $out .= str_pad(dechex(max(0, min(255, $mixed))), 2, '0', \STR_PAD_LEFT);
        }

        return $out;
    }

    private static function contrast(float $first, float $second): float
    {
        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }
}
