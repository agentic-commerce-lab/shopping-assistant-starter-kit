<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

/**
 * Thrown when `--sales-channel` was omitted and the shop does not answer the question on its own.
 *
 * A distinct type rather than `\RuntimeException` so a command can catch exactly this and print it
 * as guidance instead of a stack trace: "you have two storefronts, say which" is a usage error, not
 * a failure.
 */
final class NoDefaultSalesChannelException extends \RuntimeException {}
