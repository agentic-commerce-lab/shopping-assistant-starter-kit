<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * Thrown by {@see ConversationStore::append()} when the presented scope does not match the scope a
 * conversation was opened under.
 *
 * **Not a shopper-facing condition.** The controller resolves and validates the shopper's scope
 * before it ever calls `append()`, so reaching this exception means that validation was skipped —
 * a developer error, not something a shopper did. It exists as a backstop: a silent no-op here would
 * drop a shopper's turn invisibly, which is worse than failing loudly.
 *
 * The message deliberately carries neither the token nor any customer, employee or organisation id —
 * this is a `\RuntimeException`, and anything identifying either party here would leak into a log or
 * a stack trace meant for developers, not for the shopper whose conversation was reached.
 */
final class ForeignConversationException extends \RuntimeException {}
