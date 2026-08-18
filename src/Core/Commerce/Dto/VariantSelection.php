<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * Carries the option VALUE and an optional group, because the model usually knows
 * "blue" and "M" but not always which group they belong to. Shape adopted from
 * SwagWebMcp's select_variant tool.
 */
final readonly class VariantSelection
{
    public function __construct(
        public string $option,
        public ?string $group = null,
    ) {}
}
