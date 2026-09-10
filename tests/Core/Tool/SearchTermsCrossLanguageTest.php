<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The instruction that turns a cross-language search into a two-term one.
 *
 * Nine of the 34 conversations exported 2026-09-09 asked in a language the catalogue is not written
 * in, and the search matched nothing: `Sattel`, `kaciga`, `rower`, `Kopfhörer`. Measured directly
 * against the staging index, the failures are not "German does not work" — `Helm` returns two
 * products, because it is a substring of *Road Helmet Aero*. What fails is a word that does not
 * happen to look like its equivalent.
 *
 * **Both words, never only the translation**, and the reasons are in the description: a shop can
 * carry a product actually named "Sattel"; a translation can be wrong in the expensive direction
 * ("Rock" is a skirt in German and a stone in English); and the shopper's own word costs one entry
 * in a list `search_products` already accepts.
 *
 * The catalogue's language is deliberately **not named** in the prompt. `ReplyLanguage` keeps a
 * closed list of language names precisely because that value lands at the prompt's highest-authority
 * position, so naming a channel's language would either widen that list or call a French catalogue
 * "English". The vocabulary block already in the model's context is in the catalogue's language, and
 * the instruction points at it instead.
 */
#[CoversClass(SearchProductsTool::class)]
final class SearchTermsCrossLanguageTest extends TestCase
{
    private static function description(): string
    {
        $attributes = (new \ReflectionClass(SearchProductsTool::class))->getAttributes(AsTool::class);

        self::assertNotSame([], $attributes);

        return (string) ($attributes[0]->getArguments()['description'] ?? '');
    }

    public function testTheDescriptionAsksForBothWordsInOneCall(): void
    {
        $description = self::description();

        self::assertStringContainsString('Pass BOTH', $description);
        self::assertStringContainsString('two entries in "terms" of one call', $description);
    }

    /**
     * The instruction must not read as "translate the term", because replacing loses a product the
     * catalogue really does name in the shopper's language.
     */
    public function testItForbidsSendingOnlyTheTranslation(): void
    {
        self::assertStringContainsString('Never only your translation', self::description());
    }

    /**
     * It points at the vocabulary rather than naming a language, for the reason above.
     */
    public function testItNamesNoLanguage(): void
    {
        $description = self::description();

        self::assertStringContainsString('catalogue vocabulary in your context', $description);
        self::assertStringNotContainsString('written in English', $description);
    }
}
