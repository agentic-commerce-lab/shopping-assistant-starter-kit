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

        $nodes = $xml->xpath('//input-field/name');
        self::assertIsArray($nodes);

        $names = [];
        foreach ($nodes as $node) {
            $names[] = (string) $node;
        }

        $required = [
            'llmBaseUrl',
            'llmModel',
            'llmApiKey',
            'agentVoice',
            'excludedCategories',
            'blockedProducts',
            'blockedCategories',
            'enableAddToCart',
            'maxItemQuantity',
            'maxCartValue',
            'killSwitch',
            'dailyRequestCap',
            'maxToolCallsPerTurn',
        ];

        foreach ($required as $key) {
            self::assertContains($key, $names, \sprintf('config.xml is missing "%s".', $key));
        }
    }
}
