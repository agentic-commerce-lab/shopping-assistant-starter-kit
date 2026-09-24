<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory\Bundle;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\OrderRules;
use Swag\AssistantStarterKit\Tests\Core\Commerce\LoopOnlyGateway;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Order status is escalated when there are no order tools, and answered when there are.
 *
 * Found on staging 2026-09-24 with order history on and a shopper signed in: "what's the status of my
 * latest 12 orders" called `list_orders` and then `escalate`, because the escalate tool's own
 * description said "use for order status" to every model, whatever else it had been given.
 *
 * "Available" is decided by the toolbox — the three gates of `ListOrdersToolFactory` — and not by the
 * merchant's switch alone, because a guest on a shop with order history enabled has no order tool and
 * must keep the escalation it always had. `order_status_escalates` pins that branch against a live
 * model; this pins the wiring beneath it without one.
 */
final class OrderToolsChangeWhatIsEscalatedTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    /** Verbatim from before this change — the branch without order tools must not drift by a word. */
    private const UNCHANGED_DESCRIPTION =
        'Hand the conversation to a human. Use for order status, returns, account '
            . 'questions, complaints, or anything you cannot answer from shop data.';

    public function testAGuestKeepsTheEscalationItAlwaysHad(): void
    {
        $bundle = self::bundle(new AssistantConfig(enableOrderHistory: true), loggedIn: false);

        self::assertSame(self::UNCHANGED_DESCRIPTION, self::escalateDescription($bundle));
    }

    public function testAGatewayThatCannotReadOrdersKeepsItToo(): void
    {
        $gateway = new LoopOnlyGateway(FixtureCommerceGateway::fromFile(self::catalogFixturePath()));
        $bundle = self::bundle(new AssistantConfig(enableOrderHistory: true), loggedIn: true, gateway: $gateway);

        self::assertSame(self::UNCHANGED_DESCRIPTION, self::escalateDescription($bundle));
    }

    public function testWithOrderToolsOrderStatusLeavesTheEscalationList(): void
    {
        $description = self::escalateDescription(self::bundle(
            new AssistantConfig(enableOrderHistory: true),
            loggedIn: true,
        ));

        self::assertStringNotContainsString('Use for order status', $description);
        self::assertStringContainsString('returns', $description);
        self::assertStringContainsString('order tools', $description);
    }

    /**
     * The prompt the turn actually ran with, read off the trace — so this covers the runner passing the
     * toolbox's answer to the provider, not just `SystemPrompt::build()` in isolation.
     */
    public function testThePromptCarriesTheOrderRulesOnlyWhenTheToolsExist(): void
    {
        self::assertStringContainsString(OrderRules::OWN_ORDER_FIGURES, self::promptOfATurn(loggedIn: true));
        self::assertStringNotContainsString(OrderRules::OWN_ORDER_FIGURES, self::promptOfATurn(loggedIn: false));
    }

    private static function promptOfATurn(bool $loggedIn): string
    {
        $config = new AssistantConfig(enableOrderHistory: true);
        $bundle = self::bundle($config, $loggedIn);

        (new AssistantRunner($config, $bundle))->run('what is the status of my orders?', new MessageBag());

        $payload = $bundle->trace->payload('prompt') ?? [];

        if (!\array_key_exists('text', $payload) || !\is_string($payload['text'])) {
            self::fail('the turn recorded no prompt');
        }

        return $payload['text'];
    }

    private static function escalateDescription(Bundle $bundle): string
    {
        foreach ($bundle->toolbox->getTools() as $tool) {
            if ($tool->getName() === 'escalate') {
                return $tool->getDescription();
            }
        }

        self::fail('the toolbox carries no escalate tool');
    }

    private static function bundle(
        AssistantConfig $config,
        bool $loggedIn,
        ?CommerceGatewayInterface $gateway = null,
    ): Bundle {
        $http = new MockHttpClient(static fn(): MockResponse => self::textResponse('Here is what I found.'));

        return AssistantAgentFactory::withCoreToolsOnly($http)->create(
            $gateway ?? FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            cartAvailable: false,
            llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
            loggedIn: $loggedIn,
        );
    }
}
