<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * How a stored boolean is interpreted, which is not the same question as whether it is stored.
 *
 * `bin/console system:config:set` writes **every** value as a string, so a guardrail turned off from
 * the CLI arrives as the string `"false"` — and `(bool) "false"` is `true` in PHP. Measured in the
 * running shop: `system_config` held `{"_value":"false"}` for the off switch while the assistant
 * read it as ON. The admin UI sends real JSON booleans and is unaffected, which is exactly why this
 * stayed invisible — and why `docs/HANDOFF.md`'s CLI round-trip evidence proved storage rather than
 * interpretation.
 *
 * Two directions matter. `enableAddToCart`'s help text promises the tool "is never constructed" when
 * off, so under a plain cast a merchant disabling it from the CLI would get the tool constructed
 * anyway — a guardrail failing **open** while the form shows it disabled. `assistantEnabled` is the
 * same trap pointed at the product itself: a cast would read a CLI-stored `"false"` as *enabled* and
 * quietly restart an assistant somebody deliberately stopped.
 */
final class SystemConfigBooleanReadingTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testABooleanStoredAsTheStringFalseIsNotReadAsTrue(): void
    {
        self::assertFalse($this->config(['assistantEnabled' => 'false'])->assistantEnabled);
    }

    public function testAGuardrailDisabledFromTheCliDoesNotFailOpen(): void
    {
        self::assertFalse($this->config(['enableAddToCart' => 'false'])->enableAddToCart);
    }

    public function testTheStringZeroIsAlsoFalse(): void
    {
        self::assertFalse($this->config(['enableAddToCart' => '0'])->enableAddToCart);
    }

    public function testABooleanStoredAsTheStringTrueStillReadsAsTrue(): void
    {
        self::assertTrue($this->config(['assistantEnabled' => 'true'])->assistantEnabled);
    }

    public function testARealBooleanIsStillHonoured(): void
    {
        // The admin UI's path must keep working: a stored `false` is a decision, not an absence.
        self::assertFalse($this->config(['enableAddToCart' => false])->enableAddToCart);
    }

    public function testAnAbsentKeyStillFallsBackToTheDocumentedDefault(): void
    {
        // Both default on. Neither is stored here.
        $config = $this->config([]);

        self::assertTrue($config->enableAddToCart);
        self::assertTrue($config->assistantEnabled);
    }

    /**
     * @param array<string, string|int|float|bool|null> $values
     */
    private function config(array $values): \Swag\AssistantStarterKit\Core\Policy\AssistantConfig
    {
        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed[self::PREFIX . $key] = $value;
        }

        return (new SystemConfigAssistantConfig(
            new FakeSystemConfigService($prefixed),
        ))->forSalesChannel(self::CHANNEL);
    }
}
