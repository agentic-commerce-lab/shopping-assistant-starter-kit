<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Controller\AssistantController;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;
use Swag\AssistantStarterKit\Tests\Core\Trace\InMemoryConversationStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

    protected const PREFIX = 'SwagAssistantStarterKit.config.';

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
    protected function controller(array $config = [
        self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
        self::PREFIX . 'llmModel' => 'anthropic/claude-sonnet-5',
        self::PREFIX . 'llmApiKey' => 'sk-test',
    ]): AssistantController
    {
        $this->runner = new RecordingTurnRunner();
        $this->store = new InMemoryConversationStore();

        return new AssistantController(
            $this->runner,
            $this->store,
            new SystemConfigLlmSettings(new FakeSystemConfigService($config)),
        );
    }

    protected function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::CHANNEL);
        $context->method('getLanguageId')->willReturn('2fbb5fe2e29a4d70aa5854ce7ce3e20b');

        return $context;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function post(array $body): Request
    {
        return Request::create('/assistant/chat', 'POST', content: json_encode($body) ?: '{}');
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
