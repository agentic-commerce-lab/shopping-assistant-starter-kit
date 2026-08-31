<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/** An assertion that cannot be constructed by name. The registry must say so, not fatal. */
final class ConstructorArgAssertion implements Assertion
{
    public function __construct(
        private readonly string $required,
    ) {}

    public function name(): string
    {
        return $this->required;
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        return new AssertionResult($this->name(), true, 'stub');
    }

    public function isSafety(): bool
    {
        return false;
    }
}
