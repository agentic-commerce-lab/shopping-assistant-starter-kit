<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Stands in for an assertion a plugin ships alongside its own grounded tool. Deliberately outside
 * the plugin's own namespace: that is the whole property under test.
 */
final class ThirdPartyAssertion implements Assertion
{
    public function name(): string
    {
        return 'acme_fitment_declared';
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
