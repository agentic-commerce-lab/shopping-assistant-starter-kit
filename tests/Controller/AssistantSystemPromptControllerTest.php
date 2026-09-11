<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\AssistantSystemPromptController;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPromptProvider;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;

/**
 * Showing the merchant the instructions their own text is added to.
 *
 * **Composed server-side rather than copied into a template**, and that is the whole design
 * decision. `swag-assistant-escalation-preview` is deliberately static, for a good reason stated in
 * its own docblock — it illustrates *behaviour*, and reading live form state would mean reaching
 * into `sw-system-config`'s internals. A prompt preview cannot take that route: the prompt is
 * assembled from `enableEscalation`, `onlyGivenInformation`, `enableMatchReasons`,
 * `enableCompareProducts` and the reply language, so a hardcoded copy would drift from
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt} at its next edit. A preview that lies is
 * worse than none, because it is the thing a merchant would reach for to check what they changed.
 *
 * It answers for the **saved** configuration. Unsaved form fields are not reflected, which the
 * component says in as many words rather than pretending otherwise.
 *
 * Read-only by construction: there is no write route here, and the merchant's control stays the one
 * appended slot. This endpoint exists so that slot can be understood, not widened.
 */
final class AssistantSystemPromptControllerTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    /**
     * @param array<string, bool|float|int|list<mixed>|string|null> $stored
     *
     * @return array<array-key, mixed>
     */
    private function fetch(array $stored = []): array
    {
        $controller = new AssistantSystemPromptController(
            new SystemPromptProvider(),
            new SystemConfigAssistantConfig(new FakeSystemConfigService($stored)),
        );

        $decoded = json_decode(
            (string) $controller->systemPrompt(self::CHANNEL)->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testItReturnsTheComposedPromptForThatSalesChannel(): void
    {
        $body = $this->fetch();

        self::assertIsString($body['prompt'] ?? null);
        self::assertStringContainsString('You are a shopping assistant for this shop only.', (string) $body['prompt']);
    }

    public function testTheMerchantsOwnInstructionsAppearInIt(): void
    {
        // The point of the preview: the merchant sees their sentence in position, after the rules,
        // rather than having to trust that it landed somewhere.
        $body = $this->fetch([self::PREFIX . 'agentVoice' => 'Always mention our 30-day returns.']);

        self::assertStringContainsString('Always mention our 30-day returns.', (string) $body['prompt']);
        self::assertStringContainsString('the rules win', (string) $body['prompt']);
    }

    public function testASettingThatChangesThePromptChangesThePreview(): void
    {
        // Why this is composed rather than copied. `onlyGivenInformation` adds a whole rule block,
        // so a static preview would show a prompt this shop does not use.
        $off = $this->fetch();
        $on = $this->fetch([self::PREFIX . 'onlyGivenInformation' => true]);

        self::assertNotSame($off['prompt'], $on['prompt']);
    }

    public function testItReportsTheLengthSoTheCardNeedNotMeasureIt(): void
    {
        $body = $this->fetch();

        self::assertSame(mb_strlen((string) $body['prompt']), $body['characters'] ?? null);
    }
}
