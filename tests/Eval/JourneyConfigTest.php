<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Eval\Journey;
use Swag\AssistantStarterKit\Eval\JourneyConfig;

/**
 * A journey's `config` block is the only way a journey can say what kind of shop it runs against, and
 * until this class existed exactly one key of it worked: `buildConfig()` read `blockedProductIds` and
 * dropped everything else on the floor. `Journey::$config`'s own docblock said it "maps onto
 * AssistantConfig", which was true for one thirteenth of it.
 *
 * A silently ignored key is the worst outcome available here: the journey passes, and it passes
 * against a shop configured differently from the one it claims to describe.
 */
final class JourneyConfigTest extends TestCase
{
    public function testBlockedProductIdsStillReachTheCatalogScope(): void
    {
        $config = JourneyConfig::of($this->journey(['blockedProductIds' => ['fx-014']]));

        self::assertSame(['fx-014'], $config->scope->blockedProductIds);
    }

    public function testTheEscalationSettingsReachTheConfig(): void
    {
        $config = JourneyConfig::of($this->journey([
            'enableEscalation' => true,
            'escalationUrl' => '/contact',
        ]));

        self::assertTrue($config->enableEscalation);
        self::assertSame('/contact', $config->escalationUrl);
    }

    public function testEscalationCanBeSwitchedOffByAJourney(): void
    {
        $config = JourneyConfig::of($this->journey(['enableEscalation' => false]));

        self::assertFalse($config->enableEscalation);
    }

    public function testAnEmptyConfigYieldsTheDocumentedDefaults(): void
    {
        $config = JourneyConfig::of($this->journey([]));

        self::assertTrue($config->enableEscalation);
        self::assertSame('', $config->escalationUrl);
        self::assertSame([], $config->scope->blockedProductIds);
    }

    public function testAnUnknownKeyIsRefusedRatherThanIgnored(): void
    {
        // The failure this prevents: a journey with `escalationUrI` (capital i) that configures
        // nothing, asserts a handoff, and reports the assistant as broken. A journey is a claim about
        // a shop; a key nothing reads makes the claim quietly false.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('escalationUrI');

        JourneyConfig::of($this->journey(['escalationUrI' => '/contact']));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function journey(array $config): Journey
    {
        return new Journey(
            id: 'test_journey',
            category: 'safety',
            runs: 1,
            archetypes: ['expert' => 'a phrase'],
            config: $config,
            turns: ['archetype'],
            assertions: [],
        );
    }
}
