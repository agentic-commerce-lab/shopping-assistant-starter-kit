<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;

use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

/**
 * Everything one request's turn needs, built together so they share state:
 * the agent (wired to the SAME toolbox as the tools below, and the same
 * renderer/trace as the pipeline services inside those tools), the renderer
 * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner} reads the
 * rendered cards from afterwards, the trace it reads outcome signals from,
 * and the toolbox — carried here purely so a caller (chiefly tests) can
 * inspect which tools this request actually has available, since capability
 * control is toolbox construction and there is otherwise no way to observe
 * it from outside.
 *
 * `$vocabulary` is the already-rendered {@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary}
 * string, not the {@see \Swag\AssistantStarterKit\Core\Retrieval\FacetProbe} that produced
 * it: this is a readonly DTO of things one turn needs, and
 * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner} has no business probing the
 * catalog itself — it only needs the string to hand to
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt::build()}.
 */
final readonly class Bundle
{
    public function __construct(
        public AgentInterface $agent,
        public FactRenderer $renderer,
        public TraceRecorder $trace,
        public ToolboxInterface $toolbox,
        public string $vocabulary = '',
    ) {}
}
