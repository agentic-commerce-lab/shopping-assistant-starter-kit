<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;
use Swag\AssistantStarterKit\Core\Tool\Factory\SearchShopInfoToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;

/**
 * Contributes the real {@see SearchShopInfoTool} to a journey, over a {@see ShopInfoFixture}'s store.
 *
 * The tool itself is the shipped one — its threshold, its reply shape, its notes and its trace event
 * are all the production code. Only where the passages came from differs, which is the same bargain
 * {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway} already strikes for the
 * catalogue.
 *
 * Separate from {@see SearchShopInfoToolFactory} because that one resolves an embedder from Shopware's
 * system config, and the eval harness deliberately has no Shopware. The R13 null rule is repeated
 * here rather than inherited, so a journey that forgets to configure a model gets no tool instead of a
 * broken one.
 */
final readonly class ShopInfoFixtureToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private ShopInfoFixture $fixture,
    ) {}

    public function create(ToolContext $context): ?object
    {
        if ($context->config->embeddingModel === '') {
            return null;
        }

        return new SearchShopInfoTool(
            $this->fixture->embedder,
            $this->fixture->store,
            $context->trace,
            $context->config,
        );
    }
}
