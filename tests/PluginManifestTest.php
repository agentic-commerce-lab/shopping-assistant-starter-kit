<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin;
use Swag\AssistantStarterKit\SwagAssistantStarterKit;

/**
 * The deliverable of this task is "the plugin installs into a real 6.7 shop", which PHPUnit
 * cannot assert. What it can assert is the manifest coupling whose breakage makes the plugin
 * silently uninstallable: a renamed plugin class, a `shopware-plugin-class` that no longer
 * resolves, or a config key the pipeline reads but the merchant form never offers.
 */
final class PluginManifestTest extends TestCase
{
    /**
     * `json_decode` cannot promise string keys, and claiming it does in a docblock would be
     * exactly the "hide the violation behind a lie" option the standing constraints reject.
     *
     * @return array<array-key, mixed>
     */
    private function composerJson(): array
    {
        $raw = file_get_contents(__DIR__ . '/../composer.json');
        self::assertIsString($raw);

        $decoded = json_decode($raw, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testThePackageDeclaresItselfAShopwarePlugin(): void
    {
        $type = $this->composerJson()['type'] ?? null;

        self::assertSame('shopware-platform-plugin', $type);
    }

    public function testTheDeclaredPluginClassIsTheClassThatExists(): void
    {
        $extra = $this->composerJson()['extra'] ?? null;
        self::assertIsArray($extra);

        $declared = $extra['shopware-plugin-class'] ?? null;

        self::assertSame(SwagAssistantStarterKit::class, $declared);
        self::assertTrue(class_exists(SwagAssistantStarterKit::class));
    }

    public function testThePluginClassExtendsShopwaresPluginBaseClass(): void
    {
        $plugin = new SwagAssistantStarterKit(true, __DIR__ . '/..');

        self::assertInstanceOf(Plugin::class, $plugin);
    }

    public function testEveryConfigKeyTheConfigBridgeReadsIsDeclaredInConfigXml(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../src/Resources/config/config.xml');
        self::assertNotFalse($xml);

        // Both element kinds, because the off switch is rendered by a custom Administration
        // component rather than a plain bool field — and a test that only walked `input-field`
        // would report the most important setting in the form as missing.
        $nodes = $xml->xpath('//input-field/name | //component/name');
        self::assertIsArray($nodes);

        $names = [];
        foreach ($nodes as $node) {
            $names[] = (string) $node;
        }

        $required = [
            'llmBaseUrl',
            'llmModel',
            'llmApiKey',
            'enableShopKnowledge',
            'embeddingModel',
            'autoIndexShopPages',
            'agentVoice',
            'blockedProducts',
            'blockedCategories',
            'enableAddToCart',
            'maxItemQuantity',
            'maxCartValue',
            'assistantEnabled',
            'dailyRequestCap',
            'maxToolCallsPerTurn',
            'requestsPerMinute',
            'enableEscalation',
            'escalationUrl',
            'escalationMessage',
            'logTraces',
            'widgetEnabled',
            'assistantName',
            'greeting',
            'traceRetentionDays',
            'entryPointStyle',
            'primaryColor',
            'secondaryColor',
        ];

        foreach ($required as $key) {
            self::assertContains($key, $names, \sprintf('config.xml is missing "%s".', $key));
        }
    }

    /**
     * The defaults a shop gets before anyone opens the form, pinned against the form itself.
     *
     * These are the numbers the plugin *ships*, and every one of them was chosen by deleting an
     * earlier guess. `maxCartValue: 1000` in an unspecified currency blocked a genuine sale the
     * first time a shop sold one expensive thing; `dailyRequestCap: 500` turned a good day's
     * traffic into a dead assistant by mid-afternoon. Nothing in the runtime notices when one of
     * these drifts back — `SystemConfigAssistantConfig`'s own defaults are separate constants, and
     * a form and a bridge that disagree produce a shop configured by whichever one you read.
     */
    public function testTheShippedDefaultsAreTheOnesTheDocumentationPromises(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../src/Resources/config/config.xml');
        self::assertNotFalse($xml);

        $expected = [
            // 0 is "no limit", not "refuse everything". The assistant's own off switch is what
            // stops it; a numeric field must never be the thing that silently does.
            'maxItemQuantity' => '0',
            'maxCartValue' => '0',
            'dailyRequestCap' => '0',
            // The exceptions, and both are deliberate. The per-shopper window is the only control
            // standing in front of a public unauthenticated endpoint that spends money per call,
            // and the tool-call budget bounds a model that has started looping.
            'requestsPerMinute' => '60',
            'maxToolCallsPerTurn' => '20',
            // On, in the direction the switch is drawn.
            'assistantEnabled' => null,
            'widgetEnabled' => 'true',
            'enableAddToCart' => 'true',
            'enableEscalation' => 'true',
            'logTraces' => 'true',
            'traceRetentionDays' => '30',
        ];

        foreach ($expected as $name => $default) {
            $nodes = $xml->xpath(\sprintf('//*[name="%s"]/defaultValue', $name));
            self::assertIsArray($nodes);

            if ($default === null) {
                // Rendered by a custom component, which carries its own default in the props it
                // declares rather than in the form.
                self::assertSame([], $nodes, \sprintf('"%s" should not declare a defaultValue.', $name));

                continue;
            }

            self::assertCount(1, $nodes, \sprintf('"%s" has no defaultValue in config.xml.', $name));
            self::assertSame($default, (string) $nodes[0], \sprintf('The shipped default for "%s" changed.', $name));
        }
    }
}
