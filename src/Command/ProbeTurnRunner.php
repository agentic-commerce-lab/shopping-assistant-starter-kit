<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentExceptionInterface;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Runs one complete turn against a live model and hands back the turn plus its trace.
 *
 * This is the piece that closes handoff known-issue 8 — *"no trace has ever been read end to
 * end"* — because {@see TraceDumper} needs something to dump. Before this, every statement about
 * model behaviour on this branch was inferred from which assertion had failed.
 *
 * **Settings come from the environment, not from merchant config, and that is deliberate.** This is
 * a developer tool: it must work before `config.xml` has ever been filled in, and it reads exactly
 * the three variables the eval suite reads so one `.env` configures both. All three are required
 * (ruling R43: checking only the base URL once meant a run with two of three set attempted a real
 * network call).
 *
 * @return array{turn: AssistantTurn, trace: string}
 */
final readonly class ProbeTurnRunner
{
    public const REQUIRED_ENV = ['ASSISTANT_LLM_BASE_URL', 'ASSISTANT_LLM_API_KEY', 'ASSISTANT_LLM_MODEL'];

    public function __construct(
        private CommerceGatewayInterface $gateway,
        private TraceDumper $dumper = new TraceDumper(),
    ) {}

    /**
     * @return list<string> the names of any required variables that are not set
     */
    public function missingEnvironment(): array
    {
        $missing = [];

        foreach (self::REQUIRED_ENV as $name) {
            $value = getenv($name);

            if (!\is_string($value) || trim($value) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @return array{turn: AssistantTurn, trace: string}
     */
    public function ask(string $message, AssistantConfig $config): array
    {
        $missing = $this->missingEnvironment();

        if ($missing !== []) {
            throw new LlmException(\sprintf('Not configured: %s.', implode(', ', $missing)));
        }

        $bundle = AssistantAgentFactory::withCoreToolsOnly()->create(
            $this->gateway,
            $config,
            // The cart exists: this runs inside a Shopware kernel with a real sales-channel
            // context, so add_to_cart is constructed and the model can see it. Passing false
            // would silently make the probe exercise a smaller toolbox than the widget does.
            cartAvailable: true,
            llm: $this->settings(),
        );

        try {
            $turn = (new AssistantRunner($config, $bundle))->run($message, new MessageBag());
        } catch (AgentExceptionInterface $failure) {
            // Degrade, never abort — and above all KEEP THE TRACE. Three separate pilot blockers on
            // this branch were foreseeable conditions ending the turn instead of degrading (rulings
            // R48, R49, R52), and each was found by a live run rather than by the suite. In a probe
            // the trace up to the failure is the most valuable thing in the room, so losing it to
            // an uncaught exception would defeat the command's purpose. The message is surfaced
            // rather than swallowed: this is a developer tool.
            $turn = new AssistantTurn(\sprintf('The turn failed: %s', $failure->getMessage()), [], 'error');
        }

        return ['turn' => $turn, 'trace' => $this->dumper->dump($bundle->trace)];
    }

    private function settings(): LlmSettings
    {
        $model = $this->env('ASSISTANT_LLM_MODEL');

        if ($model === '') {
            throw new LlmException('ASSISTANT_LLM_MODEL is empty.');
        }

        return new LlmSettings(
            baseUrl: $this->env('ASSISTANT_LLM_BASE_URL'),
            apiKey: $this->env('ASSISTANT_LLM_API_KEY'),
            model: $model,
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);

        return \is_string($value) ? trim($value) : '';
    }
}
