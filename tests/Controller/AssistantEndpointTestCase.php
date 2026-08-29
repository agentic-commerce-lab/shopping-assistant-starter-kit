<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Controller\AssistantController;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingContextResolver;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Swag\AssistantStarterKit\Core\Policy\RequestBudget;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;
use Swag\AssistantStarterKit\Tests\Core\Trace\InMemoryConversationStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Shared fixture for the endpoint tests.
 *
 * A base class rather than the same six helpers copied into three files: jscpd is part of the gate,
 * and more to the point the environment guard below is a correctness requirement rather than
 * convenience — without it a developer `.env` makes "unconfigured shop" untestable, because
 * `SystemConfigLlmSettings` deliberately prefers the environment (ruling R77).
 */
abstract class AssistantEndpointTestCase extends TestCase
{
    protected const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    protected const BLUE_M_ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    protected const CUSTOMER = '01a01b4f9e2270a1b2c3d4e5f6a7b8c9';

    protected const PREFIX = 'SwagAssistantStarterKit.config.';

    /**
     * A shop with a model configured and every other setting left at its default.
     *
     * @var array<string, string|int|float|bool|null>
     */
    protected const CONFIGURED = [
        self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
        self::PREFIX . 'llmModel' => 'anthropic/claude-sonnet-5',
        self::PREFIX . 'llmApiKey' => 'sk-test',
    ];

    /** @var list<string> */
    private const ENV_NAMES = ['ASSISTANT_LLM_BASE_URL', 'ASSISTANT_LLM_MODEL', 'ASSISTANT_LLM_API_KEY'];

    protected RecordingTurnRunner $runner;

    protected InMemoryConversationStore $store;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->runner = new RecordingTurnRunner();
        $this->store = new InMemoryConversationStore();
    }

    protected function setUp(): void
    {
        // SystemConfigLlmSettings prefers the environment over stored config (ruling R77), so a
        // developer .env would make "unconfigured shop" impossible to test — the suite would read
        // the machine it runs on and pass for the wrong reason.
        foreach (self::ENV_NAMES as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if (\is_string($value)) {
                putenv(\sprintf('%s=%s', $name, $value));
            }
        }
    }

    /**
     * @param array<string, string|int|float|bool|null> $config
     */
    protected function controller(array $config = self::CONFIGURED): AssistantController
    {
        $this->runner = new RecordingTurnRunner();
        $this->store = new InMemoryConversationStore();

        $systemConfig = new FakeSystemConfigService($config);

        return new AssistantController(
            $this->runner,
            $this->store,
            new SystemConfigLlmSettings($systemConfig),
            new SystemConfigAssistantConfig($systemConfig),
            // In-memory rather than a cache pool: one budget per controller, so a test's windows
            // start empty and cannot leak into the next test.
            new RequestBudget(new InMemoryStorage()),
            // The controller calls `ShoppingContextResolver::of()` with the `SalesChannelContext` it
            // already holds, never `current()` — so the provider this constructor still requires is
            // never actually read here, and a request-less one is enough to satisfy the type.
            new ShoppingContextResolver(new SalesChannelContextProvider(new RequestStack())),
        );
    }

    /**
     * A configured shop with some settings overridden.
     *
     * @param array<string, string|int|float|bool|null> $overrides
     *
     * @return array<string, string|int|float|bool|null>
     */
    protected function configuredWith(array $overrides): array
    {
        return array_merge(self::CONFIGURED, $overrides);
    }

    protected function context(?string $customerId = null): SalesChannelContext
    {
        $customer = null;

        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::CHANNEL);
        $context->method('getLanguageId')->willReturn('2fbb5fe2e29a4d70aa5854ce7ce3e20b');
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }

    /**
     * The scope {@see self::context(null)} resolves to: a guest on {@see self::CHANNEL}. A test that
     * writes directly to `$this->store` (bypassing the controller) needs this to read back what it
     * wrote through `$this->context()`, since the store now refuses a mismatched scope.
     */
    protected function guestScope(): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);
    }

    /**
     * @param array<string, mixed> $body
     * @param string               $ip   the caller the per-client window is counted against
     */
    protected function post(array $body, string $ip = '127.0.0.1'): Request
    {
        return Request::create(
            '/assistant/chat',
            'POST',
            server: ['REMOTE_ADDR' => $ip],
            content: json_encode($body) ?: '{}',
        );
    }

    /**
     * @param array<string, string> $query
     */
    protected function get(array $query): Request
    {
        return Request::create('/assistant/history', 'GET', $query);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), associative: true);
        self::assertIsArray($decoded);

        $payload = [];
        foreach ($decoded as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
