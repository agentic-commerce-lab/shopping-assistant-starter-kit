<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * The escalation settings, in their own class rather than in
 * {@see SystemConfigAssistantConfigTest}: the URL half is a security boundary with its own table of
 * hostile inputs, and `mago`'s method cap is a fair reading of the alternative — a config test class
 * that covers everything covers nothing in particular.
 *
 * `escalationUrl` is the only merchant-entered value in this plugin that reaches an `href` served to
 * every shopper, which is why it is validated where config is read rather than where it is rendered.
 */
final class SystemConfigEscalationTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testTheEscalationTargetReachesTheConfigObject(): void
    {
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'escalationUrl' => '/contact',
            self::PREFIX . 'escalationMessage' => 'Our team can help with orders.',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame('/contact', $config->escalationUrl);
        self::assertSame('Our team can help with orders.', $config->escalationMessage);
    }

    public function testAnUnsetEscalationTargetIsEmptyRatherThanADefaultUrl(): void
    {
        // Empty means "no destination configured", which HandoffPayload renders as no handoff block
        // at all. Inventing a default like /contact would promise a page that may not exist.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertSame('', $config->escalationUrl);
        self::assertSame('', $config->escalationMessage);
    }

    public function testEscalationDefaultsToOnAndAStoredFalseIsHonoured(): void
    {
        // Same mechanism and same reason as `enableAddToCart`: `getBool()` cannot tell an absent key
        // from a stored `false`, and the default here is **on**, so an absent key must not read as
        // "the merchant switched escalation off" — nor may a default switch it back on for a
        // merchant who deliberately disabled it. Hence `boolOr`, not `getBool`.
        $absent = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);
        self::assertTrue($absent->enableEscalation);

        $stored = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'enableEscalation' => false,
        ])))->forSalesChannel(self::CHANNEL);
        self::assertFalse($stored->enableEscalation);
    }

    public function testEscalationSwitchedOffFromTheCliIsNotReadAsOn(): void
    {
        // `bin/console system:config:set` stores every value as the string "false", and
        // `(bool) "false"` is true. That exact bug shipped once already for `killSwitch`, so the
        // guardrail that must fail *closed* gets its own assertion.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'enableEscalation' => 'false',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertFalse($config->enableEscalation);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileUrls(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'uppercased javascript scheme' => ['JavaScript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'protocol relative, escapes the host' => ['//evil.example/contact'];
        yield 'not a url at all' => ['contact'];
    }

    #[DataProvider('hostileUrls')]
    public function testAnUnsafeEscalationUrlIsDroppedRatherThanRendered(string $stored): void
    {
        // This value lands in an href served to every shopper. Config access is not permission to
        // run JavaScript in the storefront, so the scheme is validated where config is read — once,
        // rather than at each of the places that render it.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'escalationUrl' => $stored,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame('', $config->escalationUrl);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function safeUrls(): iterable
    {
        yield 'absolute path' => ['/contact', '/contact'];
        yield 'absolute path with query' => ['/support?topic=orders', '/support?topic=orders'];
        yield 'https url' => ['https://help.shop.test/', 'https://help.shop.test/'];
        yield 'http url' => ['http://help.shop.test/', 'http://help.shop.test/'];
        yield 'surrounding whitespace is trimmed' => ["  /contact\n", '/contact'];
    }

    #[DataProvider('safeUrls')]
    public function testASafeEscalationUrlSurvivesUnchanged(string $stored, string $expected): void
    {
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'escalationUrl' => $stored,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame($expected, $config->escalationUrl);
    }
}
