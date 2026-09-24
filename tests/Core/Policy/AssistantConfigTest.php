<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

final class AssistantConfigTest extends TestCase
{
    public function testCompareProductsIsOffByDefault(): void
    {
        self::assertFalse((new AssistantConfig())->enableCompareProducts);
    }

    /**
     * The copy the prompt is built from differs in one field and no other. Every other field is set
     * away from its default, so a copy that dropped one back to its default would show here.
     */
    public function testWithOrderHistoryChangesThatFieldAndNothingElse(): void
    {
        $config = new AssistantConfig(
            agentVoice: 'Be brief.',
            scope: new CatalogScope(['cat-1']),
            enableAddToCart: false,
            maxItemQuantity: 3,
            maxCartValue: 99.5,
            assistantEnabled: false,
            dailyRequestCap: 7,
            maxToolCallsPerTurn: 4,
            requestsPerMinute: 9,
            enableEscalation: false,
            escalationUrl: '/contact',
            escalationMessage: 'Call us.',
            enableMatchReasons: true,
            enableCompareProducts: true,
            suggestAlternatives: false,
            enableOrderHistory: true,
            onlyGivenInformation: true,
            logTraces: false,
            salesChannelId: 'sc-1',
            embeddingModel: 'embed',
            autoIndexShopPages: true,
            defaultReplyLanguage: 'German',
        );

        $narrowed = $config->withOrderHistory(false);

        self::assertFalse($narrowed->enableOrderHistory);
        self::assertEquals([...get_object_vars($config), 'enableOrderHistory' => false], get_object_vars($narrowed));
        self::assertTrue($config->enableOrderHistory, 'the original is left alone');
    }
}
