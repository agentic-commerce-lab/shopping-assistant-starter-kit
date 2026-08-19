<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The split of a model's returned product ids into what the turn's retrieval
 * actually backs ({@see self::$accepted}) and what it does not
 * ({@see self::$invented}) — ids the model produced without ever having been
 * handed the record, whether by hallucination or injected instruction.
 */
final readonly class ValidationResult
{
    /**
     * @param list<string> $accepted
     * @param list<string> $invented
     */
    public function __construct(
        public array $accepted,
        public array $invented,
    ) {}
}
