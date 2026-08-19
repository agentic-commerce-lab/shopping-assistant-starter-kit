<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * Thrown by {@see Guard} when a tool argument violates a bound that
 * `#[AsTool]`'s reflection-derived schema cannot express. Reject, never
 * coerce — a coerced argument is a silent injection success.
 */
final class ToolArgumentException extends \InvalidArgumentException {}
