<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;

/**
 * What the configuration form has to tell a merchant about model names, because getting either of
 * them wrong is a silent failure.
 *
 * ## The two traps
 *
 * **A model id is spelled the way the merchant's own provider spells it, and this plugin passes it
 * through untouched.** {@see \Swag\AssistantStarterKit\Core\Llm\PlatformFactory} hands the string to
 * the provider and {@see \Swag\AssistantStarterKit\Core\Llm\EmbeddingsOnlyModelCatalog} accepts any
 * name at all, deliberately — the provider is the authority on what exists. A gateway fronting
 * several vendors prefixes them (`openai/text-embedding-3-small`); a vendor's own API does not
 * (`text-embedding-3-small`). The form used to state one convention in its placeholders and the
 * other in its help text, so a merchant who followed the `https://api.openai.com` placeholder and
 * then copied a model out of the help text got "model not found".
 *
 * **The recall floor is calibrated for one embedding model.** This is the sharper trap, and it is
 * measured in this repository: `docs/superpowers/reports/2026-08-25-shopinfo-threshold.md` ran the
 * statutory German revocation notice against three models, and
 * {@see SearchShopInfoTool::RECALL_MIN_SCORE} is 0.40 because `bge-m3`'s lowest *answerable* score
 * was 0.4421.
 *
 * | Embedding model | Lowest score for a question the document ANSWERS |
 * |---|---|
 * | `baai/bge-m3` | 0.4421 — above the floor |
 * | `text-embedding-3-small` | **0.3622** — below the floor |
 * | `text-embedding-3-large` | **0.3566**, and 0.3713 — below the floor |
 *
 * So a shop that switches to either OpenAI model keeps a floor tuned for a different one, and
 * questions its documents genuinely answer are discarded before the model ever sees the passage.
 * Nothing warns about it and nothing can infer it — the merchant has to be told.
 */
final class ConfigModelNamingTest extends TestCase
{
    /** @return array<string, string> help text keyed by field name */
    private static function helpTexts(): array
    {
        $xml = simplexml_load_file(__DIR__ . '/../src/Resources/config/config.xml');
        self::assertNotFalse($xml);

        $fields = $xml->xpath('//input-field[name]');
        self::assertIsArray($fields);

        $texts = [];

        foreach ($fields as $field) {
            $texts[(string) $field->name] = (string) ($field->helpText ?? '');
        }

        return $texts;
    }

    /**
     * Both conventions, on both model fields. A merchant cannot be expected to know that "the model
     * id" means something different depending on the URL in the field above it.
     */
    public function testBothModelFieldsExplainThatTheSpellingIsTheProvidersOwn(): void
    {
        $texts = self::helpTexts();

        foreach (['llmModel', 'embeddingModel'] as $field) {
            self::assertArrayHasKey($field, $texts);
            self::assertStringContainsString('prefix', $texts[$field], $field . ' must warn about the prefix');
        }
    }

    /**
     * The unprefixed OpenAI form has to appear literally, because the field's own sibling
     * placeholder points at `https://api.openai.com` and the help text used to name only the
     * gateway form.
     */
    public function testTheEmbeddingHelpTextNamesTheUnprefixedOpenAiForm(): void
    {
        $help = self::helpTexts()['embeddingModel'] ?? '';

        self::assertStringContainsString('text-embedding-3-small', $help);
        self::assertStringNotContainsString('openai/text-embedding-3-small (1536) and', $help);
    }

    /**
     * The measured consequence, not a vague "may differ". See the class docblock for the table this
     * number comes from.
     */
    public function testTheEmbeddingHelpTextWarnsThatTheRecallFloorIsCalibratedForBgeM3(): void
    {
        $help = self::helpTexts()['embeddingModel'] ?? '';

        self::assertStringContainsString('bge-m3', $help);
        self::assertStringContainsString((string) SearchShopInfoTool::RECALL_MIN_SCORE, $help);
        self::assertStringContainsString('0.3622', $help, 'the measured score that falls below the floor');
    }

    /** A merchant reading the base URL field should see both kinds of endpoint named. */
    public function testTheBaseUrlHelpTextNamesBothKindsOfProvider(): void
    {
        $help = self::helpTexts()['llmBaseUrl'] ?? '';

        self::assertStringContainsString('https://api.openai.com', $help);
        self::assertStringContainsString('https://openrouter.ai/api', $help);
    }
}
