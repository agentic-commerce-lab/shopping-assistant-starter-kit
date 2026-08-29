<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Storefront;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Config\SystemConfigWidgetSettings;
use Swag\AssistantStarterKit\Core\Context\ContextStorageKey;
use Swag\AssistantStarterKit\Core\Context\ShoppingContextResolver;
use Swag\AssistantStarterKit\Storefront\AssistantWidgetExtension;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TwigFilter;

/**
 * `swag_assistant_context_key()`: what it returns, and that the panel template actually renders it.
 *
 * A `SalesChannelContext` is mocked here rather than constructed for real — same reasoning as
 * {@see \Swag\AssistantStarterKit\Tests\Core\Context\ShoppingContextResolverTest}: its constructor
 * takes a dozen collaborators none of these cases care about.
 *
 * @see AssistantWidgetExtension::contextKey()
 */
final class AssistantWidgetContextKeyTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testTheKeyIsThirtyTwoLowercaseHexCharacters(): void
    {
        $key = $this->extensionFor($this->requestScopedProvider())->contextKey();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
    }

    public function testTheSameShopperGetsTheSameKeyTwice(): void
    {
        $provider = $this->requestScopedProvider();
        $extension = $this->extensionFor($provider);

        self::assertSame($extension->contextKey(), $extension->contextKey());
    }

    public function testADifferentShopperGetsADifferentKey(): void
    {
        $alice = $this->extensionFor($this->requestScopedProvider('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'));
        $bob = $this->extensionFor($this->requestScopedProvider('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2'));

        self::assertNotSame($alice->contextKey(), $bob->contextKey());
    }

    public function testItDegradesToAnEmptyStringRatherThanThrowing(): void
    {
        // No request at all — the state `ShoppingContextResolver::current()` throws on. Every other
        // function on this class is total, and a Twig function that throws replaces the merchant's
        // whole storefront page with an error, not just the widget.
        $provider = new SalesChannelContextProvider(new RequestStack());

        self::assertSame('', $this->extensionFor($provider)->contextKey());
    }

    public function testThePanelTemplateRendersTheAttribute(): void
    {
        // The real extension, not a double: `swag_assistant_context_key` is one of six functions on
        // `AssistantWidgetExtension`, and Twig's compiler resolves a bound method on an
        // `ExtensionInterface` object by looking that object up as *the* registered extension of its
        // class — so a stub function bound to a real `AssistantWidgetExtension` instance would fail
        // to resolve unless that same instance is also the one registered here.
        $extension = $this->extensionFor($this->requestScopedProvider());

        $loader = new FilesystemLoader(
            \dirname(__DIR__, levels: 2) . '/src/Resources/views/storefront/component/assistant',
        );
        $twig = new Environment($loader);
        $twig->addExtension($extension);
        $twig->addExtension($this->missingCallablesStub());

        $html = $twig->render('panel.html.twig', ['context' => ['salesChannelId' => self::CHANNEL]]);

        self::assertMatchesRegularExpression('/data-swag-assistant-context-key="[0-9a-f]{32}"/', $html);
    }

    /**
     * A provider that resolves {@see self::CHANNEL} (and `$customerId`, if given) for as long as it
     * lives — unlike `SalesChannelContextProvider::use()`'s scoped override, which restores `null`
     * the moment its callback returns and so cannot outlive a single call. A pushed `Request` carrying
     * the attribute Shopware itself sets on every `frontend.*` route is what `current()` actually
     * reads, and it persists on the stack exactly the way a real storefront request would.
     */
    private function requestScopedProvider(?string $customerId = null): SalesChannelContextProvider
    {
        $customer = null;
        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn($customer);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::CHANNEL);

        $request = Request::create('/');
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new SalesChannelContextProvider($requestStack);
    }

    private function extensionFor(SalesChannelContextProvider $provider): AssistantWidgetExtension
    {
        $config = new FakeSystemConfigService([]);

        return new AssistantWidgetExtension(
            new SystemConfigLlmSettings($config),
            new SystemConfigAssistantConfig($config),
            new SystemConfigWidgetSettings($config),
            new ShoppingContextResolver($provider),
            new ContextStorageKey('test-kernel-secret'),
        );
    }

    /**
     * The two Twig callables `panel.html.twig` needs that `AssistantWidgetExtension` does not
     * provide: the `trans` filter, and a parser for the one custom tag the file contains.
     *
     * `SystemConfigWidgetSettings::theme()` defaults `entryPointStyle` to `icon` on an unconfigured
     * shop, so the `creature` branch — the only branch that reaches `sw_include` at *runtime* — never
     * executes here. Twig still has to parse the whole file to build that branch's AST, though, so
     * the tag needs a parser regardless of which branch runs; `sw_include`'s real implementation
     * resolves a bundle override chain this test has no bundle for, so the stub only has to produce
     * something parseable, never something that renders.
     */
    private function missingCallablesStub(): AbstractExtension
    {
        return new class extends AbstractExtension {
            public function getFilters(): array
            {
                // The real `trans` filter needs a translator this test has no use for; the panel
                // only ever passes it a snippet key, and returning that key unchanged is enough to
                // prove the surrounding markup — including the attribute under test — renders.
                return [new TwigFilter('trans', static fn(string $id): string => $id)];
            }

            public function getTokenParsers(): array
            {
                return [
                    new class extends AbstractTokenParser {
                        // `$token` is a `Twig\Token` — a lexer token from the template source, not
                        // a conversation token — but mago's `sensitive-parameter` rule matches the
                        // name, not the type. The attribute costs nothing here and keeps the rule
                        // meaningful where it actually matters, in AssistantController.
                        public function parse(#[\SensitiveParameter] Token $token): Node
                        {
                            // Discard the template-name expression, then the closing `%}` the way
                            // every other tag parser does — the never-executed node below is all the
                            // unreachable `creature` branch needs to compile cleanly.
                            $this->parser->parseExpression();
                            $this->parser->getStream()->expect(Token::BLOCK_END_TYPE);

                            return new class([], [], $token->getLine()) extends Node {};
                        }

                        public function getTag(): string
                        {
                            return 'sw_include';
                        }

                        public function isAlwaysAllowedInSandbox(): bool
                        {
                            return true;
                        }
                    },
                ];
            }
        };
    }
}
