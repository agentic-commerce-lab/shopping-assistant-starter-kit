<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\WidgetTheme;

/**
 * A merchant's colour lands in a `style` attribute served to every shopper, so it is validated where
 * it is read — the same argument as `SystemConfigAssistantConfig::safeUrl()`. Config access is not
 * permission to inject CSS.
 */
final class WidgetThemeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'css injection' => ['#fff; background: url(//evil.example/x)'];
        yield 'a css function' => ['var(--x)'];
        yield 'a named colour' => ['rebeccapurple'];
        yield 'three-digit hex' => ['#fff'];
        yield 'no hash' => ['0870ff'];
        yield 'too long' => ['#0870ff00'];
        yield 'not hex at all' => ['#zzzzzz'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejected')]
    public function testAnythingButASixDigitHexIsDropped(string $stored): void
    {
        // Dropped to empty rather than corrected, so the `var()` fallback in the stylesheet wins and
        // the widget keeps the shipped token.
        self::assertSame('', WidgetTheme::of($stored, '', 'icon')->primary);
    }

    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'lowercase' => ['#0870ff', '#0870ff'];
        yield 'uppercase is normalised' => ['#0870FF', '#0870ff'];
        yield 'surrounding whitespace' => ['  #0870ff ', '#0870ff'];
    }

    #[DataProvider('accepted')]
    public function testAValidHexSurvivesNormalised(string $stored, string $expected): void
    {
        self::assertSame($expected, WidgetTheme::of($stored, '', 'icon')->primary);
    }

    public function testAnUnknownEntryPointStyleFallsBackToTheNeutralIcon(): void
    {
        self::assertSame('icon', WidgetTheme::of('', '', 'sparkles')->entryPointStyle);
        self::assertSame('icon', WidgetTheme::of('', '', '')->entryPointStyle);
        self::assertSame('creature', WidgetTheme::of('', '', 'creature')->entryPointStyle);
    }
}
