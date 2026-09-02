<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The strings a shopper reads, offered to the merchant in the plugin's own configuration form.
 *
 * **Why these are snippets and not settings.** `system_config` has no translation layer — one value
 * per key per sales channel — so a shopper-facing sentence held there needs a *field per language*,
 * which is what the greeting used to have (`greeting`, `greetingDe`, `greetingEn`) and what a third
 * language would have cost again. Every other line this widget shows is already a snippet, resolved
 * per snippet set like the rest of the storefront. `sw-snippet-field` puts that same snippet in the
 * configuration form with a language switch on it, which was the one argument for keeping these out
 * of the snippet system: a setting a merchant cannot find is a setting that does not exist.
 *
 * Nothing in PHP reads these keys — the storefront renders them through `|trans` — so nothing in PHP
 * would notice a typo. A misspelt `<snippet>` produces a form field that edits a key no template asks
 * for: the merchant rewrites all three chips, saves, and still sees the shipped ones. That is what
 * these tests are for.
 */
final class ConfigSnippetFieldsTest extends TestCase
{
    /**
     * The three prompts a shopper reads before typing anything, offered to the merchant.
     *
     * They ship as snippets rather than as `system_config` values because `sw-snippet-field` is the
     * only mechanism in a plugin config form that edits one string per language — see
     * `swagAssistant.panel.defaultGreeting` for the same reasoning applied to the greeting. The
     * storefront already renders them through `|trans`, so nothing in PHP reads these keys and
     * nothing in PHP would notice a typo: a misspelt `<snippet>` produces a form field that edits a
     * key no template asks for, and a merchant who rewrites all three sees the shipped ones.
     */
    public function testTheGreetingAndChatSuggestionsAreEditableInTheConfigForm(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../src/Resources/config/config.xml');
        self::assertNotFalse($xml);

        $nodes = $xml->xpath('//component[@name="sw-snippet-field"]/snippet');
        self::assertIsArray($nodes);

        $offered = array_map(strval(...), $nodes);

        foreach (['defaultGreeting', 'suggestionOne', 'suggestionTwo', 'suggestionThree'] as $suggestion) {
            self::assertContains(
                'swagAssistant.panel.' . $suggestion,
                $offered,
                \sprintf('config.xml offers no snippet field for "%s".', $suggestion),
            );
        }
    }

    /**
     * Every snippet field names a key the storefront actually ships.
     *
     * `sw-snippet-field` writes into the snippet set whatever key it is given, so a typo is silent
     * in both directions: the form saves happily, and the storefront keeps rendering the shipped
     * default. Checked against the English file, which is the one the storefront falls back to.
     */
    public function testEverySnippetFieldNamesAKeyTheStorefrontShips(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../src/Resources/config/config.xml');
        self::assertNotFalse($xml);

        $nodes = $xml->xpath('//component[@name="sw-snippet-field"]/snippet');
        self::assertIsArray($nodes);
        self::assertNotSame([], $nodes, 'No snippet fields found — this test would assert nothing.');

        // `en-GB`, not `en`: `SnippetFileLoader` reads the locale straight out of the filename
        // (`explode('.')`, second part), so a file named `.en.json` registers under the iso `en`,
        // which is not a Shopware locale. On 6.7 a translator fallback hid that; on 6.6 the
        // storefront rendered raw keys — measured live, see docs/manual.md.
        $raw = file_get_contents(__DIR__ . '/../src/Resources/snippet/swag-assistant.en-GB.json');
        self::assertIsString($raw);

        $snippets = json_decode($raw, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($snippets);

        foreach ($nodes as $node) {
            $key = (string) $node;

            self::assertNotNull(
                self::snippetAt($snippets, $key),
                \sprintf('config.xml offers a field for "%s", which the storefront does not ship.', $key),
            );
        }
    }

    /**
     * @param array<array-key, mixed> $snippets
     */
    private static function snippetAt(array $snippets, string $key): ?string
    {
        $cursor = $snippets;

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return \is_string($cursor) ? $cursor : null;
    }
}
