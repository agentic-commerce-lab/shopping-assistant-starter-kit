<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Sink;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Receives one finished turn's trace. Tag the implementation `swag_assistant.trace_sink`.
 *
 * **Runs synchronously, inside the shopper's request, after the trace has been persisted.** Not
 * through Messenger, and that is a decision rather than an omission: Shopware installs differ in how
 * transports are configured, an async sink that silently never runs is worse than a sync one that
 * does, and a starter kit must not require a worker process. The trace is already written to the
 * database in this same request, so a sink adds no new *class* of cost — only more of it.
 *
 * The consequence, said plainly: **a slow sink makes the shopper wait.** Keep `send()` fast, or make
 * it enqueue rather than call.
 *
 * A throwing sink is caught and recorded by {@see TraceSinkDispatcher}. The turn has already
 * succeeded and the audit row is already written by the time you are called; a broken integration
 * must not turn a delivered answer into a 500.
 *
 * @api
 */
interface TraceSinkInterface
{
    public function send(#[\SensitiveParameter] string $token, string $salesChannelId, TraceRecorder $trace): void;
}
