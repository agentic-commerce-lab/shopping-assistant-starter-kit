<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * The outcome of one {@see Assertion::evaluate()} call against one run of one journey.
 */
final readonly class AssertionResult
{
    public function __construct(
        public string $name,
        public bool $passed,
        public string $detail,
    ) {}
}
