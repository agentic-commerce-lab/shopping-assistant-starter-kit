<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * Its own file rather than more methods on {@see SystemConfigAssistantConfigTest} — mago's
 * too-many-methods threshold is per class, and every other setting-group here already has one.
 *
 * The storefront's locale is a **request** fact, not a stored setting: one sales channel can serve
 * several domains in different languages, so the channel row cannot answer "what language is this
 * page in" and the domain can. It arrives as an argument for that reason, and it is the only value
 * on `AssistantConfig` that does not come out of `system_config`.
 */
final class SystemConfigAssistantConfigLanguageTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private function config(): SystemConfigAssistantConfig
    {
        return new SystemConfigAssistantConfig(new FakeSystemConfigService([]));
    }

    public function testTheStorefrontLocaleBecomesTheFallbackLanguage(): void
    {
        self::assertSame('German', $this->config()->forSalesChannel(self::CHANNEL, 'de-DE')->defaultReplyLanguage);
    }

    /**
     * A console run, an API caller, or any request the storefront's `RequestTransformer` never
     * touched has no domain locale. English rather than empty, so nothing downstream has to know
     * what the absent case means.
     */
    public function testNoStorefrontLocaleFallsBackToEnglish(): void
    {
        self::assertSame('English', $this->config()->forSalesChannel(self::CHANNEL)->defaultReplyLanguage);
    }

    /**
     * The closed list is enforced here too, not only in the prompt: a locale naming a language this
     * project has not chosen resolves to the fallback rather than travelling as itself.
     */
    public function testAnUnknownLocaleResolvesToTheFallbackRatherThanTravellingAsItself(): void
    {
        self::assertSame('English', $this->config()->forSalesChannel(self::CHANNEL, 'fr-FR')->defaultReplyLanguage);
    }
}
